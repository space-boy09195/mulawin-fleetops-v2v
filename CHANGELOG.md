# Changelog

## 2026-11-10 (Role workspaces: Maintenance and Purchasing)
- **Motorpool Custodian** lands on the existing **Maintenance Overview** (unchanged apart from its links). Sidebar: Maintenance
  Overview, Repair Management (`#maintenance`), Truck Masterlist, Mechanic Masterlist, Maintenance History (new, completed and
  cancelled records with cost and attachment count). Parts requests are made and tracked inside Repair Management (new "Parts
  requests" tab and "Request parts" button): Motorpool sees an In stock / Low stock / Out of stock label and requested versus
  released units, and no costs, stock counts, budget, purchase orders or stock movements.
- **Purchasing Officer** lands on **Purchasing Overview**. The combined "Parts & Purchasing" page (`#purchasing`) is replaced by
  Parts Requests, Purchase Orders, Inventory, Stock Movements and Purchasing Reports; each page loads only its own data. Finance
  keeps Purchase Orders and Purchasing Reports; Admin and Audit keep read access. Approval routing, budget checks and receiving are
  unchanged. Old `#purchasing` links and notifications redirect to the user's page.
- The company Dashboard stays for the other roles; Motorpool and Purchasing no longer have it. The inventory section moved from
  Reports to Purchasing Reports. The Maintenance Overview parts data is now limited to Motorpool, Admin and Audit. Purchasing no
  longer sees the Truck Masterlist, repair documents or the general Reports page; Motorpool no longer sees purchase-order
  documents. No database change. Tests: `php tests/workspace_access_tests.php` (no database needed);
  `tests/run_maintenance_overview_tests.php` was updated for the new menu.

## 2026-11-10 (Maintenance Overview, Phase 1)
- New **Maintenance Overview** page (`#maintenance_overview`), first in the Motorpool Custodian's menu: four counts
  (New reports, Under repair, For verification, Pending parts requests), **Needs attention** (records waiting for an action:
  assign a mechanic, assess an incident, verify a completed repair, parts Not Available), quick actions (the existing
  Report a truck problem form, Repair Management, completed repairs, parts inventory) and **Recent maintenance activity**
  (from the existing repair status history, parts requests and stock-out releases). Read-only; same roles as Corrective
  Maintenance; parts data only for roles that may open Parts & Purchasing.
- Motorpool menu wording: Overview, Repair Management (`#maintenance`, unchanged), Parts Inventory (`#purchasing`, unchanged).
  Repair Management accepts `?view=reported|in_progress|verify` to narrow the Open records list. Existing links still work.
- No change to repair statuses, verification, truck availability, incidents or permissions. No database change.
  Tests: `tests/run_maintenance_overview_tests.php`.

## 2026-11-10 (Authentication: no email OTP, Admin-generated temporary passwords)
- **Login is now username (or email) + password only.** The emailed login code, its screen, endpoints and the "Trusted devices"
  feature (which only existed to skip that code) are removed. Kept: password hashing, 5-wrong-password account lock and per-session
  limit, CSRF, session-id regeneration at login, logout, idle timeout, sessions signed out after any password change.
- **New accounts:** the Admin no longer types a password. The system generates a random one-time temporary password
  (`random_bytes()`, 16 characters), stores only its `password_hash`, and shows it to the Admin **once** after saving.
  It is never emailed, logged or audited. **Reset password** (Admin only, not for yourself) generates a new one the same way;
  an existing password can never be viewed.
- **First login:** accounts created or reset this way are flagged `must_change_password`. The server blocks every page, module,
  document and API call until the user replaces the temporary password (`Admin.password_change_force`: temporary + new password,
  no email code); the flag is cleared only after the new hash is saved, then the user lands on their role's start page.
  Existing accounts are NOT flagged and keep working.
- Normal (voluntary) password change and email change keep their emailed verification codes. Audit: account created,
  password reset by Admin, password changed at first login.
- While a password change is pending the server also withholds the menu, notifications and settings (`?api=me`), email change,
  and the test-data loader page. `automation/*.py` now use the new login and read the one-time password from the Admin screen.
- No database change. Tables `login_otps` and `trusted_login_devices` stay in the database, unused. Tests: `tests/auth_no_otp_tests.php`.

## 2026-11-08 (Batch trip planning)
- **Trips → Batch planning**: enter up to 20 trips on one page. Every row becomes its own trip with its own number, using
  the same rules as New trip (auto-assignment or Awaiting Resources for the Operations Head; Pending Review requests for a
  dispatcher). Nothing is created if any row is wrong (each problem is listed by row); sending again never makes
  duplicates, while identical-looking rows (several trucks for the same route and time) are allowed. A result is shown
  for every row; a row that fails while saving can be retried alone. No database change.

## 2026-11-08 (Checklist #44: Diesel PO and Supplier SOA attachments)
- Trip Details: the dispatcher can attach the **Diesel PO** (optional). Complete Trip: optional **Supplier SOA** next to the
  Waybill, Acknowledgement Receipt and DR (which stay required). Both are trip documents in Trip Details and the Documents
  page; Finance can view and download them. Attachments only — no new approval or payment process. No database change.

## 2026-11-08 (Checklist #42 Phase 2: crew attendance at dispatch)
- New Admin setting **Crew attendance check at Confirm Dispatch**: **Warn** (default) or **Enforce**. Enforce can only be
  turned on when every driver and helper has a shift; the Admin sees a readiness list first.
- Auto-assignment picks crew from the trip's scheduled shift and never picks crew marked Absent; in Warn mode it may use
  other-shift crew when needed, clearly flagged in the trip history and to the Operations Head.
- Enforce: Confirm Dispatch / Redispatch needs every driver and helper Present on their own shift at the actual dispatch
  time (Philippine time). Warn: dispatch allowed, missing attendance noted. The Operations Head is notified when assigned
  crew are marked Absent. Car Carrier trips are assigned only when two drivers are available (otherwise Awaiting
  Resources); every trip needs its full crew before Confirm Dispatch. No database change. Tests: `tests/run_crew_dispatch_tests.php`.

## 2026-11-08 (Checklist #42 Phase 2 preparation: crew attendance roster)
- Admin attendance for drivers now includes **helpers** ("Crew Attendance"); Admin assigns a Day/Night shift to helpers
  as well. Crew without a shift are listed in the attendance window and in the Admin bell so they can be backfilled.
  Trips, auto-assignment and Confirm Dispatch are unchanged in this step. No database change.
  Tests: `tests/run_crew_attendance_tests.php`. Rollout plan: `docs/CLAUDE_42_PHASE2_ATTENDANCE.md`.

## 2026-11-08 (Checklist #36 / #37: cash-only trip fund release, Trip Fund tabs)
- **Trip fund release is cash only** and the release date is the server's date (cannot be changed or backdated). The
  release window, voucher, expense and audit trail are unchanged; other payments (Billing, Finance, Purchasing) still offer
  all payment methods. Direct API calls with another method or date are refused.
- **Trip Funds tabs**: "Ready for release" is now **To Release**; "For liquidation" keeps the Liquidate action.
  ("All requests" was already removed.) No database change. Tests: `tests/run_fund_release_tests.php`.

## 2026-11-08 (Checklist #38 / #40: trip schedule restrictions)
- **Pre-dispatch checklist** opens 2 hours before the scheduled dispatch and **Confirm Dispatch / Redispatch** 15 minutes
  before (both set by the Admin in Settings). Planning and approval (create, review, edit, assignment, fund request and
  approval, reschedule, cancel) stay allowed any time. Enforced on the server; the trip screen shows the opening times and
  why a button is disabled. A late dispatch is allowed and noted in the trip history. No database change.
  Changing the schedule of a checked trip keeps the checklist if the new time is still inside its window; otherwise the
  trip goes back to Pre-Assigned for a new checklist (assignment, fund and distance kept). Confirm Dispatch now checks
  that the checklist exists for the current attempt and the assigned truck.
  Tests: `tests/run_trip_schedule_tests.php`. Details: `docs/CLAUDE_38_40_TRIP_TIME_GATES.md`.

## 2026-11-07 (Checklist #5 Phase 3: road distance in Trip Management)
- **New trip / Review request / Edit details**: choose an **approved client route** (client + truck type) or pin the points
  and pick a route option; the road km fills in (2 decimals). Changing the km needs a reason; above 15% a warning is shown.
  The server checks the route (Approved, approved rate, Active client, same truck type, dispatcher team) and computes the
  road distance again before saving. Without the map service the typed km is saved as Manual, as before.
- **Trip details** show the distance source, the computed / route km, the route reference, the reason and the road line.
- **Billing unchanged**: still km × client rate per km. The route rate is not used yet (business decision pending).
- Database: `database/migrations/2026-11-07_07_trip_routing.sql` (7 columns in `trips`, new table `trip_routes`; existing
  trips become `Manual`). Tests: `tests/run_trip_routing_tests.php`. Details: `docs/CLAUDE_05_PHASE3_TRIP_ROUTING.md`.

## 2026-11-06 (Checklist #5: OpenStreetMap and automatic road distance; Phase 1 + 2)
- **Maps use OpenStreetMap** (MapTiler removed). Address search and map-point addresses go through the server
  (openrouteservice geocoding); the browser never sees an API key. The map code moved from `core.js` to `assets/js/map.js`.
- **Client Routes > Request route**: pin origin and destination; the road distance (openrouteservice, truck profile) fills in
  the km and the road line is shown. Route options: Recommended (default), Avoid toll roads, General roads only.
  The km keeps 2 decimals. Changing the km needs a reason; a difference above 15% shows a warning. The server computes the
  distance again before saving. Without `ORS_API_KEY`, or when the service does not answer, the km is typed (Manual).
- **Trip Management is unchanged** (straight-line guide, typed billable km); only the map tiles and the search provider changed.
- Database: `database/migrations/2026-11-06_04_maps_routing.sql` (map cache and usage tables; 6 nullable columns in
  `client_routes`). Settings: `ORS_*`, `MAP_TILE_*`, `MAP_USER_PER_MINUTE` in `.env.example`.
- Tests: `tests/run_map_tests.php`. `tests/run_route_tests.php` now checks the whole migration chain (it broke when
  `2026-11-06_03_audit_changes` was added). Details: `docs/CLAUDE_05_OSM_ROUTING.md`. Implementation defaults only: the
  company still has to confirm the kilometer billing and toll-road policies.

## 2026-11-06 (Checklist #20: client routes, provisional policy)
- **Client Routes** (Masterlists): approved routes per client and truck type, with contractual rates. Operations Head
  requests a route (origin to destination is one direction; same route can have another rate for another truck type),
  Billing enters the rate, Admin approves or rejects. Only Approved routes will be selectable in planning.
- **Rate revisions** create a new version; the old approved rate stays in use until the Admin approves the new one, and
  is then kept as Superseded. A rejected revision does not affect the route or its current rate. Approved rates are
  never edited. The person who entered a rate cannot approve it.
- **Client Masterlist**: client details show a read-only "Approved routes" section.
- Duplicate routes (same client, truck type, origin and destination, ignoring case, spaces and punctuation) are refused
  by the database, also when two users submit at the same time.
- Not connected yet to Trip Management or billing: billing still uses the client billing rate per km (`client_rates`).
- Database: `database/migrations/2026-11-06_02_client_routes.sql` (new tables `client_routes`, `client_route_rates`,
  `client_route_events`; nothing existing is changed). Tests: `tests/run_route_tests.php`.
  Details, install, rollback: `docs/CLAUDE_20_CLIENT_ROUTES.md`.

## 2026-11-06 (Phase 1: validation and purchasing)
- **Supplier typed in the purchase order.** The PO form has Supplier name (required), address, contact person,
  contact number and email (optional) instead of the supplier dropdown. The details are saved IN the purchase order
  (snapshot), so the PO prints and is paid with the details it was approved with. Suppliers used before are suggested
  and fill in their details; no supplier record is created. The Suppliers tab is hidden; the `suppliers` table is kept
  for old purchase orders. Finance payables, PO payment (voucher and expense payee), PO printing and the
  partially-received report read the snapshot first. Finance "Add payable": the supplier dropdown is removed (Payee is typed).
- **Trip schedule.** New trips, new dispatcher requests, changed schedules and reschedules must be the current date
  and time or later (Philippine time, 5-minute grace for filling in the form) and at most 365 days ahead. Before,
  up to one day in the past was accepted. Editing a trip that is not dispatched yet without changing its schedule
  still saves, with a warning that the schedule has passed. The date picker uses Philippine time, not the PC clock.
- **Duplicate trips blocked.** Each New Trip form has a key; sending the same form twice creates one trip.
- **Validation fixes:** a driver always needs the license number and expiry (also on edit, and when a helper is changed
  to driver); map pins must be complete and inside the Philippines; billable distance up to 3,000 km with at most
  2 decimals; quantities up to 100,000 (no more database errors on huge numbers); phone numbers checked for clients,
  drivers/helpers and suppliers (7 to 15 digits); passwords at most 72 characters (the hash ignores the rest).
- **Confirm password checked on the server:** new account, Admin "Set new password" (new confirmation field),
  own password change and Admin recovery. The confirmation is never stored.
- Database: `database/migrations/2026-11-06_00_schema_migrations.sql` (record of applied migrations; a migration
  imported twice now stops before changing anything) and `2026-11-06_01_phase1_supplier_snapshot_and_trip_key.sql`.

## 2026-11-05 (checklist timer)
- **Minimum checklist duration** (PDF #11 section G, "such as 15 minutes"), for both the pre-dispatch and the arrival
  checklist. The timer starts on the server when the dispatcher opens the checklist (closing and reopening does not
  reset it). The Submit button shows a countdown and stays disabled until the time has passed, and the server refuses
  an early submission even through the API. Start time is saved with the checklist; trip details show how long it took.
- **Editable by the Admin**: User Management > Settings > "Minimum checklist duration" (0 to 120 minutes, 0 = off,
  default 15 from `config/formulas.php`). Changes are written to the activity log.
- Database: `database/migrations/2026-11-05_checklist_timer.sql` (table `checklist_starts`, column `inspections.started_at`).

## 2026-11-05 (arrival checklist, PMS removed)
- **Arrival checklist** (PDF #11 section G; Car Carrier step "Complete the arrival checklist"). After **Complete Trip**
  the billing still moves to For Billing and the driver and helper are free at once, but the **truck stays On Trip**
  (it is driving back) until the trip's dispatcher submits the arrival checklist. Same 57 items as the pre-dispatch
  checklist; the result before departure is shown beside each item, and the trip details compare odometer, fuel
  and the items that changed since departure.
  - All OK: the truck is released (Available) and waiting trips are assigned automatically.
  - NOT OK: a description and photos are required; a corrective maintenance record is opened (or the findings are
    added to the truck's open record) and the truck becomes Under Maintenance.
  - While a truck is returning, it cannot be auto-assigned, set Unavailable or reported from the garage.
- Database: `database/migrations/2026-11-05_arrival_checklist.sql` (existing databases). `extension.sql` updated for new installs.
- Preventive maintenance removed for good: the unused `pm_interval_km` and `pm_auto_hold` settings were deleted from `config/formulas.php`.
- Security: `.gitignore` ignores `APP_ENV.txt` and credential files; `.htaccess` blocks `.txt`, `.md`, `.csv`, `.json`, `.py`, `.bat` and every `.env*` file from the web.

## 2026-11-04 (test data loader)
- New `scripts/seed_test_data.php`: fills the masterlists completely for testing, in one command
  (`C:\xampp\php\php.exe scripts\seed_test_data.php`). Imports `database/seed_masterlists.sql` when the database is empty,
  then completes every truck (details, regular driver and helper, OR/CR and insurance files), driver and helper
  (employee no., contact, license), client (information sheet, the 5 required files, rates -> Active), mechanics,
  parts inventory with opening stock, suppliers and a purchasing budget. Attachments are real PDF/PNG files in
  `storage/private`, labelled "SAMPLE DOCUMENT - TEST DATA".
- Adds special test cases (expired license, people on leave, inactive records, truck Unavailable, truck under repair,
  repair waiting for verification, regular driver on leave, expiring registration, mechanic on leave, clients with
  missing files / no rate / one rate, low and zero stock).
- Writes a test basis report in `storage/test_data/` with the expected automatic assignment order per truck type,
  what each special case should do, and the full masterlists. The expected order was checked against the real
  automatic assignment (18 of 18 trips matched) on a fresh database.
- Safe to run again: only adds what is missing, never deletes or overwrites, never creates trips, money records or accounts.
- New `public/seed_test_data.php` for hosting without SSH: works only while `SEED_TEST_TOKEN` (32+ characters) is set in
  `.env` and the Admin is logged in. `.env.example` gained that optional line; `.gitignore` gained `storage/test_data`.

## 2026-11-04 (revision 2: email login codes, e-signature, corrective maintenance, mechanics, map, redesign, demo scripts)
Source: "Consolidated additional project requirements". Decisions confirmed by the team: Gmail + App Password for the
login emails, the operator types the login codes in the scripts, the Admin sets new users' passwords, MapTiler free plan
for the map.

**Install / upgrade**
- New install of the database is required (new tables and columns). Import, in order: `database/schema.sql`,
  `database/extension.sql`, optionally `database/seed_masterlists.sql` (now also has 8 fictional mechanics).
- `.env.example` gained the email settings (`MAIL_*`) and the map settings (`MAPTILER_API_KEY`, `MAPTILER_STYLE`).
  This configuration file had to change because the new features cannot work without these values; nothing else in it changed.
  Copy the new lines into your `.env`. Passwords and keys are never written in the code.
- `.gitignore` gained the private files of the new automation scripts (config, saved passwords, sessions, reports,
  sample attachments) so they are never committed.
- No file in `docs/`, `guide/`, `README*.md` or `tests/` was changed. The new beginner guides A-M are shared as separate documents.

**1. Login with an emailed code (OTP)** - replaces the temporary-password step
- Step 1 username/email + password, step 2 a 6-digit code sent to the user's email (PHPMailer 6.9.3 vendored in
  `backend/vendor/PHPMailer`, free Gmail SMTP with an App Password). The session is signed in only after the code.
- Codes: 5 minutes, single use, stored hashed (`login_otps.code_hash`), a new code invalidates the old one,
  resend after 60 s, at most 5 unused codes per 15 minutes, 5 wrong codes cancel the code and count toward the
  account lock. No bypass; codes and passwords are never written to logs (only a masked email).
- `users.email` (required, unique); `must_change_password` removed. The Admin sets each new user's password.
- New `scripts/test_email.php` (sends one test email; prints only SENT or the reason). `scripts/create_user.php`
  and `scripts/reset_password.php` ask for the password with hidden typing (no temporary passwords).

**2. E-signature** - name menu > My e-signature: draw (mouse, touch, stylus; clear, redraw, preview) or upload PNG/JPG
  (max 2 MB, at least 60x20 px). Empty drawings are refused on the screen and on the server (ink check).
  The image is served only to signed-in users (`index.php?api=signature`) and printed above the signer's name on the
  Request Form, Purchase Order, Request for Cash Fund, Incident Report, Service Invoice, SOA and Acknowledgement Receipt.

**3. Corrective Maintenance replaces PMS** (preventive maintenance removed completely: reminders, last PMS date, setting)
- Statuses Reported -> Under Inspection -> Under Repair -> Completed -> (verification) returned to service; Cancelled for
  garage reports made by mistake. Every change is kept in `maintenance_events` (status history with mechanic and actor).
- Sources: garage report (photos required) or trip incident (created automatically; Minor/Major assessment chooses the mechanic).
- Truck availability: the truck is Under Maintenance from the report until a **Passed operational verification**;
  "Completed" alone never makes it available. A failed verification sends the record back to Under Repair.
- One unresolved record per truck and one active repair per mechanic, enforced by unique keys in the database.
- Parts requests, labor/outside costs and evidence files per record; dashboard shows trucks under corrective
  maintenance, open repair requests and recently completed repairs.

**4. Mechanic Masterlist** - ID (MEC-###), name, contact, specialization, availability (Available / Assigned /
  Unavailable with reason), active flag; add, edit, search, filter, activate/deactivate. Assignment is a dropdown of
  Available mechanics; an assigned mechanic cannot be set Unavailable or deactivated. No mechanic logins.

**5. Map (fixes the OpenStreetMap 403)** - cause: the `Referrer-Policy: same-origin` header (tile servers received no
  referrer) and the old `{s}.tile.openstreetmap.org` address. Now `strict-origin-when-cross-origin` and MapTiler tiles
  with the required attribution and logo; key and style come from `.env`. Place search and address lookup use MapTiler
  geocoding (Philippines, cached in the browser session, run only on Search/click). Without a key the map shows a notice
  and addresses are typed (manual fallback).
- New Trip Request is now its own page (Trip Management > New trip) with numbered sections: client and schedule,
  pickup and destination (map, search, pins, address confirmation), cargo details, assignment preview, attachments,
  and a live summary. Attachments are stored with the trip ("Trip Request Document").

**6. UI/UX redesign** - new design system (`layout.css`, `login.css`, `app.css`): dark sidebar in the order Dashboard,
  Trip Management, Truck / Driver / Client / Mechanic Masterlists, Corrective Maintenance, then the rest; status strips,
  bordered panels, tables with search and numbered pagination, one filter dialog per list (Apply / Reset), details
  drawers with next step and history, loading / empty / error states, responsive layout. Deep links such as
  `?open=` are used once and removed from the address so saving does not reopen the same form.

**7. Printable forms** - all forms use the company logo (`public/assets/img/logo.svg`/`.png`, redrawn from the low-
  resolution reference, so it is an approximation) and print CSS; blank rows like the paper forms. New printable
  Acknowledgement Receipt for client payments (amount in words, form of payment). Layouts follow PDF #13 closely but
  are not exact reproductions.

**8. Notifications** - states Unread / Read / Resolved / Archived (nothing is deleted), unread count on the bell,
  details, Resolve from the bell, Notifications history page, tasks resolve themselves when the work is done.

**9. In-app dialogs** - no browser alert / confirm / prompt anywhere (logout uses the in-app confirmation).

**10-17. Demonstration scripts** - new `automation/` folder (blocked from the web by `.htaccess`), Python + Playwright like `tests/`:
- `script1_init_accounts.py`: creates or checks the role accounts through the Admin screens (idempotent), passwords
  generated and saved only in `automation/.credentials.json`; optional login check of each new account.
- `script2_demo.py`: demonstrates every objective through the screens and re-checks each result from the server;
  fictional demo data with fixed identifiers (reused, never duplicated); unfinished work from an earlier run is continued;
  sample attachments are generated when missing; printed forms saved as PDF; report in `automation/reports/`.
- Results are PASSED (done and confirmed after reload), FAILED or SKIPPED (with the reason). Login codes are typed by
  the operator by default (`--otp-source mailpit` exists only for a local test computer).

**Verification done in the development workspace (honest status)**
- Email: the full login flow was tested against a local SMTP test inbox (Mailpit). Delivery through Gmail could not be
  tested from the workspace (no internet SMTP); run `php scripts/test_email.php you@gmail.com` on your PC.
- Map: the tile and search requests were tested with the MapTiler servers simulated; real MapTiler tiles could not be
  loaded from the workspace. Check on your PC with your key (guide E). Script 2 reports the map as SKIPPED without a key.
- `tests/system_test.py`, `tests/e2e_workflow.py` and `tests/formula_test.php` were not changed; they still test
  features removed in revision 1 (payroll, PMS) and fail at those steps.

## 2026-11-04 (system revision: masterlists, simplified trip workflow, objective alignment)
Reference files: PDF #14 (objectives), PDF #12 (workflow), PDF #11 (system requirements), PDF #13 (form formats).
Client dummy data: CAR CARRIER and CONTAINER MONITORING 2026 workbooks (only the fields the system needs).

**Database (new install required, see "Install / upgrade" below)**
- `database/schema.sql` and `database/extension.sql` rewritten (35 tables). Masterlists are central and every
  transaction refers to them by foreign key: `vehicles` (trucks), `personnel` (drivers and helpers), `clients`
  (+ `client_rates` per truck type). Unique keys on plate number, client company name, driver/helper name + type.
- Availability is enforced in the database, not only in the screen: table `trip_assignments` has one row per
  reserved truck/driver/helper (primary key = resource), so the same resource can never be on two active trips.
  CHECK constraints on statuses and amounts (stock cannot go below zero), foreign keys on all links,
  one checklist per trip attempt, one billing per trip, one fund request per trip attempt.
- New `database/seed_masterlists.sql` (optional): 29 trucks (15 Car Carrier, 7 Container, 7 Wing Van),
  62 drivers, 18 helpers and 13 clients taken from the client workbooks. Clients start as "Incomplete"
  (documents and rates are not in the workbooks).
- Removed `database/migrations/` (old upgrade files for the previous schema; they do not apply to the new one).

**Masterlists**
- Trucks: truck number, plate, type, registration/insurance dates, regular driver/helper, status history,
  last PMS date. Status is computed by the system (Available, Assigned, On Trip, Under Maintenance) plus a manual
  Unavailable with reason. "Under Maintenance" always has an open maintenance job behind it.
- Drivers & Helpers: license number/expiry for drivers, availability (Available, Assigned, On Trip, Unavailable).
- Clients: company profile, contact, TIN, payment terms, required documents (signature, company profile,
  SEC/DTI, business permit, BIR 2303) uploaded as files, per-km rate and minimum charge per truck type.
  Status: Incomplete -> Pending Rate -> Active / Inactive. Only Active clients can be chosen for trips.
- All forms use dropdowns from the masterlists; deactivation is soft (history is kept).

**Trip workflow (PDF #12, simplified)**
- Statuses: Pending Review, Awaiting Resources, Pre-Assigned, Ready for Dispatch, Dispatched, In Transit,
  On Hold - Incident Reported, For Reschedule, Completed, Cancelled, Rejected. Each trip shows a progress
  stepper and a timeline.
- Operations Head reviews (approve or reject with reason). Approval auto-assigns truck, driver and helper
  (same truck type, Available, no open repair; regular crew first; then fewest completed trips in the last 30 days;
  helper required for Container/Wing Van, second driver optional for Car Carrier). If nothing is free the trip is
  "Awaiting Resources" and is assigned automatically when a resource becomes free. Manual reassignment with reason.
- If an assigned truck or person becomes unavailable, the system re-assigns or moves the trip back to Awaiting Resources.
- Pre-dispatch checklist (57 items, "Mark all OK" helper); any NOT OK keeps the trip from Ready for Dispatch and
  notifies Motorpool. Confirm Dispatch needs Ready for Dispatch + Released fund (checked again on the server).
- Pickup/drop-off are chosen on a map (Leaflet + OpenStreetMap, vendored in `public/assets/vendor/leaflet/`);
  distance is entered in km and used for billing. Map tiles need internet.
- Completion requires Waybill and Acknowledgement Receipt files and a completion time not earlier than dispatch.
- Incidents: Minor (truck stays on the trip; Confirm Repair -> Resume) or Major (truck Under Maintenance, trip
  For Reschedule; after repair: Reschedule -> second attempt -> new checklist -> Confirm Redispatch).
  Printable incident report.

**Funds, billing, finance, purchasing, maintenance**
- Trip fund: preset categories (Admin > Fund categories) + added lines; Ops Head approves or rejects; rejected
  requests are corrected and resubmitted; Finance releases (payment voucher + expense); liquidation records returned cash.
  Printable Request for Cash Fund (PDF #13 format).
- Billing: draft created at dispatch from the client's per-km rate (minimum charge applies) + additional charges;
  becomes For Billing on completion; Billing verifies Waybill + Acknowledgement Receipt, finalizes the Service Invoice
  (due date = invoice date + client terms); collections require an uploaded receipt; Partially Paid / Paid; SOA print.
- Purchasing: parts requests from Motorpool released from stock; purchase orders route to Finance (up to the
  approval limit, default 10,000) or Admin (above); budget is checked and reserved atomically, otherwise
  "Rejected - Insufficient Budget"; partial receiving; Finance pays received value. PO print with e-signature.
- Maintenance: incident repairs, on-premise repairs (photos required), monthly PMS reminders, Confirm Repair
  requires details and evidence; parts and labor costs go to expenses.
- Reports & Analytics: trips, fleet (downtime from status history), money (revenue, expenses, profit per trip,
  receivables aging), stock; filters with Apply/Reset; comparison with previous period.

**Removed (not needed by the objectives, or by instruction)**
- Payroll, Attendance, Cash Advance & Balances (module, pages, role `payroll`, tables).
- English/Tagalog language switch (`public/assets/js/i18n.js`, `public/assets/js/i18n/`, `users.language`).
- Per-role account limits, arrival checklist, 15-minute minimum checklist time, container/wing van client steps,
  saved routes table, Formula Settings computation lists (replaced by simple Settings + Fund categories).
- Duplicate/stale files: `backend/Modules/*.php` (top level), `backend/Modules/Inspections/`,
  `public/assets/js/modules/*.js` (top level), `backend/Core/TripFunds.php`.

**"Pending company confirmation" items settled into final rules**
- Auto-assign priority (C01), allowance categories (C02), dispatch order (C03), budget (one active Purchasing budget
  set by Finance, C05), checklist (all items OK, no minimum time, C06), redispatch fund optional (C07),
  PMS monthly instead of km-based (C08), 5 units per car carrier trip default (C09), billing per-km rate + minimum,
  VAT shown at 12% on the invoice, due date from client terms (C10), partial receiving allowed (C11), 30-day expiry
  reminders (C12), access per role in `backend/Core/Access.php` (C13), payroll removed (C14), Ops Head may reject a
  request (C17), client steps removed (C18), liquidation kept (C19), Awaiting Resources with automatic assignment (C20),
  billing documents = Waybill + Acknowledgement Receipt. No `TODO PENDING COMPANY CONFIRMATION` marks remain in code.
- Note: `docs/PENDING_COMPANY_CONFIRMATION.md`, `docs/`, `guide/`, `README.md` and `README-initial.md` were not
  modified (project rule) and still describe the previous version. `config/formulas.php` was not modified; the
  system reads only these defaults from it: purchase approval limit, document/license reminder days, payment terms,
  km per liter, diesel price and units per trip (its payroll and computation-list values are no longer used).

**Install / upgrade**
- New install: import `database/schema.sql`, then `database/extension.sql`, then (optional) `database/seed_masterlists.sql`;
  create the first Admin with `public/install.php` or `php scripts/create_user.php admin USERNAME "FULL NAME"`.
- The old database and the old backup in `storage/backups/` are for the previous schema and cannot be restored into this version.

**Verification (what was actually run)**
- Separate API script (outside the project) covering all workflows, role restrictions and database safeguards: 124 passed, 0 failed.
- Browser smoke test of every page and tab for every role: no JavaScript errors. Browser end-to-end flow
  (client -> trip -> fund -> dispatch -> completion -> invoice -> payment -> reports): 24 passed, 0 failed.
- Existing project tests were run without modification and do NOT pass, because they test removed features:
  `tests/formula_test.php` stops at `Settings::fuelCost()` (Formula Settings removed); `tests/e2e_workflow.py`
  stops when it cannot create/login the `payroll` role; `tests/system_test.py` (`--mode xampp --no-browser`):
  33 passed, 3 failed (all 3 are the payroll account), then stops at the account stage.

## 2026-11-02 (security, limit bawat role, mapa)
- **Lock ng account:** 5 maling password kahit saang computer = naka-lock nang 15 minuto (dati: bawat browser lang).
  Ang "Reset password" ng Admin ay nag-a-unlock din.
- **Recovery code ng Admin** (simple, walang email o setup): pangalan > Recovery code (kailangan ang kasalukuyang password),
  i-print at itago. Sa login page: "Forgot the Admin password? Use the recovery code". Isang beses lang gumagana ang code.
  May paalala sa dashboard ng Admin kapag wala pang code. Huling paraan para sa IT: `scripts/reset_password.php`.
- Kapag napalitan ang password (ng user, ng Admin o gamit ang recovery code), mala-log out ang lahat ng lumang session ng account.
- **Limit bawat role** (User Accounts > Limits per role): ilang active na account bawat role; dispatchers bawat shift (Day / Night);
  0 = walang limit; Admin kahit 1. Default: tig-iisa. Ang punong role o shift ay hindi na lalabas sa pagpipilian. Sinusunod din
  ito ng `scripts/create_user.php`.
- **Mapa (Google Maps embed, walang API key at walang bayad, gaya ng sa mulawin_fleetops repo):** sa Add / Edit route, sa
  paggawa ng trip, sa Trip details, at sa Map button ng Dispatch Board. May "Open in Google Maps" para makita ang layo sa km.
- **DATABASE CHANGE:** `database/migrations/2026-11-04_account_security.sql` (bagong install: kasama na sa `schema.sql`).
- Test: may checks na para dito sa `tests/system_test.py` (14c at mapa).

## 2026-11-02 (wika: English o Tagalog)
- Sa dropdown ng pangalan (kung nasaan ang Log Out): **Wika / Language: English o Tagalog**. Naka-save sa account, kaya
  pareho saan mang computer o phone mag-login. English ang default.
- Tagalog: pang-opisinang Taglish sa lahat ng nakikita sa screen (buttons, labels, tabs, cards, charts, forms, mensahe ng
  system at notifications). Hindi isinasalin: mga pangalan ng page sa menu, mga status at termino ng PDF at Excel
  (Pre-Assigned, Ready for Dispatch, Billed, SSS ...), at ang mga printout (invoice, payslip, payment voucher, printed report).
- Paano gumagana: `public/assets/js/i18n.js` (tagasalin) at `public/assets/js/i18n/tl.js` (diksiyunaryo). Para magdagdag
  o magpalit ng salin, i-edit ang `tl.js`; walang ibang file na kailangang galawin.
- Sa English, English na rin ang ilang tekstong Tagalog dati sa screen (halimbawa ang tabs ng Finance: Expenses & Payables,
  Employees, Planning).
- **DATABASE CHANGE:** bagong column `users.language`. Bagong install: kasama na sa `schema.sql`. Live: i-import ang
  `database/migrations/2026-11-03_user_language.sql` pagkatapos ng backup (ligtas kahit dalawang beses).
- Test: may language checks na sa `tests/system_test.py` (API at browser sa Tagalog).

## 2026-11-02 (polish: analytics)
- **Bug fix:** gumagana na ang Print sa Reports & Analytics (dati ay blangkong page, o lumang payslip ang lumalabas).
  Dalawang pagpipilian: Print this section at Print all sections. May header (kumpanya, period, filters, sino at kailan
  nag-print), cards, charts at buong tables.
- Charts: may ₱ at comma ang pera sa axis at tooltip, may porsyento ang bilog na charts, buong numero lang ang bilang,
  iisang kulay bawat kahulugan sa lahat ng chart, pahigang bar para sa client at dispatcher, stacked na maintenance cost
  per truck (pinakamalaki muna). Bagong chart sa Reports > Payroll (gross, deductions at net kada period, galing sa
  parehong data ng table). Mas maikli ang charts sa phone.
- Cards: may maliit na arrow ang card na may link at bubukas sa mismong tab o filter (halimbawa Overdue balance ->
  Billing > Receivables by client). Hindi na clickable kapag wala sa menu ng role ang page.
- Dashboard: "Updated" na oras sa tabi ng Refresh. Reports: buod ng period at filters sa itaas ng tabs.
- Labels: ang card group na "Pera" ay "Money" na; ₱ na rin ang hints ng cards (dating "PHP").
- Walang database change at walang binagong formula.

## 2026-11-02 (automated system test)
- Bagong `tests/system_test.py` (at `tests/run_system_test.bat`): pipili ng XAMPP o Hostinger, magla-login bilang Admin,
  gagawa ng test account kada role kung wala, susubukan ang buong workflow at mga bawal na gawain sa API, iki-click ang bawat
  page at tab ng bawat role sa browser, at gagawa ng HTML report na may screenshots. Guide: `tests/SYSTEM_TEST_GUIDE.md`.
- Login: hindi na nabubura ang username kapag mali ang password.
- Walang database change sa update na ito.

## 2026-11-02 (FMS PDF v6 at v7)
- **Billing draft (PDF v7, Scenario A step 9 / B step 10):** sa Confirm Dispatch (ongoing na ang trip), gumagawa ang
  system ng billing draft batay sa client rate ng route (`backend/Core/BillingDraft.php`). Sa Billing > Billing drafts:
  Edit (habang Draft), **Verify documents** (kailangan may waybill), **Finalize** (trip Completed + verified + may rate),
  Return to draft. Ang invoice ay galing na sa finalized billings (tab na Ready to invoice); hindi na tina-type ang rate.
  Ang cancel ng invoice ay nagbabalik sa Finalized.
- **Drivers at helpers lang (PDF v6):** inalis ang Admin Staff at PDI Personnel, ang "Employee o Contractor" at ang
  Active/Resigned sa screen. Ang page ay "Drivers & Helpers". Para sa drivers at helpers lang ang Payroll at cash advance.
- **Payroll:** sinusunod ang PDF v6 (attendance, trip pay, deductions). Bukas pa ang tanong kung per biyahe lang ang
  sahod ng drivers (C13); pwedeng i-switch sa settings nang walang code change (tingnan ang PENDING_COMPANY_CONFIRMATION).
- **DATABASE CHANGE:** bagong table `billing_drafts`. Bagong install: `schema.sql` + `extension.sql`. Existing database
  (live): i-import ang `database/migrations/2026-11-02_billing_drafts.sql` pagkatapos ng backup.
- Maliit na ayos: hindi na kumukurap ang nakadikit na header sa maiikling page.

## 2026-11-01 (polish: navigation at layout)
- Sidebar: isang section lang ang bukas (accordion); bubukas ang section ng current page.
- Nakadikit ang page header (title at buttons) habang nag-i-scroll; may "Back to top" button.
- Reports & Analytics: isang tab bawat section (Trips, Fleet, Money, Payroll, Stock ayon sa role); ang mga table ng
  section ay nasa iisang card na may pills; ang walang laman ay isang linya na lang; "More filters" para sa
  truck type, status, client, truck, driver at dispatcher.
- Dashboards: alerts, cards, 2 charts (ang iba ay "Show more"), tapos tables sa iisang card na may pills.
  Ang cards ng Admin at Operations Head ay naka-grupo (Trips, Fleet, Pera / Fleet at crew).
- Formula Settings: tabs (Values na may pill bawat group, Computation lists, Example); editable muna, View only sa huli.
- Finance & Payables: 8 tabs naka-grupo sa 3 (Gastos at Bayarin, Empleyado, Plano).
- Work pages: compact na stat cards para kita agad ang table.
- Tables: 25 rows bawat page na may Prev at Next (dating "Show more"). Search at CSV ay sakop pa rin ang lahat ng rows.
- Phone: tabs na naka-swipe, 2 cards bawat hilera, walang page na lumalampas sa lapad ng screen.
- Walang binago sa database, workflow, permissions o formulas. Files: `core.js`, `app.css`, `general/reports.js`,
  `general/dashboard.js`, `administration/admin.js`, `accounting/finance.js`, `backend/Modules/General/Analytics.php`.

## 2026-11-01 (modules grouped by department)
- Backend modules moved to department folders: `backend/Modules/Operations/` (Fleet, Trips, Inspections),
  `Maintenance/` (Maintenance, Purchasing), `Accounting/` (Funds, Finance, Billing, Payroll),
  `General/` (Analytics, Documents), `Administration/` (Admin).
- Screen files moved the same way: `public/assets/js/modules/<department>/<file>.js`.
- `Actions::MODULES` is now a map (module => department); `Access.php`, `public/index.php`, `scripts/preflight.php`
  and the storage paths of Admin and Documents were updated. The sidebar was NOT changed.
- File header comments, README, docs/SOURCE_TRACEABILITY.csv and TEST_REPORT use the new paths.
- No change to the workflow, database or formulas.
- New guides: one user guide per department (Operations, Maintenance, Accounting), one for Admin and Audit,
  and a full Technical Documentation (architecture, API, database, data sources, security, deployment).

## 2026-10-31 (flow update: FMS PDF version 2)
- Trip statuses follow the new PDF: Pending Review, Pre-Assigned, Ready for Dispatch, Dispatched,
  On Hold - Incident Reported, In Transit, For Reschedule, Returned, Completed (plus Awaiting Resources, Cancelled, Rejected).
- Fund request is now made by the **dispatcher**: preset categories (Forklift Operator 100, Checker 100, Passway 200,
  Driver Meal 300, Helper Meal 200) plus added categories; the system computes the total.
- **Removed**: automatic fund (fuel + toll), the ₱15,000 fund approval limit (Admin no longer approves funds),
  Finance "Process" step and "Pending Release", Operations Head departure clearance.
- Operations Head approves every fund → "Ready for Release"; reject → dispatcher revises and resubmits the same request.
- Finance can release the fund before the pre-dispatch checklist. Confirm Dispatch is enabled only when
  fund = Released and trip = Ready for Dispatch (disabled button shows what is missing).
- Scenario C: dispatcher incident report (date/time, location, description, problem) puts the trip On Hold;
  Maintenance assesses Minor or Major. Minor: Repaired - Cleared to Continue → Resume Trip (In Transit, not a second
  attempt). Major: truck Under Maintenance, trip For Reschedule → repair → Reschedule Trip → Confirm Redispatch (Second Attempt).
- Kept: ₱10,000 purchase approval limit, arrival checklist and Submit completed trip, liquidation.
- Database: trips.ops_cleared_* and fund_requests.processed_* removed; incidents get incident_at, problem,
  assessed_by, assessed_at and a nullable severity. Use a new empty database (schema.sql + extension.sql).

## 2026-10-31 (update)
- Minimum password length changed from 12 to 8 (letters and numbers still required). One setting: `PASSWORD_MIN_LENGTH` in `backend/bootstrap.php`.

## 2026-10-31 — Aligned V2 (V2 structure + repo design + PDF workflow + Excel formulas)
- Workflow follows the FMS PDF: Scenario A/B, automatic assignment, automatic allowance, fund approval limits
  (Ops Head ≤ ₱15,000, Admin > ₱15,000), Finance processing, checklist → Ready for Release, release, clearance,
  dispatch, incidents (minor / major), 2nd attempt, preventive maintenance by km.
- Added back from the PDF / repo: Payroll and Timekeeping (attendance, payroll, payslips, deduction form, cash advance,
  uniform, damages, cash bond), dashboards per role, reports and analytics with charts, notifications, activity log,
  backup, Formula Settings, document bulk upload, purchase budget check by the system.
- Formulas from Excel: Billing P&L (fuel, RFID, misc %, salaries, net income, margin), Drivers Payroll (wage, SSS 775/24,
  PhilHealth 75, Pag-IBIG 50, cash bond 500, net), Car Carrier (5 units per trip, company → client weekly summary),
  Container (down-truck days).
- Design from the `mulawin_fleetops` repo (layout.css, login.css, Bootstrap 5, Bootstrap Icons, Chart.js).
- Module isolation: one PHP module + one JS screen file per module; each page loads its own data.
- New empty database (schema.sql + extension.sql). Roles renamed: `head_management` → `admin`; added `payroll`.
