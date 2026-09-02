# Statamic Client Rooms

One lasting room per coaching client: what happened, what is to do, what you shared. Opened by the
first purchase, kept when the access runs out.

Entitlements say who may open what. Booking says who has how many sessions left. LeadHub knows the
person. None of them holds the *relationship* — the running list of tasks, the recording you sent
last week, the note about what to work on next. That is what a room is.

## What it does

- **One room per client.** Keyed by e-mail address (and brand, on a multi-brand install). Open it by
  hand in the Control Panel or through the facade; with `statamic-payments` installed, the first paid
  coaching product opens it on its own.
- **Tasks.** A short list per room: title, due date, done by whom. Ticking one fires
  `ClientRoomTaskCompleted`, once.
- **Documents.** Statamic assets in one container, one folder per room. Each file has a
  visible-to-client switch; the client downloads through a signed link that expires after 30 minutes
  and never sees the storage path.
- **Timeline.** With `statamic-leadhub`, the room shows the contact's merged timeline — the same
  `ContactTimeline` LeadHub's own contact screen renders. Without it, a short list read from the
  `payments` and `bookings` tables, where those exist. Read-only either way.
- **Two kinds of notes.** `notes` for the team, `client_notes` for the client. The tag yields only the
  second.
- **A tag for the members area.** `{{ client_room }}` renders the signed-in user's own room and nobody
  else's. `{{ client_room:exists }}` says whether there is one.
- **Events** for automations: `ClientRoomOpened`, `ClientRoomClosed`, `ClientRoomTaskCompleted`.

Nothing else in the suite is required. Payments, LeadHub, Booking and Brand Context are detected with
`class_exists` and used when present.

## Requirements

- PHP 8.2 or newer
- Statamic 6 (Laravel 12 or 13)
- A database: MySQL or SQLite. The three tables are the addon.
- A disk for the documents. The default `local` disk of every Laravel install will do.

## Install

```bash
composer require goldnead/statamic-clientrooms
php artisan migrate
php please clientrooms:install          # creates the asset container
php please vendor:publish --tag=statamic-clientrooms-config
```

The migration is the addon; nothing works without it. `clientrooms:install` creates the asset
container named in `config/statamic-clientrooms.php` (`clientrooms`) on the configured disk. The
default disk is `local`, which has no public URL — client documents should not have one.

## Configuration

```php
// config/statamic-clientrooms.php
'container' => 'clientrooms',
'disk' => 'local',

// Which paid product opens a room. Kinds are `type` values from statamic-products.
'open_on_product_types' => ['sessions'],
'open_on_products' => [],               // handles, whatever their kind

'default_owner' => env('CLIENTROOMS_DEFAULT_OWNER'),   // user id or e-mail
'download_ttl_minutes' => 30,
'timeline_limit' => 100,
```

## The facade

```php
use Goldnead\ClientRooms\Facades\ClientRooms;

$room = ClientRooms::open('maria@example.com', $ownerUserId, ['name' => 'Maria Beispiel']);
$room = ClientRooms::open($leadhubContact);           // links contact_id, takes the brand
ClientRooms::close($room);
ClientRooms::reopen($room);                          // or open() again

ClientRooms::forEmail('maria@example.com');          // ?ClientRoom, current brand
ClientRooms::forUser(User::current());               // by the user's address

$task = ClientRooms::addTask($room, 'Send the recording', now()->addWeek());
ClientRooms::completeTask($task);                    // idempotent, one event
ClientRooms::reopenTask($task);

$file = ClientRooms::attach($room, $uploadedFile, 'Practice plan', visibleToClient: true);
ClientRooms::downloadUrl($file);                     // signed, expires

ClientRooms::timeline($room);                        // ['mode', 'entries', 'sources', 'stats', 'total']
```

`open()` on an address that already has an open room returns that room and fires nothing. On a closed
room it reopens it and fires `ClientRoomOpened` with `reopened = true`.

## Opening rooms automatically

With `statamic-payments` installed the addon listens to `PaymentPaid`. When a line of the payment is a
product whose `type` is in `open_on_product_types`, or whose handle is in `open_on_products`, the
buyer's room is opened or reopened. The brand comes from the payment; a payment without one takes the
brand of the product that matched. `default_owner` becomes the owner. Idempotent: two payments, one
room.

## In templates

```antlers
{{ client_room }}
    {{ if no_results }}
        <p>There is no room for you yet.</p>
    {{ else }}
        <h2>{{ name }}</h2>
        <p>{{ notes_for_client }}</p>

        {{ tasks }}<li class="{{ if done }}done{{ /if }}">{{ title }}</li>{{ /tasks }}
        {{ files }}<a href="{{ url }}">{{ title }}</a>{{ /files }}
    {{ /if }}
{{ /client_room }}
```

Variables: `id`, `name`, `email`, `status`, `opened_at`, `owner_name`, `notes_for_client`, `tasks`
(`id`, `title`, `due_at`, `done`, `done_at`), `files` (`id`, `title`, `filename`, `url`,
`uploaded_at` — visible files only, signed URLs). A closed room, a user without one, or no user at
all: `no_results`. A ready-made view ships as `{{ partial:statamic-clientrooms::room }}`.

## Control Panel

**Tools → Clients.** The listing runs on core's `Listing` (search, sortable columns, column picker).
The detail page has the tasks, the documents with upload and visibility switch, the timeline, the
owner, and both note fields. Close and reopen from the header.

Permissions: `view client rooms` (listing, detail, downloads) and `edit client rooms` (everything that
writes). Every write route is guarded twice: `can:` middleware and the Gate in the controller.

## Multi-brand

With `goldnead/statamic-brand-context` installed, rooms carry `brand_id` and read through its global
scope; the same address may have one room per brand. Without it every row is brand `0` and nothing
filters.

## Tests

`composer test` — facade rules, listener idempotency and product matching, permissions (403 for every
write route without `edit client rooms`), signed links (tampered, expired, hidden, closed room), the
tag with and without a user, the fallback timeline, the install command.

## License

Proprietary. See [LICENSE.md](LICENSE.md).
