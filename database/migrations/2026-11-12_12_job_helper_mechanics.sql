-- JOB_HELPER_MECHANICS_V1  (Maintenance: more than one mechanic per repair job)
-- Fresh install: already in extension.sql (do NOT import this file). Existing DB: back up, then import this ONCE.
-- NOT YET APPLIED to mulawin_v2: requires the owner's separate approval, a verified backup and a restore test first.
--
-- DESIGN (backward compatible):
--   maintenance_jobs.mechanic_id stays the LEAD mechanic. Every existing reader (history, reports, Fleet, Analytics,
--   Mechanics) keeps working unchanged, and NO existing row is changed, copied or back-filled by this migration.
--   This new table holds ADDITIONAL (assisting) mechanics of a repair. A row is active while released_at IS NULL.
--   The database allows ONE active assisting assignment per mechanic (uq_mjh_one_active). The application also refuses
--   a mechanic who leads another active repair, under a FOR UPDATE lock on the mechanic row (Maintenance::freeMechanic).
--   Rows are never deleted: removal / job completion / cancellation sets released_at, released_by and release_reason,
--   so the assignment history stays complete. Labor cost is not tied to mechanics, so nothing can be double-counted.
--
-- If the import stops with "table already exists": the migration was already applied; do not import it again.
-- Rollback plan (only while no row exists; see the file): database/migrations/rollback/2026-11-12_12_job_helper_mechanics_rollback.sql

SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES');

CREATE TABLE maintenance_job_helpers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  maintenance_job_id INT NOT NULL,
  mechanic_id INT NOT NULL,
  assigned_at DATETIME NOT NULL,
  assigned_by INT NOT NULL,
  released_at DATETIME NULL,
  released_by INT NULL,
  release_reason VARCHAR(255) NULL,
  active_mechanic INT AS (CASE WHEN released_at IS NULL THEN mechanic_id ELSE NULL END) STORED COMMENT 'one active assisting assignment per mechanic',
  UNIQUE KEY uq_mjh_one_active (active_mechanic),
  INDEX idx_mjh_job (maintenance_job_id, released_at),
  INDEX idx_mjh_mechanic (mechanic_id, assigned_at),
  CONSTRAINT fk_mjh_job FOREIGN KEY (maintenance_job_id) REFERENCES maintenance_jobs(id),
  CONSTRAINT fk_mjh_mechanic FOREIGN KEY (mechanic_id) REFERENCES mechanics(id),
  CONSTRAINT fk_mjh_assigned_by FOREIGN KEY (assigned_by) REFERENCES users(id),
  CONSTRAINT fk_mjh_released_by FOREIGN KEY (released_by) REFERENCES users(id),
  CONSTRAINT chk_mjh_release CHECK (released_at IS NULL OR (released_at >= assigned_at AND released_by IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO schema_migrations (id) VALUES ('2026-11-12_12_job_helper_mechanics');
