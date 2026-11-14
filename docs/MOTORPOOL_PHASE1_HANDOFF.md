# Motorpool Workspace — Phase 1 Handoff (WORK IN PROGRESS, NOT TESTED)

Checkpoint date: 2026-11-11
Source branch: `luis` · Working branch: `luis-maintenance-ui` (base commit `1aa7603`)
Status: **Phase 1 navigation is implemented and its automated tests pass (see 5b). The manual browser pass per role is NOT done yet.** Phase 1 changed navigation only, not the visual design: the UI redesign is Stage A / B of `docs/MULAWIN_UI_WIREFRAME_IMPLEMENTATION_SPEC.md`. Do not merge, deploy or push this.

---

## 1. Project goal
Build two separate, fully working role-based workspaces in Mulawin FleetOps V2, connected to Operations and Accounting:

1. **Motorpool Custodian:** Maintenance Overview, Repair Management, Mechanic Masterlist, Mechanic Attendance, and Parts Requests.
2. **Purchasing Officer:** Purchasing Overview, Parts Requests, Purchase Orders, Inventory, and Stock Movements.

The target UI is an interactive fleet dashboard with KPI cards, actionable queues, modal forms, status tracking, transaction history and linked records. It must keep the existing PHP, vanilla JS, Bootstrap and MySQL stack on XAMPP and the existing FleetOps theme.

## 2. Approved plan (summary)
The full plan is in `C:\Users\Kent Emmanuel\.claude\plans\pasted-content-id-7c36-we-are-lexical-stallman.md`.

| Phase | Scope | Status |
|---|---|---|
| 1 | Motorpool workspace navigation: Parts Requests page and Mechanic Attendance page, menu order, legacy links, notification link targets | **In progress (this checkpoint)** |
| 2 | Motorpool UI: Overview KPIs and Repair Management filters | Not started; needs approval |
| 3 | Purchasing workspace UI: drawers, filters, history | Not started; needs approval |
| 4 | Accounting fixes G5, G7, G8, G9 (PO payment status, budget period, budget renewal, incident cost trip_id) | Not started; **needs separate approval** |
| 5 | Purchasing reports and reminders (optional) | Not started; needs approval |

**Execution rules (agreed):**
- One phase at a time. Phase 1 is tested and committed before anything else.
- At the end of a phase, report what changed and stop for approval.
- No mock data and no fake buttons. Every action calls an existing authorized backend action.
- Existing business rules stay as they are. Phase 1 makes no financial changes.

## 3. Phase 1 scope and acceptance criteria
- **Motorpool menu order:** Overview, Repair Management, Parts Requests, Mechanic Masterlist, Mechanic Attendance, Maintenance History, Truck Masterlist. The landing page stays the Overview.
- **New page `mp_parts_requests`:**
  - Motorpool only.
  - Shows KPI cards, status tabs, a filter (status and truck), the Request parts form, Details, and Cancel (Cancel only while the request is still "Requested").
  - Shows **no** unit cost, stock quantity, stock value, budget, supplier or purchase-order data.
- **New page `mechanic_attendance`:**
  - Same roles as the Mechanic Masterlist: admin, operations_head, motorpool, audit.
  - Shows a board of who is on duty with Time In / Time Out, KPI cards, and session history for a date range.
  - Writes only through the existing `Mechanics.time_in` and `Mechanics.time_out`, which allow Admin and Motorpool only.
  - Operations Head and Audit see the page read-only.
- **Purchasing menus** are unchanged. Motorpool is still blocked from every Purchasing and Finance page.
- **Notification link targets only:**
  - PO approval now links to `purchase_orders` (it was `purchasing`, which is not a real page).
  - A processed parts request now links to `mp_parts_requests` (it was `maintenance`).
- **Old links keep working:** `#purchasing` (Motorpool) and the old `maintenance#_=parts` remembered tab both redirect to `mp_parts_requests`.

## 4. Changes in this checkpoint

| File | Change | State |
|---|---|---|
| `backend/Core/Access.php` | New pages `mechanic_attendance` (Masterlists section) and `mp_parts_requests` (Maintenance section). Menu section entries for motorpool, operations_head and audit. | Done |
| `backend/Modules/Maintenance/Purchasing.php` | (1) `read()` routes `mp_parts_requests` to the new `motorpoolPage()`, which calls `requireRole('motorpool')` and returns requests, a status summary, trucks and open jobs. (2) `myRequests` SQL moved into `myRequestRows()`, unchanged except that `r.vehicle_id` is added. (3) Two notification page targets changed. **No change to stock, PO, budget or payment logic.** | Done |
| `backend/Modules/Maintenance/Mechanics.php` | New read-only `attendancePage()`: board, sessions (range limited to 62 days), `range`, `can.edit`. The daily Present/Absent mark is removed for every role except Admin. No change to the write actions. | Done |
| `public/assets/js/modules/maintenance/maintenance.js` | (1) New `MpParts` and `App.page('mp_parts_requests')`. (2) The Repair Management "Parts requests" tab is removed and replaced by a toolbar link; the old remembered tab redirects to the new page. (3) Overview links now go to `mp_parts_requests?status=open` / `status=not_available`, plus a Mechanic attendance quick link. | Done |
| `public/assets/js/modules/maintenance/mechanics.js` | New `MechAtt` and `App.page('mechanic_attendance')`. It reuses `MechUI.toggle`, `MechUI.open` and `AttendanceUI.open('Mechanic')` (Admin only). | Done |
| `public/assets/js/core.js` | Motorpool sidebar order and short names; the legacy `#purchasing` link now sends Motorpool to `mp_parts_requests`. | Done |
| CSS | None added. The pages use existing classes. | Not needed so far |

**URL filter keys:**
- `mp_parts_requests?status=` takes `open`, `requested`, `partially_released`, `not_available`, `released` or `cancelled`, plus `vehicle_id=`.
- `mechanic_attendance` takes `duty=in` or `duty=out`, plus `from=` and `to=` (YYYY-MM-DD).

## 5. Checks already passed
- `node --check`: `core.js`, `maintenance.js` and `mechanics.js` all pass. Each new JS block was also checked on a scratch copy before it was applied.
- `php -l`: `Access.php`, `Purchasing.php` and `Mechanics.php` all pass.
- Read-through checks against the code:
  - The helper signatures are used correctly: `UI.filterModal(ctx, fields, opts)`, `UI.table`, `UI.tabs`, `UI.statusStrip`, `UI.tool`, `UI.btn`, `UI.confirmAction`, `UI.takeQuery`.
  - `AttendanceUI.open(kind)` exists.
  - The `mechanics?availability=` filter exists.
  - `MechUI.toggle(m, btn)` matches the `UI.table` `onAction(act, row, btn)` call.
  - `time_in` and `time_out` call `requireRole(admin, motorpool)`.

## 5b. Automated verification (2026-11-11)
All database suites ran through `tests/_launcher.php` on disposable `*_test` databases only (`mulawin_v2` is refused by name and never connected).

| Suite | Result |
|---|---|
| `workspace_access_tests.php` (no database, new section C2) | 256 passed, 0 failed |
| `run_motorpool_workspace_tests.php` (new; real `Actions` dispatch per role) | 115 passed, 0 failed |
| `run_maintenance_overview_tests.php` | 105 passed, 0 failed |
| `run_mechanic_attendance_tests.php` (after the launcher fix below) | 77 passed, 0 failed |
| `run_fund_release_tests.php` | 70 passed, 0 failed |
| `run_billing_adjustment_tests.php` | 173 passed, 0 failed |
| `run_crew_dispatch_tests.php` | 127 passed, 0 failed |
| `run_crew_attendance_tests.php` | 69 passed, 0 failed |
| `run_trip_schedule_tests.php` | 95 passed, 0 failed |
| `run_trip_documents_tests.php` | 58 passed, 0 failed |
| `run_trip_routing_tests.php` | 116 passed, 0 failed |
| `run_phase2_migration_safety_tests.php` | 93 passed, 0 failed |
| `run_trip_batch_tests.php` (after the stale-label test fix below) | 65 passed, 0 failed |

- **Launcher fix (Windows):** `_launcher.php` read the base schema with `git show <sha>^:...`; `cmd.exe` drops the unquoted `^`, so the schema AFTER the migration was loaded and every migration-upgrade section crashed (duplicate `schema_migrations` key). Now `escapeshellarg("<sha>~1:...")`. Verified: identical to the parent commit's files; the safety checks are unchanged.
- **trip_batch stale label (pre-existing, fixed in the test only):** the test looked for the old button text `Retry not-created row(s)`; commit `b0bd1b7` (2026-11-09, before Phase 1) renamed it to `Retry not-created rows (N)` in `trips.js:430`. The test now expects `Retry not-created rows (`; the application code is unchanged and the behaviour it guards is intact (`trips.js:417-419`).
- **Concern 6 resolved:** `db()` sets the MySQL session time zone from PHP (`bootstrap.php:76`); the test confirms minutes on duty ≈ 0 right after Time In.
- Not run (Phase 1 does not touch their code): `run_map_tests`, `run_route_tests`, `run_account_security_tests`, `run_htaccess_tests`.

## 6. Pending and known concerns
1. **`tests/workspace_access_tests.php` is not updated.** The proposed edit was rejected when work paused, not on its merits; it is not in this checkpoint. It added a section "C2" with checks on:
   - the new pages per role;
   - the sidebar order;
   - the legacy redirect;
   - the notification targets;
   - the source of the scoped reads;
   - the daily mark being Admin-only;
   - that the request list isn't duplicated.
   These are source checks. **Runtime authorization and data-exposure tests are still needed** in a database test (see 7.3).
2. **None of the database test suites has been run** since these changes.
3. Possible effect on existing tests:
   - `mechanic_attendance_tests.php` calls `Mechanics::read($mp, 'mechanics', ...)` and `Maintenance::read`. These should be unchanged, but this needs confirming.
   - `maintenance_overview_tests.php` has no JS or link assertions.
4. **Old notification rows** with page `maintenance` or `purchasing` still open valid pages. Not yet checked in the browser.
5. **The `mp_parts_requests` list is limited to 300 rows** (the existing `myRequestRows` LIMIT). The KPI summary counts every row, so with more than 300 requests the card counts can be larger than the list.
6. **The "On duty" minutes** use the database server's `NOW()`. If PHP and MySQL use different time zones, the display may be off. Check this in the browser.
7. **`Actions::run` and `currentUser()` cache the user in a static variable**, so one CLI test process can't switch users through the API layer. Runtime role tests must call `Access::canOpen` together with `Class::read` / `Class::action`, the same way `Actions::pageData` does.

## 7. Next tasks when we resume (in order)
1. Review this checkpoint: `git show --stat HEAD`.
2. Re-propose the `workspace_access_tests.php` C2 section for approval, then run it. It needs no database:
   `C:\xampp\php\php.exe tests\workspace_access_tests.php`
3. Write `tests/motorpool_workspace_tests.php` and the launcher `tests/run_motorpool_workspace_tests.php`. Model them on `run_maintenance_overview_tests.php` (isolated database `mulawin_mpws_fresh_test`). Cover:
   - **Runtime access:** for every role, `Access::canOpen` for `mp_parts_requests` and `mechanic_attendance`. `Purchasing::read(<non-motorpool>, 'mp_parts_requests')` returns 403.
   - **Data exposure:** recursively scan the `mp_parts_requests` payload for the keys `unit_cost`, `quantity_on_hand`, `stock_value`, `budget`, `supplier*`, `po_*` and `total_amount`. None may appear.
   - **Parts request round trip:** `request_create` → `request_process` (partial release, then Not Available) → `request_cancel`. Statuses, audit rows and summary counts must match the list. The notification `page` must be `mp_parts_requests`.
   - **PO notification target:** `po_create` writes a notification with page `purchase_orders`.
   - **Mechanic Attendance read:** board counts equal the masterlist availability counts. `daily_mark` is present only for Admin. A session from another date range is excluded. A range longer than 62 days is clamped.
   - **Time In/Out refusals:** operations_head and audit get 403; Time Out during an active repair gets 409; a duplicate Time In gets 409. Availability is unchanged after a refusal.
4. Before the first database test run, re-read the guard lines 8, 35–39 and 84–88 in `tests/_launcher.php`, then run with `TEST_DB_USER` / `TEST_DB_PASS`:
   - `run_motorpool_workspace_tests`
   - `run_maintenance_overview_tests`
   - `run_mechanic_attendance_tests`
   - regression: `run_fund_release_tests`, `run_billing_adjustment_tests`, `run_trip_*`, `run_crew_*`
5. Add the new suites to `tests/README.md`.
6. Manual browser pass as motorpool, purchasing, finance, admin, audit and operations_head:
   - menus;
   - every KPI card and link;
   - Request parts, Details and Cancel;
   - Time In and Time Out, including the refusals;
   - legacy links;
   - screen widths 400, 768 and 1280px;
   - no console errors.
7. Once everything passes: a **final Phase 1 commit**, then the report (modified files, test output, commit hash, remaining issues). **Stop for approval before Phase 2.**

## 8. Restrictions (still in force)
- No production database changes, migrations, data resets or `.env` edits.
- No merges and no push to GitHub without explicit approval.
- Work stays on `luis-maintenance-ui`.
- No Phase 2, 3, 4 or 5 without explicit approval. Phase 4 (financial) needs its own approval.
- Every edit and command is shown for manual approval.

## 9. Resume commands
```
cd C:\xampp\htdocs\RP_Mulawin_FMS_V2
git checkout luis-maintenance-ui
git log --oneline -3
git show --stat HEAD
git status
```
