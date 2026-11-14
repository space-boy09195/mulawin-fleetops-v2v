# tests/

Automated tests. This folder is blocked from the web by the root `.htaccess`.

## Suites

| Command | What |
| --- | --- |
| `php tests/run_route_tests.php` | Client Routes (Checklist #20) and the whole migration chain (base schema + every migration = fresh install) |
| `php tests/run_map_tests.php` | OpenStreetMap / road distance (Checklist #5). No real call to openrouteservice (fake transport + local mock server `tests/_mock_ors.php`) |
| `php tests/run_trip_routing_tests.php` | Road distance in Trip Management (Checklist #5 Phase 3): approved client routes, overrides, Manual fallback, trip workflow regression. Fake map service only |
| `php tests/run_trip_schedule_tests.php` | Trip schedule restrictions (Checklist #38/#40): checklist and Confirm Dispatch windows, Admin settings, date rollover, Asia/Manila, reschedule, workflow regression |
| `php tests/run_fund_release_tests.php` | Cash-only trip fund release (#36) and Trip Fund tabs (#37): API refusals, server date, double click, two Finance users at once (two processes), release window, closed trips, nothing written by failures |
| `php tests/run_mechanic_attendance_tests.php` | Dispatcher-only account shifts, mechanic masterlist without shift, Time In / Time Out, availability, assignment, migration and rollback |
| `php tests/run_maintenance_overview_tests.php` | Maintenance Overview: page rights, counts at every repair step, parts follow-up, trip incident flow, counts = Repair Management records, recent activity, read-only |
| `php tests/run_motorpool_workspace_tests.php` | Motorpool workspace (Parts Requests + Mechanic Attendance pages) through the real `Actions::pageData` / `Actions::run` (child process per user): access per role, no cost / stock / budget / supplier / PO data in the Motorpool payload, request create / release / Not Available / cancel, notification pages, attendance board, daily mark Admin-only, date range clamp, Time In / Time Out refusals, read-only |
| `php tests/run_job_helpers_tests.php` | More than one mechanic per repair (migration 12): behaviour before the migration (clear refusal, existing flows unchanged), migration = fresh install with no row changed, add / remove assisting mechanics (roles, timed-in, busy, duplicates, all-or-nothing), busy for Time Out / deactivation / lead assignment, release on completion and cancellation, history, events and audit. Databases: `mulawin_jh_fresh_test`, `mulawin_jh_reference_test` |
| `php tests/run_purchasing_finance_tests.php` | Purchasing -> Finance money integrity: PO create -> approve -> partial receive -> pay -> close with the budget reserved/spent invariant; closed-unreceived PO leaves the payables (G5); expired budget period (G7); budget renewal carries open POs and their reserve (G8); PO history, part history and their role refusals. Database: `mulawin_pf_fresh_test` |
| `php tests/run_accounting_workspace_tests.php` | Finance / Billing / Audit workspace reads: Billing through verify -> finalize -> collect (Overview, Collections, Receivables figures = sums of stored invoices; overdue and aging buckets), Finance Overview = Payables & Payments rows, page payloads, Audit Logs / Transaction History / Reports (types, field changes, counts), role refusals through the real dispatcher for every new page, reads write nothing. Database: `mulawin_acct_fresh_test` |
| `php tests/run_stock_count_tests.php` | Inventory control: Purchasing.adjust and part opening quantities refused for every role; PO receiving = one Stock In per line; stock count submit (Purchasing only, stock unchanged, evidence, audit, Admin notice), Admin post (one linked Adjustment, before/after) or reject, double post and stale count refused, database guards, ledger = on hand; Purchasing Reports analytics figures, reorder rule explanation, forecast honesty, roles, no writes. Database: `mulawin_sc_fresh_test` |
| `php tests/run_integration_workflow_tests.php` | Cross-department workflow after the luis + kenneth integration, through the real actions only: Ops Head planning + Dispatcher fixed-pair assignment, trip fund (approval, one voucher), dispatch billing draft (fixed-route pricing), completion, invoice, partial + final collection, incident (Major) -> repair -> parts shortage -> linked PO -> Finance approval -> receiving -> release -> PO payment (budget reserved -> spent) -> verification; money / stock / audit invariants, Head Management reads, Audit read-only. Database: `mulawin_e2e_fresh_test` |
| `php tests/run_incident_clearance_tests.php` | Motorpool vehicle clearance for trip incidents (migration 14): Minor -> verified repair -> Fit to Continue -> Dispatcher resumes; Minor Not Fit keeps the trip On Hold; Major records Not Fit -> For Reschedule -> linked repair -> verification -> Operations reschedules; unauthorized roles, duplicate and stale clearance refused; Head Management / Audit reads. Database: `mulawin_clear_fresh_test` |
| `php tests/run_dispatch_attendance_tests.php` | Replaces the retired crew_dispatch suite (automatic assignment and permanent shifts were retired). Integrated attendance at Confirm Dispatch: Warn/Enforce setting and guard, two-process race with an Admin mark, readiness report, required crew in both modes, Dispatcher Time In required, Admin Absent mark blocks Time In and dispatch (Warn and Enforce), no Admin Present mark needed, Day/Night only labels sessions, back-dated marks do not block today. Database: `mulawin_datt_fresh_test` |
| `php tests/run_uat_browser_scenario.php` | Not a pass/fail suite: builds synthetic browser-UAT data with the real actions and stops each flow where one role must act in the browser (assign, Not Fit reschedule, fund approve/release, billing, inspection-only clearance, parts release, PO receive/pay). KEEPS the database; users `uat_<role>` cannot sign in until a test-only password is set on that `*_test` database. Database: `mulawin_uatui_<random>_fresh_test` |
| `php tests/workspace_access_tests.php` | No database. Page map per role (Motorpool / Purchasing workspaces), menu order, every page has a module and a screen, source checks of the scoped reads |
| `php tests/maintenance_report_rules_tests.php` | No database. Maintenance Reports timeline periods (day / week / month) and the rule-based suggestions: valid, insufficient-data and no-match cases |
| `php tests/purchasing_report_rules_tests.php` | No database. Purchasing Reports replenishment suggestions (frequent + not covered, covered by an approved PO, insufficient history) and per-period suggestions |
| `php tests/run_head_management_tests.php` | Isolated `*_test` database. Head Management (Admin): page access, read-only module, approval queue limited to Admin-routed decisions, PO and stock-count decisions through the existing actions (stale and duplicate decisions refused), figures equal their sources, reads write nothing |
| `php tests/run_phase2_migration_safety_tests.php` | Phase 2 migrations: record is the last step, partial-failure states, recovery files, nothing legacy changed, fresh-install order |
| `php tests/run_crew_attendance_tests.php` | Daily attendance without shifts: roster of every active driver/helper/mechanic, marking, history, masterlist without shift, daily bell, migration of legacy shift-based marks and rollback |
| `php tests/run_trip_documents_tests.php` | #44 Diesel PO / Supplier SOA attachments: upload, file checks, nothing left on failure, roles and statuses, required completion documents unchanged, Finance view/download rights |
| `php tests/run_trip_batch_tests.php` | Batch trip planning: separate trips, Awaiting Resources, all-or-nothing validation, duplicate sends (incl. two processes), limits, dispatcher requests, approved routes, existing trips untouched, normal dispatch afterwards |
| `php tests/run_billing_adjustment_tests.php` | Billing Rate Approval: migration (marker last, failed / partial / repeated import + recovery file, old charges grandfathered, totals unchanged, fresh = upgrade), Pending until Admin approval, no self-approval, discounts / increases, atomic change and removal, withdraw, total stays above zero, DR in verification, finalize blocked while pending, invoiced / paid untouched, DB constraints, only the requester withdraws, two-process races (request / approval / finalize), trip operations unaffected |
| `php tests/run_account_security_tests.php` | Current-password lock: wrong current password in password / email change counts toward the login lock (5 = 15 minutes), shared counter, atomic and serialized (stale rows, two processes), locked account refused even with the right password or code, notice to the previous email address (masked, no code / link), mail failure keeps the change. Local SMTP receiver only. |
| `TEST_BASE_URL=... php tests/run_htaccess_tests.php` | Web exposure (read-only GET, no database): `.git`, `.env` and other dot-paths, private folders, backups and docs are not served; app, API and assets still work. `TEST_LAYOUT=public` when the document root is `public/`. Run after every deployment. |

Both need `TEST_DB_USER` / `TEST_DB_PASS` (see below). The shared launcher is `tests/_launcher.php`.

## Client Routes (Checklist #20)

```
TEST_DB_USER=<mysql user> TEST_DB_PASS=<password> php tests/run_route_tests.php
```

Windows / XAMPP:

```
set TEST_DB_USER=root
set TEST_DB_PASS=
C:\xampp\php\php.exe tests\run_route_tests.php
```

Safety rules:

- The project `.env` is never read. The launcher copies the code to a temporary folder with its own test `.env`.
- Only databases ending in `_test` are created or dropped (`<prefix>_fresh_test`, `<prefix>_upgrade_test`,
  `<prefix>_partial_test`; prefix `mulawin_routes`, change it with `TEST_DB_PREFIX`).
- The run stops when a name does not end in `_test` or equals `DB_NAME` of the project `.env`.
- `tests/route_tests.php` refuses to run on its own (it only runs inside the temporary copy).
- The base schema for the migration checks is read from git as `<commit>~1` (quoted). `^` is not used: on Windows
  `cmd.exe` drops an unquoted `^`, which loaded the schema AFTER the migration and broke the upgrade checks.

Exit code 0 = all tests passed. Details: `docs/CLAUDE_20_CLIENT_ROUTES.md`.


## Unified PHPUnit runner (PHP 8.2 / PHPUnit 11.5)

Run from the project root in PowerShell:

```powershell
& C:\xampp\php\php.exe vendor/phpunit/phpunit/phpunit
& C:\xampp\php\php.exe composer.phar test
& C:\xampp\php\php.exe vendor/phpunit/phpunit/phpunit --log-junit tests/reports/safe.xml
```

The default `Safe` suite runs environment and unit tests without the application .env.
`--testsuite Unit`, `--filter Checklist`, `--testdox` and other PHPUnit options work normally.
The bootstrap copies backend/config into a temporary directory with an unusable local DB endpoint;
only the functions under test are loaded. Unexpected DB access fails instead of reaching development.

Integration is opt-in and runs each existing suite in its own process to avoid global helper collisions:

```powershell
$env:FLEETOPS_DB_TESTS = '1'
$env:TEST_DB_USER = 'fleetops_test' # dedicated local user, never application credentials
# Set TEST_DB_PASS explicitly in the session, using your secret manager. An empty value is
# not retained by some Windows shells; use a dedicated password-protected test account.
& C:\xampp\php\php.exe vendor/phpunit/phpunit/phpunit --testsuite Integration --log-junit tests/reports/integration.xml
```

Missing opt-in/credentials/extension yields an explicit skip; an enabled suite with a DB connection,
migration or assertion failure fails the run. A green default run does not prove DB workflows.
Use a local MySQL user with CREATE/DROP and table privileges limited to the intended test prefix
(wildcard grants must escape literal underscores). Do not grant global privileges. No user is
created automatically. Never substitute credentials read from the application's .env.

Database names now include a random per-run identifier: `<prefix>_<random>_<suffix>_test`.
Only loopback DB hosts are accepted. A manifest binds the temporary directory, DB names and
connection configuration. A database must have this run's ownership marker before reset or deletion.
Pre-existing/unowned databases are refused, even with a `_test` suffix. Cleanup runs after child
failure as well as success and removes only owned databases. Forced process termination can leave
artifacts; inspect them manually, never remove databases by suffix alone. The launcher reads only
DB_NAME from the project .env as a refusal check; it does not use application credentials.
Legacy direct launcher commands remain supported with explicit TEST_DB_USER.

HTTP feature checks require real Apache and an explicit URL:

```powershell
$env:TEST_BASE_URL = 'http://localhost/mulawin-fleetops-v2v'
& C:\xampp\php\php.exe vendor/phpunit/phpunit/phpunit --testsuite Feature --log-junit tests/reports/feature.xml
```

Reports are ignored by Git. Legacy results appear as one PHPUnit test per script; individual legacy
assertions remain in subprocess output on failure. This is scenario coverage, not measured code coverage.
See COVERAGE_INVENTORY.md for existing assertions and remaining workflow gaps. Quantity expansion,
preventive maintenance and a Delayed trip status have not been introduced.


`run_inventory_finance_tests.php` adds stock-release and payable/budget reconciliation scenarios.
It uses the same isolated launcher and is automatically discovered by the Integration suite.
It has not been database-verified in this session; see IMPLEMENTATION_REPORT.md.

## Operations Head redesign browser checks

Operations Head Reports now has its own role-scoped three-tab projection and screen. The default safe PHPUnit suite includes `OperationsReportsTest.php`; the same Chrome runner includes `ops_reports_ui.html`, five report layouts, and an actual fixture CSV download containing the full matching set from page four. Open that fixture locally with `?preview=trips`, `?preview=trucks`, `?preview=incidents`, or `?preview=empty`. Mocked CSV downloads are written under ignored `tests/reports/operations-reports-download-*`. Never serve fixtures through Apache.

`run_operations_reports_tests.php` reuses the existing guarded disposable launcher for query/date/distinct-count/export scenarios and is discovered by Integration. It has not been DB-executed because isolated credentials were absent. See `docs/OPERATIONS_HEAD_REPORTS.md` for actual results, retained-dispatch utilization semantics, and manual checks. The generic Reports renderer and other roles' report queries remain intact.

The subsequent New Trip improvement adds a required manual Dispatcher group/shift per planning entry and renames quantity to Number of Required Trucks, while retaining the approved layout. See `docs/TRIP_PLANNING_DISPATCHER_ASSIGNMENT.md` for the migration, account-slot behavior, strict access integration, and actual results. New local-file previews are `ops_head_ui.html?preview=planner-filled` (three entries / nine required trucks) and `?preview=planner-review` (nine individual drafts); API calls remain mocked. The existing Chrome runner checks these at desktop/tablet/mobile sizes.

`tests/Unit/DispatcherPlanningTest.php` is included in the default safe PHPUnit suite. `run_planning_dispatcher_tests.php` adds guarded disposable-DB creation/ownership/route/retry/notification scenarios and is discovered by Integration. It has only been syntax-checked; isolated credentials were absent. Historical NULL-shift trips stay group-wide. Legacy Operations Head creation fixtures need the new manual `dispatcher_assignment` value (for example `container_wingvan_dispatcher:Day`) and an actually approved client/type route. No schedule-based shift inference or automatic backfill should be added to tests or production to satisfy old assumptions.

Run `node tests/run_ops_head_browser.cjs` from the repository root. This uses local headless Chrome and Node's built-in WebSocket support, with no npm dependencies. `operations_ui.html`, `ops_head_ui.html`, `trip_approvals_ui.html`, and `dispatcher_ui.html` mock every API call; no login or database is used. The runner also checks exact desktop/tablet/mobile viewports, including fully settled drawers and visible fixed footers, writes screenshots/results to ignored `tests/reports/`, and safely removes its generated browser profile.

Open `ops_head_ui.html` directly as a local file for manual fixture results. Its `?preview=dashboard`, `?preview=planner`, `?preview=active`, and `?preview=incidents` modes display clearly labeled fixture records for visual review. Do not serve these test files through Apache. See `docs/OPERATIONS_HEAD_REDESIGN.md` for the implementation, actual results, business-rule discrepancies, and live verification limitations.

`trip_approvals_ui.html` adds dedicated approval-list and drawer scenarios; `?preview=list` and `?preview=drawer` show test-only visuals. See `docs/TRIP_APPROVALS_REDESIGN.md` for the current sidebar, evidence-based counts, unchanged maintenance read policy, and actual results.

`run_trip_approval_tests.php` uses the existing guarded isolated launcher and is automatically discovered by the PHPUnit Integration suite. It covers approval/rejection evidence, server pagination, lifecycle history, request-date filters, stale decisions, authorization, and a two-process approval race. It was added and syntax-checked but not database-executed in this session because dedicated isolated credentials were absent. Run only with the disposable-database protections above; do not run `trip_approval_tests.php` directly.

## Dispatcher redesign checks

The latest Dispatcher UI cleanup fixes page-record tables at ten rows, removes their size selectors, and removes redundant page-header access badges. Shared component defaults for other roles remain intact. Run the same safe PHPUnit and mocked Chrome commands; see `docs/DISPATCHER_UI_CLEANUP.md` for exact pages, executed results, boundary/search/filter coverage, and skipped server-integration limits. The guarded `dispatcher_tests.php` adds server request-size enforcement and unchanged Operations Head size assertions; these have not been database-executed in this session.

The latest workflow update supersedes the earlier combined availability page and eight-link sidebar. See `docs/DISPATCHER_WORKFLOW_IMPLEMENTATION.md` for the current attendance, regular-driver assignment, strict operational scoping, six-pill trip details, actual verification results, and limitations. The additive `2026-11-10_09_dispatcher_sessions.sql` migration is required for sessions; it has been installed in the verified local development database without seeding operational records. `database/extension.sql` also includes it for fresh isolated installs.

Run `C:\xampp\php\php.exe vendor/phpunit/phpunit/phpunit --do-not-cache-result` for safe scope/session/assignment tests, and `node tests/run_ops_head_browser.cjs` for mocked browser contracts and responsive checks. New fixture previews include `attendance`, `assignment-form`, `drawer-fuel`, and `drawer`; existing `availability` and `personnel` previews now show separate read-only master lists. Add `&role=cc` to inspect Car Carrier labels. Fixtures remain local files with mocked APIs.

`run_dispatcher_tests.php` now includes real session idempotency/history, held-person checkout rejection, fixed-truck validation, two-distinct-driver Car Carrier assignments, group-transfer history, and cross-scope direct access scenarios. These require the guarded disposable-database launcher above. They have not been DB-executed in this session. The full legacy DB regression suite is also unverified; older automatic-assignment fixtures assume crew without regular links/current sessions and need adaptation to the new prerequisites before their old expectations are applicable. Do not weaken application eligibility or run those fixtures on development records to make them pass.

The final approved visual refinement restores professional green/neutral surfaces, yellow active navigation, pastel icons, and layered elevation. The same browser runner now also exercises actual router selection, custom trip pagination and page size, shared List/Grid controls, empty-table headers/footers, five additional History/availability layouts, and a real reduced-motion hover check. Test-only preview modes `history` and `history-empty` show the final Trip History layouts. See `docs/DISPATCHER_FINAL_REFINEMENT.md` for the final design and executed results; its visual direction supersedes the earlier Controlled Vivid stage.

The subsequent Dispatcher refinement adds centralized filter draft/apply/clear/search/date checks, grouped-form checks, computed color contrast checks, native Escape/Enter focus checks, and additional responsive captures. Run the same `node tests/run_ops_head_browser.cjs` command. Detailed Dispatcher results are saved to ignored `tests/reports/dispatcher-refinement-checks.txt`; see `docs/DISPATCHER_REFINEMENT.md` for the measured contrast and actual results. Additional test-only preview modes are `filters`, `fund-form`, `incident-form`, `request-records`, `diesel`, `availability`, and `documents` (for example, open the fixture file with `?preview=filters&role=cc`). These remain fully mocked and must not be served through Apache.

`dispatcher_ui.html` covers both Dispatcher roles, summary-card mappings, requests/recovery, fund presets/totals, Diesel document controls, both incident entry points, reference/history/documents, and action restrictions. Open it directly as a file; `?preview=dashboard`, `?preview=operations`, `?preview=requests`, `?preview=funds`, `?preview=incidents`, and `?preview=drawer` provide test-only visuals. Add `&role=cc` for Car Carrier. The shared browser runner checks 1920px, 1440px, 820px, and 390px layouts.

`run_dispatcher_tests.php` uses the guarded disposable-database launcher and is discovered by PHPUnit Integration. Its new read-scope, creator-key recovery, preset-authority, attendance/arrival, and document scenarios are syntax-checked but have not been database-executed because isolated credentials were absent. Do not run `dispatcher_tests.php` directly or use the development database. See `docs/DISPATCHER_REDESIGN.md` for actual results and the explicitly unimplemented Diesel PO request/approval/issuance workflow.
