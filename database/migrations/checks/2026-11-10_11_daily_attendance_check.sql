-- READ-ONLY checks for migration 2026-11-10_11_daily_attendance. They change nothing; safe to run any time.
-- Run each numbered query in phpMyAdmin (SQL tab) on the FleetOps database, one at a time.
--
-- ===== SECTION 1: BEFORE the migration (write the results down) =====
-- 1a. Server version. Needs MariaDB 10.2.1+ or MySQL 8.0.16+ (enforced CHECK).
SELECT VERSION() AS server_version, @@SESSION.sql_mode AS sql_mode;
-- 1b. Must be 1 / 1 / 0 / 0 (migration 08 recorded, attendance_marks present, new table absent, this migration not recorded).
SELECT
  (SELECT COUNT(*) FROM schema_migrations WHERE id = '2026-11-08_08_attendance') AS migration_08_recorded,
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'attendance_marks') AS attendance_marks_exists,
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'attendance_daily') AS attendance_daily_exists,
  (SELECT COUNT(*) FROM schema_migrations WHERE id = '2026-11-10_11_daily_attendance') AS recorded;
-- 1c. Legacy data must only contain the shifts Day and Night (anything else would not fit the copy). Expect Day / Night rows only.
SELECT duty_shift, COUNT(*) AS marks FROM attendance_marks GROUP BY duty_shift;
-- 1d. Baseline numbers. legacy_marks and the checksum must be identical after the migration; expected_daily_rows is what the copy must produce.
SELECT (SELECT COUNT(*) FROM attendance_marks) AS legacy_marks,
       (SELECT COUNT(*) FROM (SELECT 1 FROM attendance_marks GROUP BY duty_date, personnel_id, mechanic_id) g) AS expected_daily_rows,
       (SELECT COUNT(*) FROM (SELECT 1 FROM attendance_marks GROUP BY duty_date, personnel_id, mechanic_id HAVING COUNT(*) > 1) g) AS days_with_two_shift_marks,
       (SELECT SUM(CRC32(CONCAT_WS('|', id, duty_date, duty_shift, IFNULL(personnel_id, '-'), IFNULL(mechanic_id, '-'), status, marked_by, marked_at))) FROM attendance_marks) AS legacy_checksum,
       (SELECT SUM(duty_shift IS NOT NULL) FROM personnel) AS personnel_with_legacy_shift,
       (SELECT SUM(duty_shift IS NOT NULL) FROM mechanics) AS mechanics_with_legacy_shift;

-- 1e. Foreign-key column types: users, personnel and mechanics must all be signed INT (is_unsigned = 0), like the new columns.
SELECT table_name, column_type, (column_type LIKE '%unsigned%') AS is_unsigned FROM information_schema.columns
WHERE table_schema = DATABASE() AND column_name = 'id' AND table_name IN ('users','personnel','mechanics');

-- ===== SECTION 2: STATE (after the migration, or any time) =====
--   COMPLETE      table present and migration recorded. Nothing to do (also run section 3).
--   NOT APPLIED   nothing applied. Import database/migrations/2026-11-10_11_daily_attendance.sql.
--   PARTIAL       the table exists but the migration is not recorded (the import stopped after step 1 or 2).
--                 Import database/migrations/recovery/2026-11-10_11_daily_attendance_finish.sql.
--                 Do NOT import the migration file again.
--   UNEXPECTED    stop. Run no migration statement. See docs/CLAUDE_PHASE2_MIGRATIONS.md ("UNEXPECTED").
SELECT table_exists, table_columns, unique_rules, recorded,
  CASE
    WHEN recorded = 1 AND table_exists = 1 AND table_columns = 8 AND unique_rules = 2 THEN 'COMPLETE'
    WHEN recorded = 0 AND table_exists = 0 THEN 'NOT APPLIED'
    WHEN recorded = 0 AND table_exists = 1 AND table_columns = 8 AND unique_rules = 2 THEN 'PARTIAL'
    ELSE 'UNEXPECTED'
  END AS state
FROM (SELECT
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'attendance_daily') AS table_exists,
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'attendance_daily'
     AND column_name IN ('id','duty_date','personnel_id','mechanic_id','status','marked_by','marked_at','legacy_shifts')) AS table_columns,
  (SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'attendance_daily'
     AND index_name IN ('uq_attd_person','uq_attd_mech') AND non_unique = 0) AS unique_rules,
  (SELECT COUNT(*) FROM schema_migrations WHERE id = '2026-11-10_11_daily_attendance') AS recorded
) s;

-- ===== SECTION 3: VERIFICATION (run ONLY when the table exists) =====
-- 3a. legacy_marks and legacy_checksum must equal query 1d (the old rows are untouched).
SELECT COUNT(*) AS legacy_marks,
       SUM(CRC32(CONCAT_WS('|', id, duty_date, duty_shift, IFNULL(personnel_id, '-'), IFNULL(mechanic_id, '-'), status, marked_by, marked_at))) AS legacy_checksum
FROM attendance_marks;
-- 3b. copied_days must equal expected_daily_rows of query 1d (right after the migration, before the new code is used).
SELECT COUNT(*) AS copied_days, SUM(legacy_shifts = 'Day+Night') AS merged_two_shift_days, SUM(legacy_shifts IS NULL) AS marks_made_after_migration
FROM attendance_daily;
-- 3c. Must return 0 rows: a legacy day that has no daily row. After the new code has been used, a row here can only be a
--     mark the Admin cleared on purpose; right after the migration it means the copy is incomplete (use the recovery file).
SELECT g.duty_date, g.personnel_id, g.mechanic_id
FROM (SELECT duty_date, personnel_id, mechanic_id FROM attendance_marks GROUP BY duty_date, personnel_id, mechanic_id) g
WHERE NOT EXISTS (SELECT 1 FROM attendance_daily d WHERE d.duty_date = g.duty_date AND d.personnel_id <=> g.personnel_id AND d.mechanic_id <=> g.mechanic_id);
-- 3d. Must return 0 rows: a copied day whose status differs from the rule (Present if any legacy mark was Present, else Absent).
SELECT d.id, d.duty_date, d.personnel_id, d.mechanic_id, d.status AS daily_status
FROM attendance_daily d
WHERE d.legacy_shifts IS NOT NULL
  AND d.status <> (SELECT CASE WHEN SUM(m.status = 'Present') > 0 THEN 'Present' ELSE 'Absent' END FROM attendance_marks m
                    WHERE m.duty_date = d.duty_date AND m.personnel_id <=> d.personnel_id AND m.mechanic_id <=> d.mechanic_id);
-- 3e. personnel_with_legacy_shift and mechanics_with_legacy_shift must equal query 1d (no legacy shift value was touched).
SELECT (SELECT SUM(duty_shift IS NOT NULL) FROM personnel) AS personnel_with_legacy_shift,
       (SELECT SUM(duty_shift IS NOT NULL) FROM mechanics) AS mechanics_with_legacy_shift;
