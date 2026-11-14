-- ROLLBACK of 2026-11-14_14_incident_clearance. Use ONLY while no clearance or resumption was recorded:
--   SELECT COUNT(*) FROM incidents WHERE clearance IS NOT NULL OR resumed_by IS NOT NULL;   -- must be 0
-- Otherwise restore the backup taken before the migration (dropping the columns would delete recorded decisions).
-- Guard: the INSERT below violates the CHECK (must_be_zero = 0) when decisions exist, so the import stops before any DROP.
SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES');
CREATE TEMPORARY TABLE rollback_guard_14 (must_be_zero INT NOT NULL CHECK (must_be_zero = 0));
INSERT INTO rollback_guard_14 SELECT COUNT(*) FROM incidents WHERE clearance IS NOT NULL OR resumed_by IS NOT NULL;
DROP TEMPORARY TABLE rollback_guard_14;
ALTER TABLE incidents
  DROP CONSTRAINT chk_incident_resume_record,
  DROP CONSTRAINT chk_incident_clearance_record,
  DROP CONSTRAINT chk_incident_clearance,
  DROP FOREIGN KEY fk_incident_resumed_by,
  DROP FOREIGN KEY fk_incident_cleared_by,
  DROP COLUMN resumed_at, DROP COLUMN resumed_by, DROP COLUMN cleared_at, DROP COLUMN cleared_by,
  DROP COLUMN clearance_findings, DROP COLUMN clearance;
DELETE FROM schema_migrations WHERE id = '2026-11-14_14_incident_clearance';
