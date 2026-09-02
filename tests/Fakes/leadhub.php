<?php

/*
 * Stand-ins for `goldnead/statamic-leadhub`, a suggest that is not in vendor:
 * the contact model with the columns this addon reads, and a timeline that
 * answers a fixed list and records who asked. Enough to prove the link is
 * made and the timeline is delegated, not reimplemented.
 */

namespace Goldnead\Leadhub\Models {
    if (! class_exists(Contact::class)) {
        class Contact extends \Illuminate\Database\Eloquent\Model
        {
            protected $table = 'leadhub_contacts';

            protected $guarded = [];

            public $timestamps = false;
        }
    }
}

namespace Goldnead\Leadhub\Support\Timeline {
    if (! class_exists(ContactTimeline::class)) {
        class ContactTimeline
        {
            /** @var list<int> The contact ids `build()` was asked about. */
            public static array $asked = [];

            public function build(object $contact, ?int $limit = null): array
            {
                static::$asked[] = (int) $contact->getKey();

                return [
                    'entries' => [[
                        'id' => 'leadhub:fake-1',
                        'source' => 'leadhub',
                        'kind' => 'leadhub.note_added',
                        'at' => '2026-08-01T10:00:00+00:00',
                        'at_human' => 'a while ago',
                        'summary' => 'Notiz hinzugefügt',
                        'url' => null,
                        'badge' => null,
                        'amount' => null,
                        'detail' => [],
                        'actor' => 'System',
                        'payload' => [],
                    ]],
                    'sources' => ['payments' => true, 'booking' => false],
                    'failed' => [],
                    'stats' => ['purchase_count' => 1],
                    'total' => 1,
                ];
            }
        }
    }
}
