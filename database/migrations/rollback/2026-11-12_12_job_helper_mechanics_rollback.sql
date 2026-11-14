-- ROLLBACK of 2026-11-12_12_job_helper_mechanics (only before any assisting mechanic was recorded).
-- 1) Check first (read-only). If n > 0, assignment history exists: export it before any rollback and ask the owner.
SELECT COUNT(*) AS n FROM maintenance_job_helpers;
-- 2) Only when n = 0 and the previous PHP/JS is deployed again:
-- DROP TABLE maintenance_job_helpers;
-- DELETE FROM schema_migrations WHERE id='2026-11-12_12_job_helper_mechanics';
