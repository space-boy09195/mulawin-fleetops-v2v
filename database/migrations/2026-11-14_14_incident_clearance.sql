-- =====================================================================
--  2026-11-14_14_incident_clearance.sql  —  Motorpool vehicle clearance for trip incidents
-- =====================================================================
--  For an EXISTING database. Import ONCE, after 2026-11-13_13_fixed_route_fuel. A fresh install does not need it
--  (database/extension.sql has the same columns and records this migration).
--
--  ADDITIVE ONLY: six nullable columns on incidents and their rules. No row is changed; existing incidents keep
--  clearance = NULL (no assessor or decision is invented for them). Minor / Major stays the damage classification
--  (incidents.severity); clearance is Motorpool's separate technical decision.
--    clearance            Fit to Continue | Not Fit to Continue (Motorpool)
--    clearance_findings   the findings behind the decision (required with a decision)
--    cleared_by/at        Motorpool actor and time of the decision
--    resumed_by/at        Dispatcher who resumed the trip on a Fit to Continue clearance, and when
--
--  Import with a client that stops at the first error. The schema_migrations row is the LAST statement, so a failed
--  ALTER records nothing. Rollback: database/migrations/rollback/2026-11-14_14_incident_clearance_rollback.sql.
-- =====================================================================

ALTER TABLE incidents
  ADD COLUMN clearance VARCHAR(20) NULL COMMENT 'Motorpool decision: Fit to Continue or Not Fit to Continue' AFTER recommendation,
  ADD COLUMN clearance_findings VARCHAR(1000) NULL COMMENT 'Motorpool findings behind the clearance decision' AFTER clearance,
  ADD COLUMN cleared_by INT NULL AFTER clearance_findings,
  ADD COLUMN cleared_at DATETIME NULL AFTER cleared_by,
  ADD COLUMN resumed_by INT NULL COMMENT 'Dispatcher who resumed the trip on a Fit to Continue clearance' AFTER resolved_at,
  ADD COLUMN resumed_at DATETIME NULL AFTER resumed_by,
  ADD CONSTRAINT fk_incident_cleared_by FOREIGN KEY (cleared_by) REFERENCES users(id),
  ADD CONSTRAINT fk_incident_resumed_by FOREIGN KEY (resumed_by) REFERENCES users(id),
  ADD CONSTRAINT chk_incident_clearance CHECK (clearance IS NULL OR clearance IN ('Fit to Continue','Not Fit to Continue')),
  ADD CONSTRAINT chk_incident_clearance_record CHECK ((clearance IS NULL AND cleared_by IS NULL AND cleared_at IS NULL AND clearance_findings IS NULL)
      OR (clearance IS NOT NULL AND cleared_by IS NOT NULL AND cleared_at IS NOT NULL AND clearance_findings IS NOT NULL)),
  ADD CONSTRAINT chk_incident_resume_record CHECK ((resumed_by IS NULL AND resumed_at IS NULL)
      OR (resumed_by IS NOT NULL AND resumed_at IS NOT NULL AND clearance = 'Fit to Continue'));

INSERT INTO schema_migrations (id) VALUES ('2026-11-14_14_incident_clearance');
