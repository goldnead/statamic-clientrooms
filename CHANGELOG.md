# Changelog

## 0.8.0 — 2026-09-07

### Neu: acht Werte der Klientenräume im Control Panel

Unter **Einstellungen → Addon-Einstellungen** steht ein Abschnitt für dieses Addon, mit vier
Gruppen:

- **Dateien im Raum:** die erlaubten Dateiendungen (geprüft beim Upload und noch einmal beim
  Anhängen; eine Endung zu streichen sperrt nur neue Uploads, was schon im Raum liegt, bleibt
  liegen), die Höchstgröße je Datei für die Mitglieder-Schnittstelle, und die Laufzeit eines
  Downloadlinks. Ein verkürztes Fenster entwertet keine bereits verschickten Links rückwirkend,
  es gilt ab dem nächsten Aufbau des Raums.
- **Aufgaben:** die Arten, die die Aufgabenmaske anbietet, und die einzigen, die sie annimmt.
  Eine Art zu streichen ändert keine bestehende Aufgabe, sie lässt sich danach nur nicht mehr
  neu vergeben.
- **Räume, die sich von selbst öffnen:** die Produktarten und die einzelnen Produkte, bei denen
  eine bezahlte Zahlung einen Raum öffnet, und der Besitzer, den ein so geöffneter Raum bekommt.
  Der Besitzer wird beim Öffnen eingetragen und ändert bestehende Räume nicht.
- **Zeitachse:** wie viele Ereignisse ein Raum zeigt. Gelöscht wird dabei nichts.

Gespeichert wird nur die Abweichung, alles andere folgt weiter
`config/statamic-clientrooms.php`.

Nicht auf der Seite: `container` und `disk`, weil ein Wechsel die Dateien erst bewegen muss und
ein Feld, das nur den Zeiger umlegt, aus jedem bestehenden Raum einen leeren macht. Dafür ist
`php please clientrooms:install` da. Ebenfalls draußen: `member_api`, das beim Registrieren der
Routen gelesen wird und hier erst nach dem nächsten Deploy wirken würde.

**Neues Recht `manage clientrooms settings`.** Bis es einer Rolle zugewiesen ist, sieht den
Abschnitt niemand. Bestehende Rechte sind unverändert.

**`goldnead/statamic-brand-context` bleibt weich gebunden, jetzt aber mit Mindestfassung
1.13.** Das Addon läuft einmarkig weiter ohne den Nachbarn; dann meldet sich diese Seite nicht
an, und die Werte stehen wie bisher in der Config. Wer ihn hat, braucht 1.13: unter älteren
Fassungen wurden die Einstellungen der zuletzt angemeldeten Addons auf einer Installation mit
einer einzigen Marke gar nicht angewendet, und bis 1.12 löschte ein zweites Speichern desselben
Abschnitts die Überschreibung des ersten, ohne Meldung. Bis hierher stand im `suggest` keine
Nummer, ein Käufer landete also auf 1.12.

## 0.7.2 — 2026-09-05

Das ausgelieferte Bundle war älter als die Quelle, aus der es stammen sollte.

### Fixed

- **`dist/` zur Quelle nachgezogen.** Der Commit `6dc08a9` (Status-Badges als Pille, Icon
  repariert) änderte `resources/js`, das committete `dist/build` blieb aber auf dem Stand vom
  Vormittag davor. Wer 0.7.1 installierte, bekam PHP von 0.7.1 und JavaScript von 0.7.0 — genau
  die Klasse Fehler, die in der Suite am 03.09. „Cannot read properties of undefined" ausgelöst hat.
  Der frische Build aus der committeten Quelle ist byte-identisch mit dem, was lokal schon
  ungeprüft im Arbeitsverzeichnis lag; committet ist jetzt dieser Stand.
- **Status-Badges als Pille, Icon repariert.** 13 Badges hatten `size="sm"` ohne `pill`; mit Rahmen
  und 3px-Ecke sah das aus wie ein beschnittener Knopf. `Icon name="file"` gibt es im Kern nicht.
  (Quelle seit 0.7.1 im Repo, Bundle erst jetzt.)
- **CI lief nie.** Das Repo ist privat, der Workflow hatte `permissions: {}` — damit konnte
  `actions/checkout` das eigene Repo nicht lesen und jeder Job war rot, bevor ein Test lief. Jetzt
  `contents: read`. Der `dist`-Job dieser CI ist die Prüfung, die den Fehler oben abgefangen hätte.

## 0.7.1 — 2026-09-03

### Fixed

- **Innenabstand links, gleich hohe Zeilen, Aufzeichnung als Zeichen.** Die Karte trägt kein
  Polster, damit die Trennlinien durchlaufen; dadurch klebte das Datum an der Kante, jetzt `ps-4`
  auf der ersten und `pe-4` auf der letzten Spalte. „Aufnahme (Link abgelaufen) · Transkript (kein
  Link)" brach über drei Zeilen; jetzt zwei Zeichen in einer Zeile, der Tooltip trägt den Zustand,
  ausgeschrieben steht es im Stack. Das Entwurfs-Abzeichen ist raus, der Schalter daneben sagt
  dasselbe.

## 0.7.0 — 2026-09-03

Die Sitzungsliste sah nicht nach Statamic aus.

### Changed

- **Die Liste ist jetzt eine `Table` des Kerns**, keine handgebaute `<ul>`. Eine Sitzung ist ein
  Datensatz mit Datum, Dauer und Zustand, und das Control Panel hat einen Weg, Datensätze zu zeigen:
  Spaltenköpfe, ausgerichtete Spalten, Datum in Ziffernbreite. Die alte Liste war dem Aufgaben-Panel
  nachgebaut — aber Aufgaben sind ein Zettel zum Abhaken, Sitzungen sind eine Kartei.
- **Das Ausführliche liegt im `Stack`**, nicht mehr in einer aufklappenden Zeile. Agenda,
  Zusammenfassung, Protokoll und die eigene Notiz bekommen dort Platz, ohne dass die Liste
  auseinanderfällt; der Stack ist die Stelle, an der das Control Panel seit jeher das Einzelne zeigt.
  Der Sichtbar-Schalter steht in seinem Fuß, neben dem Speichern-Knopf.
- Spaltenkopf ist `Sichtbar`, nicht `Für Klient sichtbar` — der lange Text brach dreizeilig um und
  drückte die Löschen-Spalte aus der Karte.

### Removed

- `session_protocol_show` / `session_protocol_hide` — es gibt nichts mehr auf- und zuzuklappen.

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
