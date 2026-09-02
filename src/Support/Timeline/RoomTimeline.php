<?php

namespace Goldnead\ClientRooms\Support\Timeline;

use Carbon\Carbon;
use Goldnead\ClientRooms\Models\ClientRoom;
use Goldnead\ClientRooms\Support\Contacts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * What happened with this client, in one list.
 *
 * Two ways to answer, and the room does not care which. With LeadHub
 * installed and the room linked to a contact, LeadHub's own merged timeline
 * is used as it is — the same class its contact screen renders, resolved
 * through the container, never reimplemented here. Without it, a short list
 * is read straight from the payments and bookings tables for the room's
 * address, when those tables exist. Nothing here writes, and nothing here
 * throws: a sibling mid-upgrade costs a panel, not the room.
 *
 * @phpstan-type Built array{
 *     mode: 'leadhub'|'fallback',
 *     entries: list<array<string, mixed>>,
 *     sources: array<string, bool>,
 *     failed: list<string>,
 *     stats: array<string, mixed>,
 *     total: int,
 * }
 */
class RoomTimeline
{
    /** @return Built */
    public function build(ClientRoom $room, ?int $limit = null): array
    {
        $limit ??= max(1, (int) config('statamic-clientrooms.timeline_limit', 100));

        $fromLeadhub = $this->fromLeadhub($room, $limit);

        return $fromLeadhub ?? $this->fallback($room, $limit);
    }

    /**
     * Whether LeadHub's timeline can answer for this room at all.
     */
    public function leadhubAvailable(): bool
    {
        return class_exists('\Goldnead\Leadhub\Support\Timeline\ContactTimeline')
            && Contacts::available();
    }

    /** @return Built|null */
    protected function fromLeadhub(ClientRoom $room, int $limit): ?array
    {
        if (! $this->leadhubAvailable()) {
            return null;
        }

        // A room opened before LeadHub was installed, or before the contact
        // existed, has no link yet. Looked up once by address and brand, and
        // kept, so the next render does not ask again.
        if ($room->contact_id === null) {
            $contactId = Contacts::idFor($room->email, (int) $room->brand_id);

            if ($contactId === null) {
                return null;
            }

            $room->forceFill(['contact_id' => $contactId])->saveQuietly();
        }

        try {
            $contactModel = Contacts::MODEL;
            $contact = $contactModel::query()->withoutGlobalScopes()->find($room->contact_id);

            if ($contact === null) {
                return null;
            }

            $built = app('\Goldnead\Leadhub\Support\Timeline\ContactTimeline')->build($contact, $limit);

            return [
                'mode' => 'leadhub',
                'entries' => $built['entries'] ?? [],
                'sources' => ['leadhub' => true] + ($built['sources'] ?? []),
                'failed' => $built['failed'] ?? [],
                'stats' => $built['stats'] ?? [],
                'total' => (int) ($built['total'] ?? count($built['entries'] ?? [])),
            ];
        } catch (Throwable $e) {
            Log::warning('statamic-clientrooms: LeadHub could not build the timeline; falling back to the room\'s own list.', [
                'room' => $room->id,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Payments and bookings for the address, newest first.
     *
     * Deliberately small: one line per paid payment, one per booking. It is
     * what a coach needs to see at a glance — "when did they buy, when were
     * they here" — and everything richer is LeadHub's job.
     *
     * @return Built
     */
    protected function fallback(ClientRoom $room, int $limit): array
    {
        $email = mb_strtolower(trim($room->email));
        $entries = [];
        $sources = [];
        $failed = [];

        foreach (['payments' => 'paymentEntries', 'booking' => 'bookingEntries'] as $key => $method) {
            try {
                $own = $this->{$method}($email);
            } catch (Throwable $e) {
                Log::warning("statamic-clientrooms: the timeline source [{$key}] failed and was left out.", ['exception' => $e->getMessage()]);
                $failed[] = $key;
                $sources[$key] = false;

                continue;
            }

            if ($own === null) {
                $sources[$key] = false;

                continue;
            }

            $sources[$key] = true;
            $entries = array_merge($entries, $own);
        }

        usort($entries, fn (array $a, array $b): int => strcmp((string) $b['at'], (string) $a['at']) ?: strcmp((string) $b['id'], (string) $a['id']));

        $total = count($entries);
        $times = array_values(array_filter(array_map(fn (array $e) => $e['at'], $entries)));

        return [
            'mode' => 'fallback',
            'entries' => array_slice($entries, 0, $limit),
            'sources' => $sources,
            'failed' => $failed,
            'stats' => [
                'first_contact_at' => $times !== [] ? min($times) : null,
                'last_contact_at' => $times !== [] ? max($times) : null,
                'purchase_count' => count(array_filter($entries, fn (array $e) => $e['source'] === 'payments')),
            ],
            'total' => $total,
        ];
    }

    /** @return list<array<string, mixed>>|null */
    protected function paymentEntries(string $email): ?array
    {
        if (! class_exists('\Goldnead\StatamicPayments\Models\Payment') || ! Schema::hasTable('payments')) {
            return null;
        }

        $rows = DB::table('payments')
            ->select(['id', 'product', 'amount_cent', 'currency', 'status', 'paid_at', 'created_at'])
            ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
            ->where('status', 'paid')
            ->orderByDesc('paid_at')
            ->limit(200)
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $at = $row->paid_at ?? $row->created_at;
            $cent = (int) $row->amount_cent;
            $currency = strtoupper((string) ($row->currency ?: 'EUR'));

            $out[] = $this->entry(
                id: 'payments:'.$row->id,
                source: 'payments',
                kind: 'payment.paid',
                at: $at !== null ? (string) $at : null,
                summary: __('statamic-clientrooms::messages.timeline_paid', ['product' => (string) $row->product]),
                badge: ['text' => __('statamic-clientrooms::messages.timeline_badge_paid'), 'color' => 'green'],
                amount: ['cent' => $cent, 'currency' => $currency, 'formatted' => number_format($cent / 100, 2, ',', '.').' '.$currency],
            );
        }

        return $out;
    }

    /** @return list<array<string, mixed>>|null */
    protected function bookingEntries(string $email): ?array
    {
        if (! class_exists('\Goldnead\StatamicBooking\Models\Booking') || ! Schema::hasTable('bookings')) {
            return null;
        }

        $rows = DB::table('bookings')
            ->select(['id', 'endpoint', 'status', 'scheduled_at', 'cancelled_at', 'duration_minutes'])
            ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
            ->orderByDesc('scheduled_at')
            ->limit(200)
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $cancelled = $row->cancelled_at !== null;

            $out[] = $this->entry(
                id: 'booking:'.$row->id,
                source: 'booking',
                kind: 'booking.'.(string) $row->status,
                at: $row->scheduled_at !== null ? (string) $row->scheduled_at : null,
                summary: __('statamic-clientrooms::messages.timeline_booking', ['endpoint' => (string) $row->endpoint]),
                badge: $cancelled
                    ? ['text' => __('statamic-clientrooms::messages.timeline_badge_cancelled'), 'color' => 'red']
                    : ['text' => __('statamic-clientrooms::messages.timeline_badge_'.(string) $row->status), 'color' => 'purple'],
                detail: $row->duration_minutes !== null
                    ? [['label' => __('statamic-clientrooms::messages.timeline_duration'), 'value' => $row->duration_minutes.' min']]
                    : [],
            );
        }

        return $out;
    }

    /**
     * The same shape LeadHub's `TimelineEntry::toArray()` produces, so the
     * screen renders both answers with one template.
     *
     * @param  array{text: string, color: string}|null  $badge
     * @param  array{cent: int, currency: string, formatted: string}|null  $amount
     * @param  list<array{label: string, value: string}>  $detail
     * @return array<string, mixed>
     */
    protected function entry(string $id, string $source, string $kind, ?string $at, string $summary, ?array $badge = null, ?array $amount = null, array $detail = []): array
    {
        $carbon = null;

        if ($at !== null) {
            try {
                $carbon = Carbon::parse($at);
            } catch (Throwable) {
                $carbon = null;
            }
        }

        return [
            'id' => $id,
            'source' => $source,
            'kind' => $kind,
            'at' => $carbon?->toAtomString(),
            'at_human' => $carbon?->diffForHumans(),
            'summary' => $summary,
            'url' => null,
            'badge' => $badge,
            'amount' => $amount,
            'detail' => $detail,
            'actor' => null,
            'payload' => [],
        ];
    }
}
