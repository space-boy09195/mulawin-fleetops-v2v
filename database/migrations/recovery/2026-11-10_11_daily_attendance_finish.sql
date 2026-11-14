-- RECOVERY for migration 2026-11-10_11_daily_attendance: use ONLY when the check file says PARTIAL
-- (step 1, the CREATE TABLE, succeeded but the migration is not recorded).
-- It runs steps 2 and 3 of the migration. It never repeats step 1.
-- Use it BEFORE the new application code is used: step 2 only adds days that are missing, but it cannot tell a day that was
-- never copied from a mark the Admin deliberately cleared later.
-- If step 1 did NOT run, the first statement fails ("table attendance_daily doesn't exist") and nothing is changed or recorded.
-- If the migration is already COMPLETE, step 2 does nothing (it checks the record) and step 3 fails ("Duplicate entry").
-- Import with a client that stops at the first error. Then run sections 2 and 3 of the check file.

SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES');

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
