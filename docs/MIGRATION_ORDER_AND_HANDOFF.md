# Pending migrations on `mulawin_v2`: order, ownership and integration handoff

Prepared 2026-11-12 on branch `luis-maintenance-ui`. Evidence comes from read-only queries against `mulawin_v2`, read-only page reads, and isolated `*_test` suites. **No migration was applied to `mulawin_v2`.**

## 1. State of `mulawin_v2` (read-only check)

| Migration | Recorded in `schema_migrations` | Objects present | Owner |
|---|---|---|---|
| 07 `trip_routing` (`trips.distance_*`, `client_route_id`, `trip_routes`) | no | no | **Operations** (trips / routes / map). Commit decfe64. |
| 08 `attendance` (`personnel.duty_shift`, `mechanics.duty_shift`, `attendance_marks`) | no | no | **Operations / Admin** (crew attendance). Commit 93f3d71. |
| 09 `billing_adjustments` (`billing_charges` approval columns + checks) | no | no | **Accounting (Billing)**. Commits 027c556, 920eae1. |
| 10 `mechanic_attendance` | yes | yes | Maintenance |
| 11 `daily_attendance` (`attendance_daily` + copy from `attendance_marks`) | **no** | **`attendance_daily` exists** (same structure as the fresh install; 0 rows) | Admin (attendance) |
| 12 `job_helper_mechanics` (new, this branch) | no | no | Maintenance (this branch) |

Data that the pending migrations would touch: `trips` = 0 rows, `billings` = 0, `billing_charges` = 0, `attendance_daily` = 0, `personnel` / `mechanics` are not changed except for the new nullable `duty_shift` column.

## 2. Impact on the current code (read-only page reads on `mulawin_v2`)

- **Finance:** `finance`, `funds`, `reports` and `dashboard` reads work. Nothing in Finance depends on 07, 08 or 09.
- **Billing:** two sections fail on `mulawin_v2`. Every other part of the page still loads.
  - `billings` fails: `Unknown column 'bc.status'`.
  - `adjustments` fails: `Unknown column 'c.kind'`.

  The cause is migration 09. **Billing needs 09; it does not need 07 or 08.** `Billing.php` reads no `trips.distance_*`, `client_route_id` or `trip_routes`.
- **Operations (not modified here):** `Trips.php`, `Routes.php`, `RouteDistance.php`, `trips.js` and `routes.js` read the 07 columns. Trip scheduling with routes cannot work on `mulawin_v2` until 07 is applied. That decision belongs to the Operations developer.
- **08:** current code no longer reads `duty_shift` or `attendance_marks`. `CrewAttendance.php` and `Mechanics.php` say these are kept as untouched history. 08 exists so that 11 can copy old marks.
- **11:** do **not** import the file as it is. It begins with `CREATE TABLE attendance_daily`, and that table already exists (created outside the migration process, never recorded). The table structure is already correct. Recording 11 needs a separate reviewed step, only after 08, because 11 copies rows from `attendance_marks`.

## 3. Can Billing be built now with 09 unapplied?

Yes, with limits:
- **Code:** use the existing `Billing.php` contracts, which already expect the 09 columns. Every Billing screen keeps per-section failure handling, so a missing column shows "temporarily unavailable" and the rest of the page still loads.
- **Verification:** runtime checks of invoice adjustments, approvals and charges are possible only in isolated test databases built from `schema.sql` + `extension.sql`, which already include 09. Billing **cannot** be called verified on `mulawin_v2` until 09 is applied.
- **Data:** `mulawin_v2` has 0 trips and 0 billings, so the live Billing screens can only show empty states until real trips are completed.
- **Accounts:** `mulawin_v2` has **no active Billing user and no Audit user**. Browser checks of those workspaces need the owner to create the accounts (Admin > User Management). That is a write needing approval.

## 4. Recommended order (each step needs separate approval, a fresh backup and a restore test)

1. **09 `billing_adjustments`** (Accounting).
   - Independent of 07 and 08: it touches only `billing_charges`.
   - With 0 rows, its `UPDATE` changes nothing.
   - It drops and re-adds `chk_bcharge_amount` in the same `ALTER`.
   - This unblocks Billing.
2. **12 `job_helper_mechanics`** (this branch).
   - Creates one new table only.
   - Prepared and checked in a restored copy (section 5).
3. **07 `trip_routing`** and **08 `attendance`**, after the Operations developer confirms that their branch still uses these exact definitions. Same files, same order, before merging their branch.
4. **11 `daily_attendance`**: a reviewed "record-only" step, because the table already exists. Write it with the Admin/attendance owner. Do not run the existing file blindly.

Do **not** import any of these against `mulawin_v2` with `--force` or "ignore errors".

## 5. Migration 12 preparation: results

- **Backup:** `C:\xampp\fleetops_backups\2026-11-12_pre_mig12\`, outside Git.
  - `mulawin_v2_pre_mig12.sql`: 404,224 bytes, `mysqldump --single-transaction --routines --triggers --events --hex-blob`.
  - SHA-256 `f7a1142640d0b6f1220d123c6661023b1cd87e35b7a05c92dd3aced3527b6d48`.
  - `storage_private\`: 29 of 29 files.
- **Restore test** into the temporary `mulawin_v2_restorecheck_test`: 53 of 53 tables have identical row counts and `CHECKSUM TABLE` values (1,041 rows). Columns, indexes, foreign keys and check constraints are identical.
- **Migration on the restored copy:** exit 0. The only differences from `mulawin_v2` are:
  - the new table `maintenance_job_helpers`: 9 columns, 8 index entries, 4 foreign keys to `maintenance_jobs`, `mechanics` and `users` ×2, and the check `chk_mjh_release`;
  - one `schema_migrations` row.

  The other 52 tables are unchanged, row for row and checksum for checksum.
- **Second import:** stops with "Table 'maintenance_job_helpers' already exists" and changes nothing.
- **Isolated suite** `tests/run_job_helpers_tests.php`: 52 passed, 0 failed. It covers behaviour before the migration, migrated = fresh install, and the rules.
- **Clean-up:** the temporary restore database was dropped after the check, because it held a copy of real records.
- **Command to apply, only after explicit approval.** It must use a client that stops at the first error, with no `--force`:

  ```
  C:\xampp\mysql\bin\mysql.exe -uroot mulawin_v2 < database\migrations\2026-11-12_12_job_helper_mechanics.sql
  ```

- **Rollback:** `database/migrations/rollback/2026-11-12_12_job_helper_mechanics_rollback.sql`. It drops the table only while it has 0 rows; otherwise, restore the backup above.

## 6. Cross-branch integration notes for this branch

| Area | Change | Consumers checked | Conflict risk |
|---|---|---|---|
| `backend/Core/Access.php` | Purchasing nav labels only (`Overview`, `Reports`) | `workspace_access_tests` | Low (label map) |
| `public/assets/js/core.js` | Additive helpers: `UI.kpiTiles`, `UI.crumb`, `UI.ibtn`, `UI.generalFilters`, the `directActions` table option, the `.ws-scope` page class | Every page; the existing behaviour is unchanged unless the new options are used | Medium: shared file, so merge carefully |
| `public/assets/css/app.css` | Appended, scoped blocks (`.ws-scope`, `.kpi-*`, `.mo-*`, `.mech-*`, `.ws-*`) | Operations pages do not get `.ws-scope` | Low to medium (appended at the end) |
| `database/extension.sql` | Appended table of migration 12 + its `schema_migrations` row | Fresh installs, tests | Low |
| Maintenance / Mechanics / Purchasing / Finance / Analytics PHP | Described in the commit messages | Isolated suites listed in `tests/README.md` | Purchasing ↔ Finance money paths: re-run `run_purchasing_finance_tests.php` after any merge |

No Operations-owned file (`Trips.php`, `Routes.php`, `Fleet.php`, `trips.js`, `routes.js`, `fleet.js`, `clients.js`) was modified.
