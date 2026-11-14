-- ROLLBACK of 2026-11-12_13_stock_count_reconciliation (only before any stock count was recorded).
-- 1) Check first (read-only). If n > 0, count / reconciliation history exists: export it before any rollback and ask the owner.
--    Posted counts also have 'Adjustment' rows in stock_movements (reference_type='stock_count'); those must stay.
SELECT COUNT(*) AS n FROM stock_counts;
-- 2) Only when n = 0 and the previous PHP/JS is deployed again:
-- DROP TABLE stock_counts;
-- DELETE FROM schema_migrations WHERE id='2026-11-12_13_stock_count_reconciliation';
