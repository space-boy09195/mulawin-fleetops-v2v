-- ROLLBACK of 2026-11-10_11_daily_attendance. Run ONLY if you must undo the migration.
-- attendance_marks, personnel.duty_shift and mechanics.duty_shift were never changed, so the previous (shift-based) code
-- works again as soon as this runs. WARNING: it drops attendance_daily, i.e. every attendance mark saved AFTER the migration
-- (rows with legacy_shifts NULL) is lost to the old screens. Export it first if you want to keep it:
--   mysqldump <database> attendance_daily > attendance_daily_backup.sql
-- If it stops half-way, run only the statements that did not run yet (each one is safe to repeat except DROP TABLE, which then fails
-- with "Unknown table"). The schema_migrations record is removed LAST, so it never disappears while the table is still there.
-- The application code of this correction must be rolled back too (it needs the table).
DROP TABLE attendance_daily;
DELETE FROM schema_migrations WHERE id='2026-11-10_11_daily_attendance';
