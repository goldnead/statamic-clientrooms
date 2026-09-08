# Changelog

## 0.8.0 — 2026-09-07

### New: eight values of the client rooms in the Control Panel

Under **Settings → Addon Settings** there is a section for this addon, with four groups:

- **Files in the room:** the permitted file extensions (checked on upload and again when
  attaching; removing an extension only blocks new uploads, what already lies in the room stays
  there), the maximum size per file for the member API, and the lifetime of a download link. A
  shortened window does not devalue links already sent; it applies from the next time the room
  is built.
- **Tasks:** the types the task form offers, and the only ones it accepts. Removing a type
  changes no existing task, it can only no longer be assigned afresh.
- **Rooms that open by themselves:** the product types and the individual products for which a
  paid payment opens a room, and the owner a room opened that way is given. The owner is entered
  when the room opens and does not change existing rooms.
- **Timeline:** how many events a room shows. Nothing is deleted in the process.

Only the deviation is stored, everything else still follows
`config/statamic-clientrooms.php`.

Not on the page: `container` and `disk`, because a change has to move the files first, and a
field that only moves the pointer turns every existing room into an empty one. That is what
`php please clientrooms:install` is for. Also left out: `member_api`, which is read while the
routes are registered and would take effect here only after the next deploy.

**New permission `manage clientrooms settings`.** Until it is assigned to a role nobody sees the
section. Existing permissions are unchanged.

**`goldnead/statamic-brand-context` stays a soft dependency, now with a minimum version of
1.13.** The addon still runs single-brand without the neighbour; this page then does not
register itself, and the values stay in the config as before. Whoever has it needs 1.13: under
older versions the settings of the addons registered last were not applied at all on an
installation with a single brand, and up to 1.12 a second save of the same section deleted the
first save's override without a message. Until now `suggest` carried no number, so a buyer ended
up on 1.12.

## 0.7.2 — 2026-09-05

The shipped bundle was older than the source it was meant to come from.

### Fixed

- **`dist/` brought up to the source.** Commit `6dc08a9` (status badges as pills, icon repaired)
  changed `resources/js`, but the committed `dist/build` stayed at the state of the morning
  before. Anyone installing 0.7.1 got the PHP of 0.7.1 and the JavaScript of 0.7.0 — exactly the
  class of mismatch that produced "Cannot read properties of undefined" in the suite on 09-03.
  The fresh build from the committed source is byte-identical to the unreviewed build that was
  already sitting in the working directory; that is what is committed now.
- **Status badges as pills, icon repaired.** 13 badges had `size="sm"` without `pill`; with a
  border and a 3px corner that looked like a cropped button. `Icon name="file"` does not exist in
  the core. (Source in the repository since 0.7.1, the bundle only now.)
- **CI never ran.** The repository is private and the workflow declared `permissions: {}`, so
  `actions/checkout` could not read the repository and every job was red before a single test.
  Now `contents: read`. The `dist` job in this CI is the check that would have caught the error
  above.

## 0.7.1 — 2026-09-03

### Fixed

- **Padding on the left, rows of equal height, the recording as an icon.** The card carries no
  padding so that the dividers run all the way through; that left the date stuck to the edge, now
  `ps-4` on the first and `pe-4` on the last column. "Recording (link expired) · Transcript (no
  link)" broke over three lines; now two icons on one line, the tooltip carries the state, and
  spelled out it stands in the stack. The draft badge is gone, the switch beside it says the same
  thing.

## 0.7.0 — 2026-09-03

The session listing did not look like Statamic.

### Changed

- **The listing is now a core `Table`**, not a hand-built `<ul>`. A session is a record with a
  date, a duration and a state, and the Control Panel has a way of showing records: column
  headers, aligned columns, dates in tabular figures. The old list was modelled on the tasks
  panel — but tasks are a note to tick off, sessions are a card index.
- **The detail sits in the `Stack`**, no longer in a row that folds open. Agenda, summary,
  write-up and the coach's own note get space there without the listing falling apart; the stack
  is where the Control Panel has always shown the individual item. The visibility switch sits in
  its footer, next to the save button.
- The column header is `Visible`, not `Visible to client` — the long text wrapped over three
  lines and pushed the delete column out of the card.

### Removed

- `session_protocol_show` / `session_protocol_hide` — there is nothing left to fold open and
  shut.

## 0.6.0 — 2026-09-02

The sessions panel, and two things the write-up was quietly doing wrong.

### Added

- **A sessions panel on the room screen.** Every sitting, drafts included — this is the coach's desk,
  and showing what the client cannot see yet is the reason to open it. Each row carries the date, the
  length, the coach, and the state of the recording and the transcript; the chevron opens agenda,
  summary, the write-up and the coach's own notes.
- `PATCH`/`DELETE` under `client-rooms/{room}/sessions/{session}`. **Deliberately no `store` and no
  edit form**: a sitting is recorded where it took place, and a second form here would invite two
  versions of one hour that the next import would silently win. What the screen decides is
  `published_status` and `notes`; both are `sometimes`, so the switch never clears a note and the note
  never republishes a draft.
- `ClientRoomSession::protocolText()` and `protocolBlocks()`.
- `protocol_html` in the tag and in the members JSON, next to `protocol`.

### Fixed

- **A bare `<` in a write-up swallowed the rest of it.** `strip_tags` reads `3<5 Minuten` as the
  start of a tag and eats everything to the next `>`; a protocol reading "Wir haben 3<5 Minuten
  geübt und danach …" arrived as "Wir haben 3", in the Control Panel and on the client's page, with
  nothing logged. Checking for a letter is not enough either — `A<B und <strong>` fails the same way
  — so a `<` now survives unless a complete, plausible tag follows it.
- **The write-up was escaped whole in the tag**, which showed the client `<h3>` in words. `protocol`
  is now the same text with the block tags turned back into line breaks; `protocol_html` is the
  markup, unescaped and unsanitised, and the README says so. This is the one exception to "every
  free-text value arrives HTML-escaped".
- `protocol` meant plain text in the tag and raw HTML in the members JSON. Both now speak the same
  two names.
- `hasProtocol()` asks the text, not the column: `<p></p>` is a column that holds something and a
  screen that holds nothing.
- A failed submission deletion was reported with `withErrors()` to a field the room screen does not
  have, so the refusal was invisible and a client's recording looked deleted when it was not. Now
  `with('error')`, which the Control Panel toasts red.
- The owner picker had no error binding and silently kept showing an owner the save had declined.

### Notes

- The panel distinguishes three states, not two: a live link, a link that has run out, and a
  transcript that exists with no link ever issued. Calling the third one "expired" would tell the
  coach to stop asking for a link that was never minted.
- Turning an **archived** session on and off again used to leave it a draft — the switch knows two
  words and the column holds three. It now returns to where it was.
- The status badge is silent for `completed`. It sits on almost every row of a coaching history, and
  a badge that is always there paints a column instead of carrying information.

## 0.5.0 — 2026-09-02

What happened, and not just what is owed.

### Added

- **Sessions.** `client_room_sessions`, one row per sitting: title, when it was held, how long,
  agenda, summary, the write-up the client reads, the coach's own notes, and pointers to the
  recording and the transcript. `ClientRoom::sessions()` yields them newest first.
- `ClientRooms::recordSession()`, `importSession()`, `updateSession()`, `publishSession()` and
  `removeSession()` on the facade.
- **`importSession()` is idempotent over the sending system's own id.** Called twice with one
  `external_id` it updates rather than duplicating, which is what makes a backfill safe to re-run and
  a webhook safe to retry. A sitting that turns up in a different room moves rather than being copied.
  A concurrent second insert loses on the unique key and picks up the winner's row, as `open()` does.
- **The link fields carry an expiry.** A cockpit that serves recordings mints a signed URL per
  request; `recording_url_expires_at` and `transcript_url_expires_at` are stored beside the URLs, and
  `recordingUrl()` / `transcriptUrl()` return null once the moment has passed. `hasRecording()` and
  `hasTranscript()` still answer that the thing exists, so a front end can ask for a fresh link
  instead of implying nothing was recorded. Both readers use the accessors.
- Sessions in the `{{ client_room }}` tag and in the members JSON, published only — and never
  `notes`, which has no reader outside the Control Panel.
- 33 tests, organised around the three ways this goes wrong: a delivery arriving twice, a draft
  reaching the client, a dead link being handed out.

### Notes

- A session imported without an explicit `published_status` is a **draft**. One typed by hand through
  `recordSession()` is published, on the same reasoning as a task.
- `external_id` is not fillable. It is the row's identity, written where the row is made and never by
  an update — an update that could move it would let one sitting quietly become another.
- Deleting a room takes its sessions with it through the model hook, not only through the foreign
  key: SQLite enforces `ON DELETE CASCADE` only with `PRAGMA foreign_keys` on.

### Still open

- No Control Panel surface yet. Sessions are written and read through the facade, the tag and the
  JSON API; the room's detail screen does not show them.

## 0.4.0 — 2026-09-02

A members area that is not Antlers.

### Added

- Three authenticated JSON routes under `/!/statamic-clientrooms/me`: read your room, tick a task,
  hand one back with text and files. `member_api` turns them off for a site that renders with the
  tag; `member_upload_max_kb` caps one file.
- Uploads are throttled (20 a minute) and checked against the same `allowed_extensions` as the
  Control Panel.
- The routes always answer JSON, so a `FormData` post that fails validation returns 422 with field
  errors instead of a redirect that looks like it worked.

### Notes

- **No route takes a room id.** Each finds the room from the signed-in user, so there is no
  parameter to change into somebody else's room — the ownership check cannot be forgotten because
  there is nothing to check against.
- A task on `draft` cannot be addressed either, not just not listed: guessing its id answers 404.
- Values are not HTML-escaped here, unlike in the tag. JSON is data; the tag prints into a document.
- The signed-out answer is a 401 from the controller, not from the `auth` middleware. `auth` picks
  between JSON and a redirect by reading the Accept header as it throws, and middleware priority
  hoists it ahead of anything that could set that header — on a host without a `login` route it
  raises an exception instead of answering.
- CSRF applies (these are `web` routes). The README says what a front end has to send.


## 0.3.0 — 2026-09-02

What the client hands back.

### Added

- `client_room_task_submissions` and `client_room_task_submission_files`, one migration with a
  `down()`. A submission carries text, files, or both — never neither.
- **A submission is never edited.** A second attempt is a second submission, so the coach can see
  there was a first one and what changed between them.
- `ClientRooms::submitTask()`, `removeSubmission()`, `submissionDownloadUrl()`, and the event
  `ClientRoomTaskSubmitted`. Handing in is the client's claim and ticking is the coach's; the two
  are separate moments and separate events.
- The client's own submissions come back through `{{ client_room }}`: each task gains `submitted`,
  `submission_count` and `submissions` (`body`, `submitted_at`, `files` with signed URLs).
- Control Panel: submissions sit indented under the task they answer, with the files downloadable
  through a CP route rather than an expiring link, and a delete that takes the files with it.
- `client_room_tasks.meta`, in its own migration — a place for a task to remember the id it was
  imported with, so an import can run twice without making duplicates.

### Notes

- **Submission files are a separate table from `client_room_files`.** The two look alike and are
  not: a room document has a `visible_to_client` switch the coach operates, a submission file is
  the client's own and has none. Two tables means no query has to remember a filter to keep the
  coach's document list free of the client's uploads.
- Deleting a task deletes its submissions **and their assets**. The database cascade alone would
  take the rows and leave the files orphaned in the container; a model hook removes them properly.
  That hook does not fire on a mass delete (`query()->delete()`).
- A submission on a `draft` task does not reach the client, because the task does not.
- **Uploads deliberately do not run inside a transaction.** A transaction rolls back rows and cannot
  roll back a disk; a second file refused after the first was written would undo the bookkeeping and
  strand the first recording in the container. `submitTask()` undoes its own work instead, files
  included, and only fires `ClientRoomTaskSubmitted` once everything is stored.
- Deleting a **room** now also removes its tasks, submissions, documents and every asset behind them.
  The foreign keys cascade all four tables in SQL, and SQL knows nothing about an asset container.
  The addon still offers no way to delete a room — rooms are closed — but a host who calls
  `delete()` no longer strands a client's recordings.

## 0.2.0 — 2026-09-02

A task can now carry the work, and the coach decides when the client sees it.

### Added

- Six columns on `client_room_tasks`, one additive migration with a `down()`: `description`,
  `type`, `status`, `published_status`, `priority`, `estimated_minutes`.
- **`published_status` is the line between the coach's desk and the client's room.** Only
  `published` reaches `{{ client_room }}`; `draft` and `archived` are absent from the list the
  template receives, not merely flagged in it.
- `ClientRooms::updateTask()` and `ClientRooms::publishTask()`. `addTask()` takes a fifth argument,
  an attributes array, so a whole imported row can be handed over at once.
- `ClientRoomTask::workflowStatus()`, derived rather than stored: done wins, a task called off stays
  cancelled, a task past its date reads `overdue` without anybody having written that word.
- Control Panel: the add form folds out description, kind, priority, minutes and a visibility
  switch; every task row has that switch and a pencil that opens the task for editing in place.
- Config `task_types` — the kinds the Control Panel offers, extendable, translated where a
  translation exists. The facade validates none of it: an import brings years of a coach's own
  vocabulary and must not lose a task to a list.

### Notes on upgrading

- Tasks that existed before this version are stamped `published` by the migration. Nobody's client
  loses a task because the addon grew a column.
- The column's own default is `draft`, so a row written straight into the table — an import, a
  fixture — stays invisible until somebody has an opinion about it. `addTask()` publishes, because
  somebody named a client, typed a title and meant it to arrive.
- Ticking a task now also sets `status` to `completed`, and unticking sets it to `assigned`.
- `up()` is safe to re-run: each of its three steps asks whether it is still needed, so a deploy
  killed halfway through can simply be run again. The backfill runs only in the same breath that
  creates the columns, never over drafts that already exist.
- **`down()` throws the drafts away.** The column goes, and with it the knowledge of which tasks
  were unfinished. A later `up()` — a rollback and re-deploy, `migrate:refresh` — finds no column
  and stamps every task `published`, which puts the drafts in front of the client. Roll this
  migration back only on an install whose drafts you are willing to publish, or export
  `published_status` first.
- The Control Panel accepts `assigned`, `in-progress` and `cancelled` for `status`. `completed` and
  `overdue` are derived — from the tick and from the calendar — and are refused there, because a
  typed `completed` would show the client "done" on a task nobody ticked. The facade still takes all
  five, for imports; an import that carries `completed` must carry its `done_at` too.

## 0.1.0 — 2026-09-02

First cut. One lasting room per coaching client.

### Added

- `client_rooms`, `client_room_tasks` and `client_room_files`, one migration. A room is keyed by
  e-mail address and brand; LeadHub's contact id is a link, never the key.
- Facade `ClientRooms`: `open()`, `close()`, `reopen()`, `forEmail()`, `forUser()`, `addTask()`,
  `completeTask()`, `reopenTask()`, `attach()`, `downloadUrl()`, `timeline()`. `open()` is idempotent
  and reopens a closed room.
- Listener on `statamic-payments`' `PaymentPaid`: a paid line of a product of a configured kind
  (`open_on_product_types`, default `sessions`) or handle (`open_on_products`) opens the buyer's room.
  Brand from the payment, else from the product. Never a hard dependency.
- Timeline in the room: LeadHub's merged `ContactTimeline` where LeadHub is installed and the room is
  linked to a contact; otherwise a short read-only list from the `payments` and `bookings` tables.
- Documents as Statamic assets in one container (`clientrooms:install` creates it, on a private disk
  by default), folder `room-<id>/`, a visible-to-client switch per file, and signed download links
  that expire after 30 minutes. The client never sees a storage path.
- Control Panel under **Tools → Clients**: listing on core's `Listing` (name, e-mail, status, open
  tasks, last activity, brand), detail page with tasks, documents, timeline, owner, notes for the team
  and notes for the client. Permissions `view client rooms` and `edit client rooms`. German and
  English, dark mode.
- Antlers tag `{{ client_room }}` for the signed-in user, plus `{{ client_room:exists }}`, and a
  starter view `statamic-clientrooms::room`. Internal notes never reach the template.
- Events `ClientRoomOpened` (with `reopened`), `ClientRoomClosed`, `ClientRoomTaskCompleted`.
- One asset container per brand on a multi-brand install (`clientrooms-<brandId>`), created by
  `clientrooms:install` and at the first upload; `allowed_extensions` checked at the endpoint and in
  `attach()`; the tag hands every free-text value over HTML-escaped; the owner picker lists staff
  only; a lost insert race picks up the winner's row; a room without a contact link looks it up once
  when its timeline renders.
