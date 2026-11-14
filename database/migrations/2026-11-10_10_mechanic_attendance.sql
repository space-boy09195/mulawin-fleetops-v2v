-- MECHANIC_ATTENDANCE_V1  (Maintenance: Mechanic Attendance, Time In / Time Out)
-- Fresh install: already in extension.sql (do NOT import this file). Existing DB: back up, then import this ONCE,
-- BEFORE deploying the new PHP/JS.
--
-- BEFORE:  run section 1 of database/migrations/checks/2026-11-10_10_mechanic_attendance_check.sql (read-only) and keep its numbers.
-- AFTER:   run sections 2 and 3 of the same file. Read the "state" column.
-- Import with a client that STOPS at the first error (phpMyAdmin default; mysql WITHOUT --force). Never "ignore errors".
--
-- DATA PRESERVATION: mechanics.availability and mechanics.unavailable_reason are NEVER used as a marker and unavailable_reason is
-- NEVER written by this migration. Before any mechanic is changed, its ORIGINAL availability and unavailable_reason are copied into
-- the new table mechanic_availability_backup (one row per changed mechanic). The rollback restores them from that table.
--
-- SAFE ORDER (MariaDB / MySQL DDL is not transactional; every statement commits on its own):
--   STEP 0  Session setting only (strict mode, so a data problem is an error and never a silent truncation).
--   STEP 1  ONE CREATE TABLE mechanic_attendance. All-or-nothing: if it fails the table does not exist and nothing else changed.
--           Importing this file again stops HERE ("table already exists") without changing anything.
--   STEP 2  ONE CREATE TABLE mechanic_availability_backup (all-or-nothing).
--   STEP 3  ONE INSERT ... SELECT copies the ORIGINAL availability + unavailable_reason of every mechanic that step 4 will change
--           (Available, no open Time In). Anti-join: safe to repeat, never overwrites a saved original, does nothing once recorded.
--   STEP 4  ONE UPDATE sets availability='Unavailable' ONLY for mechanics that have a backup row (all-or-nothing, safe to repeat,
--           never touches a mechanic with an open Time In). unavailable_reason and every other column are untouched.
--   STEP 5  Column default for new mechanics. Safe to repeat.
--   STEP 6  The migration is recorded LAST, only after steps 1-5 succeeded.
-- If the import stops after any of steps 1-5 (state PARTIAL in the check file): do NOT import this file again.
-- Import database/migrations/recovery/2026-11-10_10_mechanic_attendance_finish.sql instead. See docs/CLAUDE_PHASE2_MIGRATIONS.md.
-- Rollback plan (restores the originals): database/migrations/rollback/2026-11-10_10_mechanic_attendance_rollback.sql

-- STEP 0
SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES');

-- STEP 1
CREATE TABLE mechanic_attendance (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  mechanic_id INT NOT NULL,
  time_in DATETIME NOT NULL,
  time_out DATETIME NULL,
  time_in_by INT NOT NULL,
  time_out_by INT NULL,
  open_mechanic INT AS (CASE WHEN time_out IS NULL THEN mechanic_id ELSE NULL END) STORED COMMENT 'one open Time In per mechanic (database rule)',
  UNIQUE KEY uq_mattn_one_open (open_mechanic),
  INDEX idx_mattn_mechanic (mechanic_id, time_in),
  CONSTRAINT fk_mattn_mechanic FOREIGN KEY (mechanic_id) REFERENCES mechanics(id),
  CONSTRAINT fk_mattn_in_by FOREIGN KEY (time_in_by) REFERENCES users(id),
  CONSTRAINT fk_mattn_out_by FOREIGN KEY (time_out_by) REFERENCES users(id),
  CONSTRAINT chk_mattn_order CHECK (time_out IS NULL OR time_out >= time_in)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- STEP 2
CREATE TABLE mechanic_availability_backup (
  mechanic_id INT NOT NULL PRIMARY KEY,
  original_availability VARCHAR(15) NOT NULL,
  original_unavailable_reason VARCHAR(150) NULL,
  backed_up_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_mavb_mechanic FOREIGN KEY (mechanic_id) REFERENCES mechanics(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='originals of mechanics set Unavailable by migration 2026-11-10_10; used by its rollback';

-- STEP 3
INSERT INTO mechanic_availability_backup (mechanic_id, original_availability, original_unavailable_reason)
SELECT m.id, m.availability, m.unavailable_reason FROM mechanics m
 WHERE m.availability = 'Available'
   AND NOT EXISTS (SELECT 1 FROM schema_migrations r WHERE r.id = '2026-11-10_10_mechanic_attendance')
   AND NOT EXISTS (SELECT 1 FROM mechanic_attendance a WHERE a.mechanic_id = m.id AND a.time_out IS NULL)
   AND NOT EXISTS (SELECT 1 FROM mechanic_availability_backup b WHERE b.mechanic_id = m.id);

-- STEP 4
UPDATE mechanics SET availability = 'Unavailable'
 WHERE availability = 'Available'
   AND id IN (SELECT b.mechanic_id FROM mechanic_availability_backup b)
   AND NOT EXISTS (SELECT 1 FROM mechanic_attendance a WHERE a.mechanic_id = mechanics.id AND a.time_out IS NULL);

-- STEP 5
ALTER TABLE mechanics ALTER COLUMN availability SET DEFAULT 'Unavailable';

-- STEP 6 (last)
INSERT INTO schema_migrations (id) VALUES ('2026-11-10_10_mechanic_attendance');
