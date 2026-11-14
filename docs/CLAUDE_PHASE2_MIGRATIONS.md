# Phase 2 database migrations: run, verify, recover

Migrations: `2026-11-10_10_mechanic_attendance` (needed by Mechanic Time In / Time Out) and
`2026-11-10_11_daily_attendance` (needed by shift-free Operations Attendance). Run **10 first, then 11**, on an EXISTING database only.
A fresh install (`schema.sql` + `extension.sql`) already contains both tables and the two records; do not import these files there.

## Rules
1. Back up the database first (Admin > Backup, or `mysqldump`). Import **before** deploying the new PHP/JS.
2. MariaDB / MySQL commit every DDL statement on its own (no rollback of `CREATE`/`ALTER`). The files are therefore built so that each
   step is a single all-or-nothing statement, the order is fixed, and the `schema_migrations` record is inserted **last**.
3. Import with a client that stops at the first error (phpMyAdmin default, `mysql` without `--force`). Never "ignore errors".
4. Never re-import a migration file after a failure. Read the **state** first (below), then use the matching action.

## Commands
Replace `DB`, `USER` as needed. In phpMyAdmin use Import / the SQL tab instead.

```
# BEFORE: run section 1 of each check file and write the numbers down
mysql -u USER -p DB < database/migrations/checks/2026-11-10_10_mechanic_attendance_check.sql   # prints every query; read sections 1-3 separately in phpMyAdmin
# RUN
mysql -u USER -p DB < database/migrations/2026-11-10_10_mechanic_attendance.sql
mysql -u USER -p DB < database/migrations/2026-11-10_11_daily_attendance.sql
# AFTER: state must say COMPLETE for both
mysql -u USER -p -e "SELECT id FROM schema_migrations WHERE id LIKE '2026-11-10%'" DB
```
Then run **section 2 (state)** and **section 3 (verification)** of each check file (see the comments in the files for the expected values).

## State -> action
| State | Meaning | Action |
|---|---|---|
| NOT APPLIED | nothing changed | import the migration file |
| PARTIAL | the new table exists, the record does not (import stopped part-way) | import `recovery/<id>_finish.sql` (never the migration file) |
| COMPLETE | applied and recorded | nothing; run section 3 |
| UNEXPECTED | table and record disagree, or a half-changed table | stop; run nothing; see below |

## Where a failure can leave the database
**10 mechanic_attendance** (step 1 CREATE attendance table, 2 CREATE backup table, 3 save originals, 4 UPDATE availability, 5 column default, 6 record)
- `mechanics.unavailable_reason` is **never written** and no existing field is used as a marker. Before a mechanic is changed (Available, no open Time In
  -> Unavailable), its ORIGINAL `availability` and `unavailable_reason` are copied to `mechanic_availability_backup`. The copy never overwrites a saved original.
- Fails in step 1 (missing FK table, wrong `id` type, old server): nothing changed. Fix the cause (check section 1), import again.
- Fails in step 2-5: `mechanic_attendance` exists, not recorded -> PARTIAL -> recovery file (guard, then steps 2-6; all repeatable, step 2 is `CREATE TABLE IF NOT EXISTS`).
- The step 4 UPDATE only touches mechanics that have a backup row and no open Time In, so the recovery file is safe even if the app was already used.
- Verification: section 3 of the check file (`reason_checksum` in 3a must equal 1d; 3b, 3d, 3e must return 0 rows).

**11 daily_attendance** (step 1 CREATE TABLE, 2 copy marks, 3 record)
- Fails in step 1: nothing changed.
- Fails in step 2 (one statement): the new table exists and is EMPTY, not recorded -> PARTIAL -> recovery file. Typical cause: `attendance_marks` missing (migration 08 not applied) or a legacy value that does not fit; fix it, then recovery.
- Step 2 only adds days that are missing and does nothing once the migration is recorded, so it never duplicates or overwrites.
- Old records are never changed: verify with `legacy_checksum` (section 1d vs 3a).

## UNEXPECTED
- **Recorded but the table does not exist** (you ran an earlier version of these files that recorded the migration FIRST and then failed):
  the record is false. Delete only that record, then import the migration file:
  `DELETE FROM schema_migrations WHERE id='2026-11-10_10_mechanic_attendance';` (or `..._11_daily_attendance`).
- **Recorded, table exists, but 11 section 3c returns rows right after the migration** (earlier version failed at the copy):
  `DELETE FROM schema_migrations WHERE id='2026-11-10_11_daily_attendance';` then import `recovery/2026-11-10_11_daily_attendance_finish.sql` (copies the missing days and records again). Do this only before the new code is used.
- **10 section 3b returns rows** (Available without Time In): run only step 4 (the UPDATE) from the recovery file.
- **10: recorded, but `mechanic_availability_backup` does not exist** (state UNEXPECTED; an earlier draft of migration 10 was run, which wrote the text
  `Not timed in (migration)` into `unavailable_reason` and overwrote any existing reason of those mechanics): the overwritten reasons are NOT in this
  database. Restore them from the backup taken before that run (compare `mechanics` there with the current table). Rows that still carry the marker text
  (check query 3f) had no reason before only if your pre-migration backup says so. Do not import any migration file until that is resolved.
- Anything else: restore the backup; do not edit tables by hand.

## Rollback
`database/migrations/rollback/<id>_rollback.sql` (the two are independent; roll back the application code too).
Export the new tables first (`mysqldump DB mechanic_attendance mechanic_availability_backup` / `attendance_daily`): the rollback drops them. The record is removed last.
- **10**: every mechanic that has a backup row AND is still Unavailable gets its ORIGINAL availability and unavailable_reason back. A mechanic timed in or
  assigned since is left alone; a mechanic with no backup row (created later, or never changed) is never touched. If it stops half-way, run the remaining
  statements; the restore UPDATE must run before the DROP statements. A rolled-back migration can be applied again.
- **11**: only the new table is dropped.
Legacy tables and columns (`attendance_marks`, `personnel.duty_shift`, `mechanics.duty_shift`) are never changed, so the old code works after a rollback.

## Foreign-key types
`users.id`, `personnel.id` and `mechanics.id` are signed `INT`; every new foreign-key column (`mechanic_id`, `personnel_id`, `time_in_by`, `time_out_by`, `marked_by`)
is signed `INT` too. Check 1b (migration 10) shows `is_unsigned = 0`; `tests/phase2_migration_safety_tests.php` compares all 7 foreign keys with their referenced columns.
