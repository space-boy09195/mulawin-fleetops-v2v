# Luis safe integration candidate — 2026-11-14

## Outcome and branch safety

**BLOCKED: local candidate only. No branch was pushed and no pull request was created.**

- Candidate: `integration/luis-safe-merge-20261114`.
- Target remains `bb308e984bc523905433f9d73630eeea346752a0` on both the local target branch and `origin/integration/all-dev-20261114`.
- Source `luis`: `85ccb0e14598e01ff9504d0d015a530f1df6c24c`.
- Source `luis-maintenance-ui`: `bf2b9a432fed93d1763188c16cec104bea51eda5`, verified as an ancestor of `luis`.
- Normal merge commit: `683abf8`; subsequent local verification fixes are separate commits. Use `git rev-parse integration/luis-safe-merge-20261114` for the current candidate SHA.
- The checkout was clean before merging. No existing branches were deleted. `main` was not checked out, merged into, or pushed.
- Repository instructions from the target were retained; the incoming AGENTS.md describes a different checkout and older workflow.

## Conflict resolutions

Eleven files conflicted. Each was examined; no repository-wide ours/theirs strategy was used.

| File | Resolution |
| --- | --- |
| `backend/Actions.php` | Register both existing `HeadManagement` and incoming `Oversight`; retain department dispatch. |
| `backend/Core/Access.php` | Combine department section registries while retaining the current executive menu, Head screens, and Operations-only Dispatcher Monitoring. |
| `backend/Modules/Maintenance/Mechanics.php` | Retain Motorpool-only mutations and incoming attendance/history processing; the conflict itself was explanatory comments. |
| `public/assets/js/core.js` | Keep collapsible executive and Operations menus, current metric icons and router behavior; add scoped department workspace helpers. Prevent the incoming Admin workspace from replacing the approved executive sidebar or landing page. |
| `public/assets/js/modules/accounting/billing.js` | Keep the current Head billing renderer as an Admin-only renderer; add Luis's Billing overview, invoice, collections and receivables workspaces for their authorized roles. |
| `public/assets/js/modules/accounting/finance.js` | Keep the current Head financial renderer as an Admin-only renderer; add Luis's Finance overview, payments and budgets workspaces. |
| `public/assets/js/modules/administration/admin.js` | Keep the current account workspace and role/shift validation; do not restore the older duplicate user table. Retain the incoming forced-password-change indicator. |
| `public/assets/js/modules/maintenance/maintenance.js` | Keep the current Head monitoring renderer and add the Motorpool workspace. Restore existing drawer sections with the new plural mechanic heading; retain helper mechanics and clearance controls. |
| `public/index.php` | Load existing Head/Operations scripts plus new Maintenance history, Audit and Oversight scripts; add workspace CSS without removing existing component assets. |
| `tests/fund_release_tests.php` | Keep the current fixed-route snapshot and manual-resource regression flow. Restore direct trip creation in this intentionally unassigned fixture rather than the incoming helper that can assign resources. |
| `tests/route_tests.php` | Keep the newer target-side route tests in conflicting hunks. Their existing failures were separately reproduced on the untouched target. |

Other retained work includes daily attendance, mechanic sessions and assisting assignments, inventory count reconciliation, Purchasing/Finance integrity checks, incident clearance and resumption audit history. Trip edits keep their approved route. Fixed driver pairing, Dispatcher account ownership, manual assignment, resource holds, and read-only monitoring guards remain in place.

## Verification

Runtime: XAMPP PHP 8.2.12, existing PHPUnit 11.5.57, installed Node/Chrome, isolated MariaDB 10.4.32.

| Check | Result |
| --- | --- |
| Modified PHP syntax (`php -l`) | PASS: 81 files. |
| Modified JavaScript syntax (`node --check`) | PASS: 15 files. |
| Whitespace and unresolved conflict-marker checks | PASS. |
| Default PHPUnit Safe suite | FAIL: 482 tests, 1,247 assertions, 7 failures and 2 warnings. The same failures and warnings were reproduced on untouched target `bb308e9` in a separate worktree. |
| `run_trip_batch_tests.php` | PASS: 65 checks. |
| `run_operations_v43_tests.php` | PASS: 89 checks after correcting fixtures to current Admin rate activation and retired permanent crew shifts. No application permissions were relaxed. |
| `run_planning_dispatcher_tests.php` | PASS: 40 checks. |
| `run_dispatcher_tests.php` | PASS: 49 checks. |
| `run_dispatch_attendance_tests.php` | PASS: 31 checks. |
| `run_maintenance_overview_tests.php` | PASS: 106 checks after matching the approved Head monitoring label. |
| `run_mechanic_attendance_tests.php` | PASS: 80 checks after matching the existing account workspace shift field. |
| `run_job_helpers_tests.php` | PASS: 52 checks. |
| `run_purchasing_finance_tests.php` | PASS: 41 checks. |
| `run_stock_count_tests.php` | PASS: 61 checks. |
| `run_incident_clearance_tests.php` | PASS: 83 checks. |
| `run_account_security_tests.php` | PASS: 48 checks; mail went only to the suite's local test receiver. |
| `run_inventory_finance_tests.php` | PASS: 20 checks. |
| `run_fund_release_tests.php` | PASS: 71 checks after restoring the intentionally unassigned fixture. |
| `run_billing_adjustment_tests.php` | PASS: 173 checks. |
| `run_accounting_workspace_tests.php` | PASS: 40 checks. |
| `run_phase2_migration_safety_tests.php` | PASS: 93 checks, only on disposable databases. |
| `run_integration_workflow_tests.php` | PASS: 72 cross-department checks. |
| `run_route_tests.php` | FAIL: four assertions then an already-decided rate exception; reproduced on untouched target. The suite assumes an older manual/pending approval flow. |
| Browser `--components-only` | PASS: 60 fixture checks; interactions and audit drawer checked at 390, 820 and 1366 pixels. Finance assertions follow the new direct row action while retaining endpoint, amount, receipt and read-only checks. |
| Browser `--fixtures-only` | FAIL: `operations_ui.html` does not complete on either target or candidate. Candidate's initial Maintenance drawer regression was repaired and verified with the component subset. |
| Live authenticated application / operational schema | NOT VERIFIED. |

The 18 passing database-backed suites contain **1,214 passing checks**. The route suite remains a separate failing gate; no successful release claim is made.

Commands used include:

```powershell
& 'C:\xampp\php\php.exe' vendor/phpunit/phpunit/phpunit --do-not-cache-result
& 'C:\xampp\php\php.exe' tests/run_trip_batch_tests.php
& 'C:\xampp\php\php.exe' tests/run_operations_v43_tests.php
& 'C:\xampp\php\php.exe' tests/run_phase2_migration_safety_tests.php
node tests/run_ops_head_browser.cjs --fixtures-only
node tests/run_ops_head_browser.cjs --components-only
```

Database runners received explicit isolated TEST_DB_* settings, never application credentials. Each runner copied the application into its own temporary sandbox and enforced database ownership markers. The server was created in a new temporary directory on port 33381; its actual data directory and initial database inventory were verified before tests. Production `.env` was never loaded by these tests.

### Pre-existing PHPUnit blockers

Five failing methods in `HeadManagementNavigationTest`:

- `testExecutiveRecordTablesFollowClientManagementWorkspace`
- `testTripAndApprovalsUseSharedClientRecordComponents`
- `testFuelPageUsesFourActualKpisAndSharedConfigurationTabs`
- `testHeadExecutiveReportsUsesFourPrimaryIndicatorsAndOriginalAggregates`
- `testHeadCompanyCommandCenterReusesVerifiedReadOnlySources`

Two failing methods in `PricingAuthorizationTest`:

- `testRouteRateModalExplainsMissingVerifiedFuelSources`
- `testExistingClientRouteMapUsesOnlyCurrentVerifiedGarageGeometry`

The two warnings are undefined `$u` and `$sections` variables in HeadManagementNavigationTest's source assertions. These baseline tests were not rewritten to manufacture a passing release gate.

## Database and authentication review

`mulawin_v2` was neither connected to nor changed. No operational migrations, seeders, demo utilities, or recovery scripts were executed. Test fixtures, schema construction, migration failure/recovery tests and cleanup ran only on the separately created disposable server. No operational records were copied into it.

Five incoming migration IDs require a separate operational deployment decision:

1. `2026-11-10_10_mechanic_attendance`: new session/backup tables, availability backup and updates, and a changed default. This is **not exclusively additive** to existing mechanic data.
2. `2026-11-10_11_daily_attendance`: new daily table and copy of legacy attendance marks; requires the existing attendance migration. Original shift marks remain historical records.
3. `2026-11-12_12_job_helper_mechanics`: additional mechanic assignments and constraints.
4. `2026-11-12_13_stock_count_reconciliation`: reviewed stock counts and movement relationships.
5. `2026-11-14_14_incident_clearance`: nullable incident clearance/resumption fields and constraints; depends on the fixed-route/fuel schema.

Some numerical prefixes overlap Operations migrations, but their full IDs are distinct. Do not choose migrations by number alone. DDL may implicitly commit; do not blindly rerun a partially applied file. Existing operational definitions and migration records were not inspected in this task, so no claim is made that these files are needed or safe to apply as-is on the operational database. Historical handoff documents are not current schema evidence.

The candidate does not change `AccountSecurity.php`, `SecurityPasswordChange.php`, `bootstrap.php` or `Data.php`. `Otp.php` and `TrustedDevices.php` were already absent in the target; this merge did not delete them. Existing authentication/forced-change/session controls were retained, and account-security checks passed.

## Remaining issues and next steps

1. Resolve or explicitly review the seven baseline PHPUnit failures, warnings, route-suite assumptions, and the Operations browser fixture timeout before considering a PR.
2. Review Luis's supplementary Admin Oversight pages against the newer executive Action Center. They are retained and backend-registered, but were not inserted into the approved executive menu. Their complete user-facing navigation/decision coverage is not claimed as verified.
3. Review any operational schema deployment separately, with actual schema inspection, explicit authorization, protected backup and disposable restore verification. No automatic migration is part of this candidate.
4. After all required gates pass, push **only** `integration/luis-safe-merge-20261114` and create/reuse a PR with base `integration/all-dev-20261114`. Do not merge automatically.

After a future PR has been approved and merged, pull the target with:

```powershell
git fetch origin
git switch integration/all-dev-20261114
git pull --ff-only origin integration/all-dev-20261114
```

## Changed-file inventory

The inventory below is relative to original target `bb308e9`, before this report was added. The report itself is an additional file.

```text
A	.gitattributes
M	.htaccess
M	CHANGELOG.md
A	CLAUDE.md
M	backend/Actions.php
M	backend/Core/Access.php
M	backend/Core/CrewAttendance.php
M	backend/Core/DispatcherAttendance.php
M	backend/Core/FleetState.php
M	backend/Core/Notify.php
M	backend/Core/Settings.php
M	backend/Core/SignatureAccess.php
M	backend/Modules/Accounting/Billing.php
M	backend/Modules/Accounting/Finance.php
M	backend/Modules/Administration/Admin.php
M	backend/Modules/Administration/Attendance.php
A	backend/Modules/Administration/Oversight.php
M	backend/Modules/General/Analytics.php
M	backend/Modules/General/Documents.php
M	backend/Modules/Maintenance/Maintenance.php
M	backend/Modules/Maintenance/Mechanics.php
M	backend/Modules/Maintenance/Purchasing.php
M	backend/Modules/Operations/Fleet.php
M	backend/Modules/Operations/Trips.php
M	database/extension.sql
A	database/migrations/2026-11-10_10_mechanic_attendance.sql
A	database/migrations/2026-11-10_11_daily_attendance.sql
A	database/migrations/2026-11-12_12_job_helper_mechanics.sql
A	database/migrations/2026-11-12_13_stock_count_reconciliation.sql
A	database/migrations/2026-11-14_14_incident_clearance.sql
A	database/migrations/checks/2026-11-10_10_mechanic_attendance_check.sql
A	database/migrations/checks/2026-11-10_11_daily_attendance_check.sql
A	database/migrations/recovery/2026-11-10_10_mechanic_attendance_finish.sql
A	database/migrations/recovery/2026-11-10_11_daily_attendance_finish.sql
A	database/migrations/rollback/2026-11-10_10_mechanic_attendance_rollback.sql
A	database/migrations/rollback/2026-11-10_11_daily_attendance_rollback.sql
A	database/migrations/rollback/2026-11-12_12_job_helper_mechanics_rollback.sql
A	database/migrations/rollback/2026-11-12_13_stock_count_reconciliation_rollback.sql
A	database/migrations/rollback/2026-11-14_14_incident_clearance_rollback.sql
A	docs/CLAUDE_PHASE2_MIGRATIONS.md
A	docs/HEAD_MANAGEMENT_COORDINATION.md
A	docs/MIGRATION_ORDER_AND_HANDOFF.md
A	docs/MOTORPOOL_PHASE1_HANDOFF.md
A	docs/MULAWIN_UI_WIREFRAME_IMPLEMENTATION_SPEC.md
A	docs/OBJECTIVES_TRACEABILITY.md
A	docs/UAT_ROLE_TASK_MATRIX.md
A	docs/VISUAL_TEST_ENVIRONMENT.md
A	docs/ui-reference/REFERENCE_GUIDE.md
A	docs/ui-reference/head-management/head_management_wireframe.html
A	docs/ui-reference/maintenance_reference.html
A	docs/ui-reference/motorpool-reports/motorpool_reports_reference.html
M	public/assets/css/app.css
A	public/assets/css/workspace.css
M	public/assets/js/attendance.js
M	public/assets/js/core.js
M	public/assets/js/modules/accounting/billing.js
M	public/assets/js/modules/accounting/finance.js
M	public/assets/js/modules/administration/admin.js
A	public/assets/js/modules/administration/audit.js
A	public/assets/js/modules/administration/oversight.js
M	public/assets/js/modules/general/dashboard.js
M	public/assets/js/modules/general/reports.js
A	public/assets/js/modules/maintenance/history.js
M	public/assets/js/modules/maintenance/maintenance.js
M	public/assets/js/modules/maintenance/mechanics.js
M	public/assets/js/modules/maintenance/purchasing.js
M	public/assets/js/modules/operations/fleet.js
M	public/assets/js/modules/operations/trips.js
M	public/index.php
A	scripts/demo_workflow_cleanup.php
A	scripts/demo_workflow_seed.php
A	scripts/dev_setup_accounts.php
A	scripts/showcase/README.md
A	scripts/showcase/lib_sim.php
A	scripts/showcase/lib_verify.php
A	scripts/showcase/populate.php
A	scripts/showcase/run_showcase.php
A	scripts/showcase/scenarios.php
A	scripts/showcase/switch_env.php
A	scripts/visual_test_seed.php
M	tests/README.md
M	tests/_launcher.php
M	tests/account_security_tests.php
A	tests/accounting_workspace_tests.php
M	tests/billing_adjustment_tests.php
M	tests/crew_attendance_tests.php
D	tests/crew_dispatch_tests.php
A	tests/dispatch_attendance_tests.php
M	tests/dispatcher_tests.php
A	tests/head_management_tests.php
A	tests/incident_clearance_tests.php
A	tests/integration_workflow_tests.php
M	tests/inventory_finance_tests.php
A	tests/job_helpers_tests.php
A	tests/maintenance_overview_tests.php
A	tests/maintenance_report_rules_tests.php
M	tests/map_tests.php
A	tests/mechanic_attendance_tests.php
A	tests/motorpool_workspace_tests.php
M	tests/operations_v43_tests.php
A	tests/phase2_migration_safety_tests.php
M	tests/pricing_tests.php
A	tests/purchasing_finance_tests.php
A	tests/purchasing_report_rules_tests.php
A	tests/role_action_probe.php
M	tests/route_tests.php
R051	tests/run_crew_dispatch_tests.php	tests/run_accounting_workspace_tests.php
M	tests/run_crew_attendance_tests.php
A	tests/run_dispatch_attendance_tests.php
A	tests/run_head_management_tests.php
A	tests/run_incident_clearance_tests.php
A	tests/run_integration_workflow_tests.php
A	tests/run_job_helpers_tests.php
A	tests/run_maintenance_overview_tests.php
A	tests/run_mechanic_attendance_tests.php
A	tests/run_motorpool_workspace_tests.php
A	tests/run_phase2_migration_safety_tests.php
A	tests/run_purchasing_finance_tests.php
A	tests/run_role_action_probe.php
A	tests/run_stock_count_tests.php
A	tests/run_uat_browser_scenario.php
A	tests/run_uat_gap_tests.php
A	tests/stock_count_tests.php
M	tests/system_components_ui.html
M	tests/trip_approval_tests.php
M	tests/trip_batch_tests.php
M	tests/trip_documents_tests.php
M	tests/trip_routing_tests.php
M	tests/trip_schedule_tests.php
M	tests/turnover_tests.php
A	tests/uat_browser_scenario.php
A	tests/uat_gap_tests.php
A	tests/workspace_access_tests.php
```
