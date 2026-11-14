-- ROLLBACK of 2026-11-10_10_mechanic_attendance. Run ONLY if you must undo the migration.
-- WARNING: this drops mechanic_attendance (all Time In / Time Out history) and mechanic_availability_backup. Export both first:
--   mysqldump <database> mechanic_attendance mechanic_availability_backup > mechanic_attendance_backup.sql
-- RESTORE RULE: every mechanic that has a row in mechanic_availability_backup AND is still Unavailable gets its ORIGINAL
-- availability and unavailable_reason back (the values saved by the migration). A mechanic that was timed in since (Available /
-- Assigned) is left alone. Mechanics the migration never changed have no backup row and are never touched.
-- If it stops half-way, run only the statements that did not run yet (ALTER and the DROP TABLE IF EXISTS lines are safe to repeat;
-- the restore UPDATE needs the backup table, so run it BEFORE the drops and never again after them).
-- Order matters: restore first, drop the tables after, remove the schema_migrations record LAST.
-- The application code of this phase must be rolled back too (it needs the table).
UPDATE mechanics m JOIN mechanic_availability_backup b ON b.mechanic_id = m.id
   SET m.availability = b.original_availability, m.unavailable_reason = b.original_unavailable_reason
 WHERE m.availability = 'Unavailable';
ALTER TABLE mechanics ALTER COLUMN availability SET DEFAULT 'Available';
DROP TABLE IF EXISTS mechanic_attendance;
DROP TABLE IF EXISTS mechanic_availability_backup;
DELETE FROM schema_migrations WHERE id='2026-11-10_10_mechanic_attendance';
