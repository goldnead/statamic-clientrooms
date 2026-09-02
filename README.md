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
- **Tasks.** A list per room: title, description, kind, state, priority, an estimate in minutes, a
  due date, done by whom. Ticking one fires `ClientRoomTaskCompleted`, once. Every task carries a
  `published_status`, and only `published` reaches the client — a task the coach is still writing is
  in the Control Panel and nowhere else.
- **Submissions.** The client hands a task back with text, files, or both. A second attempt is a
  second submission, never an overwrite. Handing in fires `ClientRoomTaskSubmitted` and is not the
  same thing as the coach ticking the task off.
- **Sessions.** What happened: the sitting, when it was, the write-up, the coach's own notes, and the
  way back to the recording and the transcript. Usually not typed here — they arrive from whatever
  cockpit runs the sitting, once per sitting, keyed on that system's own id, so a retried delivery
  updates instead of duplicating. A session carries the same `published_status` line as a task.
- **Documents.** Statamic assets in one container per brand, one folder per room. Each file has a
  visible-to-client switch; the client downloads through a signed link that expires after 30 minutes
  and never sees the storage path. Only configured file types are accepted.
- **Timeline.** With `statamic-leadhub`, the room shows the contact's merged timeline — the same
  `ContactTimeline` LeadHub's own contact screen renders. Without it, a short list read from the
  `payments` and `bookings` tables, where those exist. Read-only either way.
- **Two kinds of notes.** `notes` for the team, `client_notes` for the client. The tag yields only the
  second.
- **A tag for the members area.** `{{ client_room }}` renders the signed-in user's own room and nobody
  else's. `{{ client_room:exists }}` says whether there is one.
- **Events** for automations: `ClientRoomOpened`, `ClientRoomClosed`, `ClientRoomTaskCompleted`,
  `ClientRoomTaskSubmitted`.

Nothing else in the suite is required. Payments, LeadHub, Booking and Brand Context are detected with
`class_exists` and used when present.

## Requirements

- PHP 8.2 or newer
- Statamic 6 (Laravel 12 or 13)
- A database: MySQL or SQLite. The five tables are the addon.
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

'allowed_extensions' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'png', 'jpg', 'jpeg', 'mp3', 'mp4', 'zip'],

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

$task = ClientRooms::addTask($room, 'Send the recording', now()->addWeek(), null, [
    'description' => 'Two minutes, quiet, no click.',
    'type' => 'exercise',                            // see config `task_types`
    'priority' => 'high',                            // low|medium|high|urgent
    'estimated_minutes' => 15,
]);

ClientRooms::updateTask($task, ['priority' => 'low']);   // only the keys you hand over
ClientRooms::publishTask($task, false);              // back to draft: the client stops seeing it
$submission = ClientRooms::submitTask($task, 'Here is my recording.', [$uploadedFile]);
ClientRooms::submissionDownloadUrl($submission->files[0]);   // signed, expires
ClientRooms::removeSubmission($submission);          // takes its files off the disk too

ClientRooms::completeTask($task);                    // idempotent, one event
ClientRooms::reopenTask($task);

$session = ClientRooms::recordSession($room, 'Lesson 12', now(), [
    'duration_minutes' => 60,
    'protocol' => 'We worked on the passaggio …',   // what the client reads
    'notes' => 'Pushes on the high A.',             // never leaves the Control Panel
]);

// The same write with an identity attached: called twice with one external id,
// this updates rather than duplicates. That is what makes a backfill safe to
// re-run and a webhook safe to retry.
$session = ClientRooms::importSession($room, 'a4f1…-vf-session-uuid', [
    'title' => 'Lesson 12',
    'held_at' => '2026-08-14T10:30:00+00:00',       // any moment a date parser reads
    'status' => 'completed',                        // the sending system's own word
    'published_status' => 'published',              // absent → draft, the safe side
    'protocol' => '…',
    'recording_url' => $temporaryUrl,
    'recording_url_expires_at' => now()->addHours(6),
    'has_transcript' => true,
    'meta' => ['external_booking_id' => 'cal-778'],
]);

ClientRooms::updateSession($session, ['summary' => 'Good progress.']);
ClientRooms::publishSession($session, false);        // back to draft
ClientRooms::removeSession($session);

$file = ClientRooms::attach($room, $uploadedFile, 'Practice plan', visibleToClient: true);
ClientRooms::downloadUrl($file);                     // signed, expires

ClientRooms::timeline($room);                        // ['mode', 'entries', 'sources', 'stats', 'total']
```

`open()` on an address that already has an open room returns that room and fires nothing. On a closed
room it reopens it and fires `ClientRoomOpened` with `reopened = true`. Two callers opening the same
address at the same moment get the same room: the unique key decides, the loser picks up the winner's
row.

A room opened by address links LeadHub's contact of that brand when there is one; a room that has no
link yet looks the contact up once when its timeline is first rendered, and keeps it.

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
(`id`, `title`, `description`, `type`, `priority`, `status`, `estimated_minutes`, `due_at`, `done`,
`done_at`, `overdue`, `submitted`, `submission_count`, `submissions`), `sessions` (`id`, `title`,
`held_at`, `duration_minutes`, `status`, `agenda`, `summary`, `protocol`, `coach_name`,
`has_protocol`, `has_recording`, `recording_url`, `has_transcript`, `transcript_url` — newest first),
`files` (`id`, `title`, `filename`, `url`, `uploaded_at` — visible files only,
signed URLs). A closed room, a user without one, or no user at all: `no_results`. A ready-made view
ships as `{{ partial:statamic-clientrooms::room }}`.

**`tasks` and `sessions` hold only published rows.** One on `draft` or `archived` is not in the list
at all — not flagged, not greyed out, absent — so a template cannot leak what the coach has not
finished writing. The coach's `notes` on a session are in neither shape.

**A session's `recording_url` is null once its link has expired**, while `has_recording` stays true.
Check the flag to show the row, the URL to decide whether to link it. `status` is derived rather than stored: a task past its due date reads `overdue` without
anybody having written that word.

**Every free-text value arrives HTML-escaped** (`name`, `owner_name`, `notes_for_client`, task and
file titles, `filename`). Print them as they are; for line breaks in the notes use `| nl2br`. If your
template escapes again, use `sanitize:0` so the entities are not encoded twice (`sanitize:false`
double-encodes: Antlers reads the parameter as a string).

**Download links are bearer links.** `url` is valid for `download_ttl_minutes` (30 by default) for
anyone who holds it, and is produced fresh on every render. Keep the page behind your login and do
not put the link into a mail.

## Sessions

A room's third noun, next to tasks and documents: what happened. A session holds the sitting itself,
the write-up the client reads, the coach's own notes, and the way back to the recording and the
transcript.

**They usually arrive rather than being typed.** `importSession()` takes the id the sending system
uses for that sitting and writes the row once. Called again with the same id it updates — so a
backfill can be re-run, and a webhook that retries eight times with backoff does not leave eight
sittings behind. If the sitting turns up pointing at a different room than last time it moves, because
the sending system decides whose sitting it is.

**A session imported without an explicit `published_status` is a draft.** The sending system published
it or did not; absent that word, it waits in the Control Panel. A session typed by hand through
`recordSession()` is published, on the same reasoning as a task: somebody named a client and meant it
to arrive.

**The two link fields carry an expiry, and that is the point.** A cockpit that serves recordings mints
a signed URL per request and lets it die in a few hours. Store one bare and the client eventually
clicks a link that used to work. Hand `recording_url_expires_at` over with it and the accessors do the
rest:

```php
$session->hasRecording();     // a recording exists — a fact about the sitting
$session->recordingUrl();     // …and null once the link is no longer worth handing out
$session->hasTranscript();    // reported by the sender, link or no link
$session->transcriptUrl();
```

Both readers — the tag and the JSON API — use the accessors, so an expired link reaches a template as
nothing at all while `has_recording` still says the hour was recorded. That is what lets a page offer
"ask your coach for a fresh link" instead of implying nothing was ever captured. A link stored without
an expiry is taken at face value: a host that keeps a permanent URL means it.

`notes` is the coach's desk. It is not in the tag's shape and not in the API's, and there is no reader
for it outside the Control Panel.

## Control Panel

**Tools → Clients.** The listing runs on core's `Listing` (search, sortable columns, column picker).
The detail page has the tasks, the documents with upload and visibility switch, the timeline, the
owner, and both note fields. Close and reopen from the header.

A task is added with a title and a date; **More fields** folds out description, kind, priority,
minutes and the visibility switch. Each row carries that switch too, so a task can be taken back off
the client's screen with one click, and the pencil opens the whole task for editing in place. A task
that is not visible says so next to its title.

Permissions: `view client rooms` (listing, detail, downloads) and `edit client rooms` (everything that
writes). Every write route is guarded twice: `can:` middleware and the Gate in the controller.

## The members area as JSON

For a front end that renders itself rather than through Antlers. Turn it off with
`'member_api' => false` if your site uses the tag.

```
GET   /!/statamic-clientrooms/me                          the room, its published tasks and sessions, its files
PATCH /!/statamic-clientrooms/me/tasks/{task}             {"done": true|false}
POST  /!/statamic-clientrooms/me/tasks/{task}/submissions {"body": "...", "files[]": …}
```

Signed in, or 401. **No route takes a room id** — every one finds the room from the signed-in user, so
there is no parameter anybody could point at somebody else's room. A task id is checked against that
room and against `published_status` first: a task the coach is still writing answers 404, the same
as one that never existed. Submissions are throttled to 20 a minute and capped by
`member_upload_max_kb`; the accepted extensions are the same `allowed_extensions` as everywhere else.

The routes always answer JSON, `Accept` header or not, so a `FormData` post that fails validation
comes back as a 422 with field errors rather than a redirect that looks like success. The signed-out
case is answered by the controller as a 401 rather than by the `auth` middleware, which would decide
between JSON and a login redirect from that same header — and which Laravel's middleware priority
puts ahead of anything that could set it.

These routes sit in the `web` group, so **CSRF applies**. A front end needs to send the token: read
the `XSRF-TOKEN` cookie and send it back as `X-XSRF-TOKEN` (axios does this on its own), or put a
`_token` field in the `FormData`.

Values are **not** HTML-escaped here, unlike in the tag: JSON is data, and whoever renders it escapes
it. The tag escapes because Antlers prints straight into a document.

## Multi-brand

With `goldnead/statamic-brand-context` installed, rooms carry `brand_id` and read through its global
scope; the same address may have one room per brand. Without it every row is brand `0` and nothing
filters.

Documents follow the brand: each brand gets its own asset container, `<container>-<brandId>`
(`clientrooms-2`), created by `clientrooms:install` and again at the first upload if it is missing.
A container is the unit Statamic grants asset permissions on — give a brand's staff
`view clientrooms-2 assets` / `upload clientrooms-2 assets` and no other, and the Assets section
shows them their clients' files only. The room screens themselves do not go through the asset
permissions; they are guarded by `view client rooms` / `edit client rooms` plus the brand scope.

## What may be uploaded

`allowed_extensions` (default: pdf, doc, docx, xls, xlsx, ppt, pptx, png, jpg, jpeg, mp3, mp4, zip) is
checked at the upload endpoint and again in `ClientRooms::attach()`. An empty list accepts
everything. Files up to 50 MB.

## Tests

`composer test` — facade rules and the insert race, listener idempotency and product matching,
permissions (403 for every write route without `edit client rooms`), signed links (tampered,
expired, hidden, closed room), the tag with and without a user and with hostile text, the fallback
timeline and the LeadHub link, brand isolation for listing, detail, tag and containers, the file type
list, the owner picker, the install command.

## License

Proprietary. See [LICENSE.md](LICENSE.md).
