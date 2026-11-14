-- DAILY_ATTENDANCE_V1  (Operations Attendance without permanent Day/Night shifts)
-- Fresh install: already in extension.sql (do NOT import this file). Existing DB: back up, then import this ONCE,
-- BEFORE deploying the new PHP/JS. Requires migration 2026-11-08_08_attendance (attendance_marks).
--
-- BEFORE:  run section 1 of database/migrations/checks/2026-11-10_11_daily_attendance_check.sql (read-only) and keep its numbers.
-- AFTER:   run sections 2 and 3 of the same file. Read the "state" column.
-- Import with a client that STOPS at the first error (phpMyAdmin default; mysql WITHOUT --force). Never "ignore errors".
--
-- ADDITIVE ONLY: one new table + a COPY of the existing marks. attendance_marks, personnel.duty_shift and
-- mechanics.duty_shift are NOT altered, dropped, renamed or emptied: they stay as the untouched historical archive.
--
-- How existing records are interpreted: attendance is now one mark per person (driver/helper or mechanic) per calendar date
-- (Philippine time). Each legacy (date, shift, person) mark is copied into attendance_daily, grouped by (date, person):
--   * only one legacy mark that day            -> same status, legacy_shifts = 'Day' or 'Night'
--   * a Day and a Night mark for the same day  -> ONE daily row: Present if ANY of them was Present, otherwise Absent;
--                                                 legacy_shifts = 'Day+Night' (both original rows remain in attendance_marks)
--   * marked_by / marked_at come from the most recent legacy mark of that day.
--
-- SAFE ORDER (MariaDB / MySQL DDL is not transactional; every statement commits on its own):
--   STEP 0  Session setting only (strict mode: a value that does not fit is an error, never a silent truncation).
--   STEP 1  ONE CREATE TABLE. All-or-nothing: if it fails the table does not exist and nothing else changed.
--           Importing this file again stops HERE ("table already exists") without changing anything.
--   STEP 2  ONE INSERT ... SELECT copies the marks. It is a single statement, so it is all-or-nothing (a failure leaves the new
--           table EMPTY, never half-filled). It only adds missing days (anti-join) and does nothing once the migration is
--           recorded, so it is safe to repeat and can never duplicate or overwrite a row.
--   STEP 3  The migration is recorded LAST, only after steps 1-2 succeeded.
-- If the import stops after step 1 or 2 (state PARTIAL in the check file): do NOT import this file again.
-- Import database/migrations/recovery/2026-11-10_11_daily_attendance_finish.sql instead. See docs/CLAUDE_PHASE2_MIGRATIONS.md.
-- Rollback plan: database/migrations/rollback/2026-11-10_11_daily_attendance_rollback.sql

-- STEP 0
SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES');

-- STEP 1
CREATE TABLE attendance_daily (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  duty_date DATE NOT NULL,
  personnel_id INT NULL,
  mechanic_id INT NULL,
  status VARCHAR(7) NOT NULL,
  marked_by INT NOT NULL,
  marked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  legacy_shifts VARCHAR(9) NULL COMMENT 'Day, Night or Day+Night when copied from attendance_marks; NULL for marks made after the migration',
  UNIQUE KEY uq_attd_person (duty_date,personnel_id),
  UNIQUE KEY uq_attd_mech (duty_date,mechanic_id),
  INDEX idx_attd_date (duty_date),
  CONSTRAINT fk_attd_person FOREIGN KEY (personnel_id) REFERENCES personnel(id),
  CONSTRAINT fk_attd_mech FOREIGN KEY (mechanic_id) REFERENCES mechanics(id),
  CONSTRAINT fk_attd_user FOREIGN KEY (marked_by) REFERENCES users(id),
  CONSTRAINT chk_attd_kind CHECK ((personnel_id IS NOT NULL AND mechanic_id IS NULL) OR (personnel_id IS NULL AND mechanic_id IS NOT NULL)),
  CONSTRAINT chk_attd_status CHECK (status IN ('Present','Absent'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- STEP 2
INSERT INTO attendance_daily (duty_date, personnel_id, mechanic_id, status, marked_by, marked_at, legacy_shifts)
SELECT g.duty_date, g.personnel_id, g.mechanic_id, g.status, g.marked_by, g.marked_at, g.legacy_shifts
FROM (
  SELECT m.duty_date, m.personnel_id, m.mechanic_id,
         CASE WHEN SUM(m.status = 'Present') > 0 THEN 'Present' ELSE 'Absent' END AS status,
         (SELECT x.marked_by FROM attendance_marks x
           WHERE x.duty_date = m.duty_date AND x.personnel_id <=> m.personnel_id AND x.mechanic_id <=> m.mechanic_id
           ORDER BY x.marked_at DESC, x.id DESC LIMIT 1) AS marked_by,
         MAX(m.marked_at) AS marked_at,
         GROUP_CONCAT(DISTINCT m.duty_shift ORDER BY m.duty_shift SEPARATOR '+') AS legacy_shifts
  FROM attendance_marks m
  GROUP BY m.duty_date, m.personnel_id, m.mechanic_id
) g
WHERE NOT EXISTS (SELECT 1 FROM schema_migrations r WHERE r.id = '2026-11-10_11_daily_attendance')
  AND NOT EXISTS (SELECT 1 FROM attendance_daily d
                   WHERE d.duty_date = g.duty_date AND d.personnel_id <=> g.personnel_id AND d.mechanic_id <=> g.mechanic_id);

-- STEP 3 (last)
INSERT INTO schema_migrations (id) VALUES ('2026-11-10_11_daily_attendance');
