-- RECOVERY for migration 2026-11-10_10_mechanic_attendance: use ONLY when the check file says PARTIAL
-- (step 1, the CREATE TABLE mechanic_attendance, succeeded but the migration is not recorded).
-- It finishes steps 2-6 of the migration; every statement is safe to repeat and it never repeats step 1.
-- ORIGINAL VALUES: a mechanic's original availability / unavailable_reason is saved BEFORE it is changed and is never overwritten
-- (anti-join), so running this file after a stop at any step cannot lose them. unavailable_reason is never written.
-- If step 1 did NOT run, the first statement fails ("table mechanic_attendance doesn't exist") and nothing is changed or recorded.
-- If the migration is already COMPLETE, the last statement fails ("Duplicate entry") and nothing else changes.
-- Import with a client that stops at the first error. Then run sections 2 and 3 of the check file.

SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES');

-- GUARD: fails if step 1 never ran (nothing below is executed)
DO (SELECT 1 FROM mechanic_attendance LIMIT 1);

-- STEP 2 (only if it did not run yet)
CREATE TABLE IF NOT EXISTS mechanic_availability_backup (
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
