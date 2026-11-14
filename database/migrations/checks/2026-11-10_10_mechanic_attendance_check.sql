-- READ-ONLY checks for migration 2026-11-10_10_mechanic_attendance. They change nothing; safe to run any time.
-- Run each numbered query in phpMyAdmin (SQL tab) on the FleetOps database, one at a time.
--
-- ===== SECTION 1: BEFORE the migration (write the results down) =====
-- 1a. Server version. Needs MariaDB 10.2.1+ or MySQL 8.0.16+ (STORED generated column and enforced CHECK).
SELECT VERSION() AS server_version, @@SESSION.sql_mode AS sql_mode;
-- 1b. Prerequisites. Expect 2 rows, both InnoDB, is_unsigned = 0 (the new foreign-key columns are signed INT, like mechanics.id / users.id).
SELECT t.table_name, t.engine, c.column_type AS id_type, (c.column_type LIKE '%unsigned%') AS is_unsigned
FROM information_schema.tables t JOIN information_schema.columns c
  ON c.table_schema = t.table_schema AND c.table_name = t.table_name AND c.column_name = 'id'
WHERE t.table_schema = DATABASE() AND t.table_name IN ('mechanics','users');
-- 1c. Must be 0 / 0 / 0 / 1 (both new tables absent, migration not recorded, mechanics.unavailable_reason exists).
SELECT
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'mechanic_attendance') AS table_exists,
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'mechanic_availability_backup') AS backup_table_exists,
  (SELECT COUNT(*) FROM schema_migrations WHERE id = '2026-11-10_10_mechanic_attendance') AS recorded,
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mechanics' AND column_name = 'unavailable_reason') AS reason_column;
-- 1d. Baseline numbers to compare after the migration.
SELECT COUNT(*) AS mechanics_total, SUM(availability = 'Available') AS available, SUM(availability = 'Assigned') AS assigned,
       SUM(availability = 'Unavailable') AS unavailable, SUM(duty_shift IS NOT NULL) AS with_legacy_shift,
       SUM(availability = 'Available' AND unavailable_reason IS NOT NULL) AS available_with_reason_kept_and_backed_up,
       SUM(CRC32(CONCAT_WS('|', id, IFNULL(unavailable_reason, '-')))) AS reason_checksum,
       SUM(CRC32(CONCAT_WS('|', id, mechanic_code, full_name, IFNULL(duty_shift, '-'), active))) AS identity_checksum
FROM mechanics;

-- ===== SECTION 2: STATE (after the migration, or any time) =====
--   COMPLETE      everything applied and recorded. Nothing to do (also run section 3).
--   NOT APPLIED   nothing applied. Import database/migrations/2026-11-10_10_mechanic_attendance.sql.
--   PARTIAL       mechanic_attendance exists but the migration is not recorded (the import stopped after step 1-5).
--                 Import database/migrations/recovery/2026-11-10_10_mechanic_attendance_finish.sql.
--                 Do NOT import the migration file again.
--   UNEXPECTED    stop. Run no migration statement. See docs/CLAUDE_PHASE2_MIGRATIONS.md ("UNEXPECTED"), in particular the case
--                 "recorded but mechanic_availability_backup is missing" (an earlier draft of this migration was run).
SELECT table_exists, table_columns, one_open_rule, backup_exists, backup_columns, status_default, recorded,
  CASE
    WHEN recorded = 1 AND table_exists = 1 AND table_columns = 7 AND one_open_rule = 1 AND backup_exists = 1 AND backup_columns = 4
         AND status_default LIKE '%Unavailable%' THEN 'COMPLETE'
    WHEN recorded = 0 AND table_exists = 0 AND backup_exists = 0 AND (status_default IS NULL OR status_default NOT LIKE '%Unavailable%') THEN 'NOT APPLIED'
    WHEN recorded = 0 AND table_exists = 1 AND table_columns = 7 AND one_open_rule = 1 AND (backup_exists = 0 OR backup_columns = 4) THEN 'PARTIAL'
    ELSE 'UNEXPECTED'
  END AS state
FROM (SELECT
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'mechanic_attendance') AS table_exists,
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mechanic_attendance'
     AND column_name IN ('id','mechanic_id','time_in','time_out','time_in_by','time_out_by','open_mechanic')) AS table_columns,
  (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'mechanic_attendance'
     AND index_name = 'uq_mattn_one_open' AND non_unique = 0) AS one_open_rule,
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'mechanic_availability_backup') AS backup_exists,
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mechanic_availability_backup'
     AND column_name IN ('mechanic_id','original_availability','original_unavailable_reason','backed_up_at')) AS backup_columns,
  (SELECT column_default FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mechanics'
     AND column_name = 'availability') AS status_default,
  (SELECT COUNT(*) FROM schema_migrations WHERE id = '2026-11-10_10_mechanic_attendance') AS recorded
) s;

-- ===== SECTION 3: VERIFICATION (run ONLY when the state is COMPLETE; needs both tables) =====
-- 3a. Same mechanics as before: mechanics_total, with_legacy_shift, identity_checksum AND reason_checksum must equal query 1d
--     (unavailable_reason is never written by this migration, so reason_checksum must be identical).
SELECT COUNT(*) AS mechanics_total, SUM(duty_shift IS NOT NULL) AS with_legacy_shift,
       SUM(CRC32(CONCAT_WS('|', id, IFNULL(unavailable_reason, '-')))) AS reason_checksum,
       SUM(CRC32(CONCAT_WS('|', id, mechanic_code, full_name, IFNULL(duty_shift, '-'), active))) AS identity_checksum
FROM mechanics;
-- 3b. Must return 0 rows (if rows appear, run step 4 of the recovery file: it only touches mechanics that have a backup row and no open Time In): a mechanic shown Available without an open Time In.
SELECT m.id, m.full_name, m.availability FROM mechanics m
WHERE m.availability = 'Available' AND NOT EXISTS (SELECT 1 FROM mechanic_attendance a WHERE a.mechanic_id = m.id AND a.time_out IS NULL);
-- 3c. Mechanics changed by the migration = rows in the backup table. Right after the migration it equals "available" of query 1d.
SELECT COUNT(*) AS backed_up_mechanics, SUM(original_unavailable_reason IS NOT NULL) AS backed_up_with_reason FROM mechanic_availability_backup;
-- 3d. Must return 0 rows: a backup row that does not hold an 'Available' original (the migration only ever saves Available mechanics).
SELECT * FROM mechanic_availability_backup WHERE original_availability <> 'Available';
-- 3e. Must return 0 rows right after the migration: the reason of every backed-up mechanic is still its original reason.
SELECT m.id, m.unavailable_reason AS current_reason, b.original_unavailable_reason
FROM mechanics m JOIN mechanic_availability_backup b ON b.mechanic_id = m.id
WHERE NOT (m.unavailable_reason <=> b.original_unavailable_reason) AND m.availability = 'Unavailable' AND NOT EXISTS (SELECT 1 FROM mechanic_attendance a WHERE a.mechanic_id = m.id);
-- 3f. Leftover of an earlier draft of this migration (it wrote marker text into unavailable_reason). Expect 0.
SELECT COUNT(*) AS earlier_draft_marker_rows FROM mechanics WHERE unavailable_reason = 'Not timed in (migration)';
-- 3g. The new table is empty right after the migration (rows appear only when someone clicks Time In).
SELECT COUNT(*) AS time_in_out_rows FROM mechanic_attendance;
