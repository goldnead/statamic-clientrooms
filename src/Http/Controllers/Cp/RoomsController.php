<?php

namespace Goldnead\ClientRooms\Http\Controllers\Cp;

use Goldnead\ClientRooms\ClientRoomsManager;
use Goldnead\ClientRooms\Http\Controllers\Cp\Concerns\AuthorizesRooms;
use Goldnead\ClientRooms\Http\Resources\Cp\RoomsCollection;
use Goldnead\ClientRooms\Models\ClientRoom;
use Goldnead\ClientRooms\Models\ClientRoomFile;
use Goldnead\ClientRooms\Models\ClientRoomTask;
use Goldnead\ClientRooms\Models\ClientRoomTaskSubmission;
use Goldnead\ClientRooms\Models\ClientRoomTaskSubmissionFile;
use Goldnead\ClientRooms\Support\Brands;
use Goldnead\ClientRooms\Support\Owners;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Statamic\Http\Controllers\CP\CpController;
use Statamic\Statamic;

class RoomsController extends CpController
{
    use AuthorizesRooms;

    public function __construct(Request $request, protected ClientRoomsManager $rooms)
    {
        parent::__construct($request);
    }

    public function index(Request $request)
    {
        $this->authorize('view client rooms');

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return $this->json($request);
        }

        return Inertia::render('statamic-clientrooms::Rooms/Index', [
            'listingUrl' => cp_route('client-rooms.index'),
            'storeUrl' => cp_route('client-rooms.store'),
            'sortColumn' => 'last_activity_at',
            'sortDirection' => 'desc',
            'hasAny' => ClientRoom::query()->exists(),
            'canEdit' => $this->canEdit(),
            'owners' => Owners::options(),
            'multiBrand' => Brands::multiBrand(),
            't' => $this->strings(),
        ]);
    }

    public function show(int $room)
    {
        $this->authorize('view client rooms');

        $room = $this->findRoom($room);
        $timeline = $this->rooms->timeline($room);

        return Inertia::render('statamic-clientrooms::Rooms/Show', [
            'room' => $this->present($room),
            'tasks' => $room->tasks()->with('submissions.files')->get()->map(fn (ClientRoomTask $task) => $this->presentTask($task))->values()->all(),
            'files' => $room->files()->get()->map(fn (ClientRoomFile $file) => $this->presentFile($file))->values()->all(),
            'timeline' => $timeline['entries'],
            'timelineMode' => $timeline['mode'],
            'timelineTotal' => $timeline['total'],
            'timelineSources' => collect($timeline['sources'])
                ->map(fn (bool $available, string $key) => [
                    'key' => $key,
                    'label' => $this->sourceLabel($key),
                    'available' => $available,
                    'failed' => in_array($key, $timeline['failed'], true),
                ])
                ->values()
                ->all(),
            'stats' => $timeline['stats'],
            'taskOptions' => $this->taskOptions(),
            'owners' => Owners::options(),
            'canEdit' => $this->canEdit(),
            'urls' => [
                'index' => cp_route('client-rooms.index'),
                'update' => cp_route('client-rooms.update', $room->id),
                'close' => cp_route('client-rooms.close', $room->id),
                'reopen' => cp_route('client-rooms.reopen', $room->id),
                'tasks' => cp_route('client-rooms.tasks.store', $room->id),
                'files' => cp_route('client-rooms.files.store', $room->id),
            ],
            't' => $this->strings(),
        ]);
    }

    /** Open a room by hand. */
    public function store(Request $request)
    {
        $this->authorize('edit client rooms');

        $data = $request->validate([
            'email' => ['required', 'email', 'max:191'],
            'name' => ['nullable', 'string', 'max:191'],
            'owner_user_id' => ['nullable', 'string', 'max:64'],
        ]);

        $room = $this->rooms->open($data['email'], $data['owner_user_id'] ?? Owners::currentId(), [
            'name' => $data['name'] ?? null,
        ]);

        // Opened where a brand is current: the model stamped it. Opened by an
        // address that already has a room: that room, wherever it was.
        return redirect(cp_route('client-rooms.show', $room->id))
            ->with('success', __('statamic-clientrooms::messages.opened', ['name' => $room->displayName()]));
    }

    public function update(Request $request, int $room)
    {
        $this->authorize('edit client rooms');

        $room = $this->findRoom($room);

        $data = $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:191'],
            'owner_user_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'client_notes' => ['sometimes', 'nullable', 'string', 'max:65535'],
        ]);

        if (array_key_exists('owner_user_id', $data)) {
            $owner = Owners::resolveId($data['owner_user_id']);

            if ($data['owner_user_id'] !== null && $data['owner_user_id'] !== '' && $owner === null) {
                return back()->withErrors(['owner_user_id' => __('statamic-clientrooms::messages.owner_unknown')]);
            }

            $data['owner_user_id'] = $owner;
        }

        $room->fill($data);

        if ($room->isDirty()) {
            $room->last_activity_at = now();
            $room->save();
        }

        return back()->with('success', __('statamic-clientrooms::messages.saved'));
    }

    public function close(int $room)
    {
        $this->authorize('edit client rooms');

        $this->rooms->close($this->findRoom($room));

        return back()->with('success', __('statamic-clientrooms::messages.closed'));
    }

    public function reopen(int $room)
    {
        $this->authorize('edit client rooms');

        $this->rooms->reopen($this->findRoom($room));

        return back()->with('success', __('statamic-clientrooms::messages.reopened'));
    }

    protected function json(Request $request)
    {
        $query = ClientRoom::query()
            // Open means the client owes it: published and not ticked. A draft
            // is not work anybody has been given yet, and counting it here
            // would put a number on the listing that the room itself, which
            // counts drafts separately, then contradicts.
            ->withCount(['tasks as open_tasks_count' => fn (Builder $q) => $q
                ->whereNull('done_at')
                ->where('published_status', ClientRoomTask::PUBLISHED_PUBLISHED)]);

        if ($search = trim((string) $request->get('search', ''))) {
            $query->where(function (Builder $q) use ($search): void {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
                $q->where('name', 'like', $like)->orWhere('email', 'like', $like);
            });
        }

        if (in_array($status = (string) $request->get('status', ''), ClientRoom::statuses(), true)) {
            $query->where('status', $status);
        }

        [$column, $direction] = $this->order($request);
        $query->orderBy($column, $direction);

        $page = $query->paginate(Statamic::cpPerPage($request->get('perPage')));

        return (new RoomsCollection($page))
            ->columnPreferenceKey('statamic-clientrooms.rooms.columns');
    }

    /** @return array{0: string, 1: string} */
    protected function order(Request $request): array
    {
        $sortable = [
            'name' => 'name',
            'email' => 'email',
            'status' => 'status',
            'open_tasks' => 'open_tasks_count',
            'last_activity_at' => 'last_activity_at',
            'opened_at' => 'opened_at',
            'brand' => 'brand_id',
        ];

        $column = $sortable[(string) $request->get('sort', 'last_activity_at')] ?? 'last_activity_at';
        $direction = strtolower((string) $request->get('order', 'desc')) === 'asc' ? 'asc' : 'desc';

        return [$column, $direction];
    }

    /** @return array<string, mixed> */
    protected function present(ClientRoom $room): array
    {
        return [
            'id' => $room->id,
            'name' => $room->name,
            'display_name' => $room->displayName(),
            'email' => $room->email,
            'status' => $room->status,
            'status_label' => __('statamic-clientrooms::messages.status_'.$room->status),
            'is_open' => $room->isOpen(),
            'owner_user_id' => $room->owner_user_id,
            'owner_label' => Owners::label($room->owner_user_id),
            'opened_at' => $room->opened_at?->toIso8601String(),
            'opened_human' => $room->opened_at?->diffForHumans(),
            'closed_at' => $room->closed_at?->toIso8601String(),
            'closed_human' => $room->closed_at?->diffForHumans(),
            'notes' => $room->notes,
            'client_notes' => $room->client_notes,
            'brand_label' => Brands::label($room->brand_id),
            'contact_url' => $this->contactUrl($room),
            'opened_by' => is_array($room->meta) ? ($room->meta['opened_by'] ?? null) : null,
        ];
    }

    /** @return array<string, mixed> */
    protected function presentTask(ClientRoomTask $task): array
    {
        $status = $task->workflowStatus();

        return [
            'id' => $task->id,
            'title' => $task->title,
            'description' => $task->description,
            'type' => $task->type,
            'type_label' => $this->taskTypeLabel($task->type),
            // What is stored, for the form, and what is true, for the badge.
            // The two part company the moment a due date passes.
            'status' => $task->status,
            'workflow_status' => $status,
            'status_label' => $this->taskStatusLabel($status),
            'published_status' => $task->published_status,
            'published' => $task->isPublished(),
            'draft' => $task->isDraft(),
            'priority' => $task->priority,
            'priority_label' => $task->priority !== null ? $this->taskPriorityLabel($task->priority) : null,
            'estimated_minutes' => $task->estimated_minutes,
            'due_at' => $task->due_at?->toDateString(),
            'due_human' => $task->due_at?->diffForHumans(),
            'done' => $task->isDone(),
            'done_at' => $task->done_at?->toIso8601String(),
            'done_human' => $task->done_at?->diffForHumans(),
            'overdue' => $task->isOverdue(),
            'update_url' => cp_route('client-rooms.tasks.update', [$task->room_id, $task->id]),
            'delete_url' => cp_route('client-rooms.tasks.destroy', [$task->room_id, $task->id]),
            'submissions' => $task->submissions->map(fn (ClientRoomTaskSubmission $submission) => [
                'id' => $submission->id,
                'body' => $submission->body,
                'submitted_human' => $submission->handedInAt()?->diffForHumans(),
                'submitted_at' => $submission->handedInAt()?->toIso8601String(),
                'files' => $submission->files->map(fn (ClientRoomTaskSubmissionFile $file) => [
                    'id' => $file->id,
                    'filename' => $file->filename(),
                    'size_human' => $this->humanSize($file->size),
                    'missing' => $file->asset() === null,
                    // The Control Panel route, not the client's signed link:
                    // a screen left open for an hour should still work.
                    'download_url' => cp_route('client-rooms.submissions.download', [$task->room_id, $file->id]),
                ])->values()->all(),
                'delete_url' => cp_route('client-rooms.submissions.destroy', [$task->room_id, $submission->id]),
            ])->values()->all(),
        ];
    }

    /**
     * The task vocabularies, as the dropdowns want them.
     *
     * No list for `published_status`: the screen offers a switch, not three
     * words, so a list of them would be payload nobody reads.
     *
     * @return array{types: list<array{value: string, label: string}>, statuses: list<array{value: string, label: string}>, priorities: list<array{value: string, label: string}>}
     */
    protected function taskOptions(): array
    {
        $types = array_values(array_filter((array) config('statamic-clientrooms.task_types', []), 'is_string'));

        return [
            'types' => array_map(fn (string $type): array => [
                'value' => $type,
                'label' => (string) $this->taskTypeLabel($type),
            ], $types),

            // `completed` and `overdue` are not offered: the first is the tick,
            // the second is the calendar. Offering them would let a coach say
            // something the screen then contradicts.
            'statuses' => array_map(fn (string $status): array => [
                'value' => $status,
                'label' => $this->taskStatusLabel($status),
            ], [
                ClientRoomTask::STATUS_ASSIGNED,
                ClientRoomTask::STATUS_IN_PROGRESS,
                ClientRoomTask::STATUS_CANCELLED,
            ]),

            'priorities' => array_map(fn (string $priority): array => [
                'value' => $priority,
                'label' => $this->taskPriorityLabel($priority),
            ], ClientRoomTask::PRIORITIES),
        ];
    }

    protected function taskTypeLabel(?string $type): ?string
    {
        return $type === null ? null : $this->message('task_type_'.$type, $type);
    }

    protected function taskStatusLabel(string $status): string
    {
        // `in-progress` carries a hyphen on the wire; a translation key cannot.
        return $this->message('task_status_'.str_replace('-', '_', $status), $status);
    }

    protected function taskPriorityLabel(string $priority): string
    {
        return $this->message('task_priority_'.$priority, $priority);
    }

    /** A translation, or the handle itself where none is written. */
    protected function message(string $key, string $fallback): string
    {
        $full = 'statamic-clientrooms::messages.'.$key;
        $translated = __($full);

        return is_string($translated) && $translated !== $full ? $translated : $fallback;
    }

    /** @return array<string, mixed> */
    protected function presentFile(ClientRoomFile $file): array
    {
        $asset = $file->asset();

        return [
            'id' => $file->id,
            'title' => $file->displayTitle(),
            'filename' => basename($file->path),
            'size' => $asset?->size(),
            'size_human' => $asset !== null ? $this->humanSize((int) $asset->size()) : null,
            'missing' => $asset === null,
            'visible_to_client' => $file->visible_to_client,
            'uploaded_human' => $file->created_at?->diffForHumans(),
            'uploaded_by' => Owners::label($file->uploaded_by),
            'download_url' => cp_route('client-rooms.files.download', [$file->room_id, $file->id]),
            'update_url' => cp_route('client-rooms.files.update', [$file->room_id, $file->id]),
            'delete_url' => cp_route('client-rooms.files.destroy', [$file->room_id, $file->id]),
        ];
    }

    protected function contactUrl(ClientRoom $room): ?string
    {
        if ($room->contact_id === null || ! class_exists('\Goldnead\Leadhub\Models\Contact')) {
            return null;
        }

        try {
            return cp_route('leadhub.contacts.show', $room->contact_id);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function sourceLabel(string $key): string
    {
        return $this->message('source_'.$key, $key);
    }

    protected function humanSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / (1024 * 1024), 1).' MB';
    }

    /**
     * Every label the screens need, finished, in the user's language.
     *
     * @return array<string, string>
     */
    protected function strings(): array
    {
        $keys = [
            'title', 'nav', 'new', 'open_room', 'back_to_list', 'utilities',
            'empty_heading', 'empty_title', 'empty_description',
            'column_name', 'column_email', 'column_status', 'column_open_tasks', 'column_last_activity', 'column_brand', 'column_opened_at',
            'status_open', 'status_closed',
            'field_email', 'field_name', 'field_owner', 'field_owner_help', 'owner_none',
            'opened_since', 'closed_since', 'opened_by_payments', 'contact_link', 'brand',
            'action_close', 'action_reopen', 'close_title', 'close_body', 'cancel', 'save', 'delete',
            'panel_timeline', 'timeline_sources', 'timeline_empty', 'timeline_failed', 'timeline_more', 'timeline_mode_fallback',
            'stat_first_contact', 'stat_last_contact', 'stat_purchases',
            'panel_tasks', 'tasks_empty', 'task_title_placeholder', 'task_due', 'task_add', 'task_done', 'task_reopen', 'task_overdue', 'task_delete_title', 'task_delete_body', 'tasks_open_count',
            'task_title', 'task_description', 'task_description_placeholder', 'task_type', 'task_type_none', 'task_status', 'task_priority', 'task_priority_none', 'task_minutes', 'task_minutes_unit',
            'task_visible', 'task_visible_help', 'task_draft', 'task_archived',
            'panel_submissions', 'submission_none', 'submission_count', 'submission_handed_in', 'submission_delete_title', 'submission_delete_body', 'submission_file_missing', 'task_more', 'task_less', 'task_edit', 'task_publish', 'task_unpublish', 'tasks_draft_count',
            'panel_files', 'files_empty', 'file_title_placeholder', 'file_choose', 'file_upload', 'file_visible', 'file_hidden', 'file_missing', 'file_download', 'file_delete_title', 'file_delete_body', 'file_uploaded_by',
            'panel_notes', 'notes_internal', 'notes_internal_help', 'notes_client', 'notes_client_help', 'notes_save',
            'view_action', 'yes', 'no',
        ];

        $out = [];

        foreach ($keys as $key) {
            $out[$key] = __('statamic-clientrooms::messages.'.$key);
        }

        return $out;
    }
}
