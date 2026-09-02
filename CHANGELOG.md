# Changelog

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
