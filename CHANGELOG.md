# Changelog

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
