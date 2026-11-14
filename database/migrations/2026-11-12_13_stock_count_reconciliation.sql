-- STOCK_COUNT_RECONCILIATION_V1  (Purchasing / Inventory: physical stock count and reviewed adjustment)
-- Fresh install: already in extension.sql (do NOT import this file). Existing DB: back up, then import this ONCE.
-- NOT YET APPLIED to mulawin_v2: requires the owner's separate approval, a verified backup and a restore test first.
--
-- WHY: the former Purchasing "Adjust stock" action changed quantity_on_hand at once with only a quantity and a reason,
-- with no second person. It is now refused by the server. Physical discrepancies go through this two-step record:
--   1. Purchasing Officer counts a part: system quantity (read under lock) + counted quantity + reason (+ evidence files).
--      Status 'Submitted'. Nothing changes in stock yet.
--   2. Admin (the role that already approves purchase orders above the approval limit) reviews it:
--        Post   -> one 'Adjustment' stock movement (reference_type 'stock_count', reference_id = this row), the part's
--                  quantity becomes counted_qty. Refused when the stock changed after the count (recount needed).
--        Reject -> nothing changes in stock; the review note is kept.
--   The counter can never review their own count (checked by the application and by chk_sc_reviewer).
-- CONTROLS IN THE DATABASE:
--   * one open (Submitted) count per part                 uq_sc_one_open
--   * a count is posted at most once / one movement only  uq_sc_movement + status check
--   * quantities are never negative                       chk_sc_qty
--   * rows are never deleted; the review fields are filled once.
-- Existing tables and rows are NOT changed by this migration.
--
-- If the import stops with "table already exists": the migration was already applied; do not import it again.
-- Rollback plan (only while no row exists): database/migrations/rollback/2026-11-12_13_stock_count_reconciliation_rollback.sql

SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES');

CREATE TABLE stock_counts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  count_ref VARCHAR(30) NOT NULL UNIQUE COMMENT 'SC-YYYY-NNNN, given by the system',
  part_id INT NOT NULL,
  system_qty INT NOT NULL COMMENT 'quantity on hand recorded by the system when the count was entered',
  counted_qty INT NOT NULL COMMENT 'quantity physically counted',
  variance INT AS (counted_qty - system_qty) STORED,
  reason VARCHAR(255) NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'Submitted',
  counted_by INT NOT NULL,
  counted_at DATETIME NOT NULL,
  reviewed_by INT NULL,
  reviewed_at DATETIME NULL,
  review_notes VARCHAR(255) NULL,
  qty_before INT NULL COMMENT 'on hand just before posting',
  qty_after INT NULL COMMENT 'on hand just after posting',
  stock_movement_id INT NULL,
  open_part INT AS (CASE WHEN status = 'Submitted' THEN part_id ELSE NULL END) STORED COMMENT 'one open count per part',
  UNIQUE KEY uq_sc_one_open (open_part),
  UNIQUE KEY uq_sc_movement (stock_movement_id),
  INDEX idx_sc_part (part_id, counted_at),
  INDEX idx_sc_status (status, counted_at),
  CONSTRAINT fk_sc_part FOREIGN KEY (part_id) REFERENCES parts(id),
  CONSTRAINT fk_sc_counted_by FOREIGN KEY (counted_by) REFERENCES users(id),
  CONSTRAINT fk_sc_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users(id),
  CONSTRAINT fk_sc_movement FOREIGN KEY (stock_movement_id) REFERENCES stock_movements(id),
  CONSTRAINT chk_sc_status CHECK (status IN ('Submitted', 'Posted', 'Rejected')),
  CONSTRAINT chk_sc_qty CHECK (system_qty >= 0 AND counted_qty >= 0 AND counted_qty <> system_qty),
  CONSTRAINT chk_sc_reviewer CHECK (reviewed_by IS NULL OR reviewed_by <> counted_by),
  CONSTRAINT chk_sc_review CHECK (
    (status = 'Submitted' AND reviewed_by IS NULL AND reviewed_at IS NULL AND stock_movement_id IS NULL)
    OR (status = 'Rejected' AND reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL AND stock_movement_id IS NULL)
    OR (status = 'Posted' AND reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL AND stock_movement_id IS NOT NULL
        AND qty_before IS NOT NULL AND qty_after = counted_qty))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO schema_migrations (id) VALUES ('2026-11-12_13_stock_count_reconciliation');
