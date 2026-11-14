-- =====================================================================
-- Mulawin FleetOps (RP Mulawin Trucking)  |  database/extension.sql
-- PART 2 OF 2: trips, funds, incidents, maintenance, purchasing,
--              inventory, finance and billing. Import AFTER schema.sql.
-- Empty database only.
-- =====================================================================
SET NAMES utf8mb4;

-- Record of applied database migrations (see database/migrations/). A fresh install
-- already contains every change, so every migration is marked as applied.
CREATE TABLE schema_migrations (
  id VARCHAR(80) PRIMARY KEY,
  applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO schema_migrations (id) VALUES
  ('2026-11-04_account_security'), ('2026-11-05_arrival_checklist'), ('2026-11-05_checklist_timer'),
  ('2026-11-06_00_schema_migrations'), ('2026-11-06_01_phase1_supplier_snapshot_and_trip_key'),
  ('2026-11-06_02_client_routes'), ('2026-11-06_03_audit_changes'),
  ('2026-11-06_04_maps_routing'),
  ('2026-11-06_05_account_ownership'), ('2026-11-06_06_trusted_devices_email'),
  ('2026-11-07_07_trip_routing'), ('2026-11-08_08_attendance'),
  ('2026-11-09_09_billing_adjustments');

-- =====================================================================
-- OPERATIONS: TRIPS  (Objective 1.3 - 1.6; PDF #12 Scenario A, B, C)
-- =====================================================================
-- Trip status flow:
--   Pending Review               dispatcher request waiting for the Operations Head (Scenario B)
--   Awaiting Resources           confirmed, but no free truck / driver / helper yet
--   Pre-Assigned                 system assigned the truck, driver and helper
--   Ready for Dispatch           pre-dispatch checklist complete (all OK)
--   Dispatched                   truck left (needs fund Released + Ready for Dispatch)
--   On Hold - Incident Reported  breakdown reported, Maintenance assessing
--   In Transit                   trip resumed after a minor on-site repair
--   For Reschedule               major damage, truck returned for repair
--   Completed                    delivery done, completion details and documents saved
--   Cancelled / Rejected
-- attempt_number: 1 = first dispatch, 2 = Second Attempt after major damage.
CREATE TABLE trips (
  id INT AUTO_INCREMENT PRIMARY KEY,
  trip_ref VARCHAR(32) NOT NULL UNIQUE,
  request_key CHAR(36) NULL UNIQUE COMMENT 'one per New Trip form; blocks duplicate submissions',
  intake_source VARCHAR(20) NOT NULL COMMENT 'Operations Head (Scenario A) or Dispatcher (Scenario B)',
  created_by INT NOT NULL,
  client_id INT NOT NULL,
  consignee VARCHAR(120) NULL COMMENT 'brand / consignee (CLIENT column of the monitoring sheets)',
  truck_type VARCHAR(25) NULL,
  dispatcher_role VARCHAR(40) NULL,
  dispatcher_shift VARCHAR(5) NULL COMMENT 'Manual planning ownership; NULL for legacy group-wide trips',
  dispatcher_id INT NULL,
  pickup_point VARCHAR(200) NOT NULL,
  pickup_lat DECIMAL(10,7) NULL,
  pickup_lng DECIMAL(10,7) NULL,
  delivery_point VARCHAR(200) NOT NULL,
  delivery_lat DECIMAL(10,7) NULL,
  delivery_lng DECIMAL(10,7) NULL,
  distance_km DECIMAL(10,2) NULL COMMENT 'billable km from pickup point to delivery point (typed by Ops Head)',
  scheduled_at DATETIME NOT NULL,
  delivery_details VARCHAR(500) NULL,
  booking_reference VARCHAR(150) NULL COMMENT 'booking / nomination details, when applicable',
  units_planned INT NULL COMMENT 'car carrier units',
  container_number VARCHAR(60) NULL,
  container_size VARCHAR(20) NULL,
  seal_number VARCHAR(60) NULL,
  shipment_number VARCHAR(60) NULL,
  vehicle_id INT NULL,
  driver_id INT NULL,
  driver2_id INT NULL COMMENT 'car carrier DRIVER 2',
  helper_id INT NULL,
  assignment_mode VARCHAR(10) NULL COMMENT 'Auto or Manual',
  status VARCHAR(35) NOT NULL DEFAULT 'Pending Review',
  attempt_number INT NOT NULL DEFAULT 1,
  approved_by INT NULL,
  approved_at DATETIME NULL,
  dispatched_at DATETIME NULL,
  completed_at DATETIME NULL COMMENT 'actual completion date and time',
  units_delivered INT NULL,
  delivery_notes VARCHAR(500) NULL,
  waybill_number VARCHAR(100) NULL,
  cancel_reason VARCHAR(300) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id),
  FOREIGN KEY (client_id) REFERENCES clients(id),
  FOREIGN KEY (dispatcher_id) REFERENCES users(id),
  FOREIGN KEY (approved_by) REFERENCES users(id),
  FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
  FOREIGN KEY (driver_id) REFERENCES personnel(id),
  FOREIGN KEY (driver2_id) REFERENCES personnel(id),
  FOREIGN KEY (helper_id) REFERENCES personnel(id),
  INDEX idx_trip_schedule (scheduled_at),
  INDEX idx_trip_status (status),
  INDEX idx_trip_vehicle (vehicle_id, status),
  INDEX idx_trip_dispatcher_shift (dispatcher_role,dispatcher_shift,status),
  CONSTRAINT chk_trip_dispatcher_shift CHECK (dispatcher_shift IS NULL OR (dispatcher_shift IN ('Day','Night') AND dispatcher_role IS NOT NULL AND dispatcher_role IN ('car_carrier_dispatcher','container_wingvan_dispatcher'))),
  CONSTRAINT chk_trip_status CHECK (status IN ('Pending Review','Awaiting Resources','Pre-Assigned','Ready for Dispatch','Dispatched',
                                               'On Hold - Incident Reported','In Transit','For Reschedule','Completed','Cancelled','Rejected')),
  CONSTRAINT chk_trip_type CHECK (truck_type IS NULL OR truck_type IN ('Car Carrier','Container','Wing Van')),
  CONSTRAINT chk_trip_distance CHECK (distance_km IS NULL OR distance_km >= 0),
  CONSTRAINT chk_trip_attempt CHECK (attempt_number >= 1),
  CONSTRAINT chk_trip_crew CHECK ((driver2_id IS NULL OR driver2_id <> driver_id) AND (helper_id IS NULL OR helper_id <> driver_id))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- DOUBLE-BOOKING SAFEGUARD (database level).
-- One row per truck or person that is held by an active trip.
-- PRIMARY KEY (resource_type, resource_id) means the database itself refuses
-- to put the same truck or the same driver/helper on two active trips.
-- Rows are added on assignment and removed when the trip is completed,
-- cancelled or re-assigned.
CREATE TABLE trip_assignments (
  resource_type VARCHAR(10) NOT NULL COMMENT 'vehicle or person',
  resource_id INT NOT NULL,
  trip_id INT NOT NULL,
  role VARCHAR(10) NOT NULL COMMENT 'truck, driver, driver2, helper',
  assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (resource_type, resource_id),
  FOREIGN KEY (trip_id) REFERENCES trips(id),
  INDEX idx_ta_trip (trip_id),
  CONSTRAINT chk_ta_type CHECK (resource_type IN ('vehicle','person'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- History of every approval, assignment, dispatch and completion (PDF #11 II.D).
CREATE TABLE trip_events (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  trip_id INT NOT NULL,
  actor_id INT NULL,
  event_type VARCHAR(80) NOT NULL,
  from_status VARCHAR(35) NOT NULL DEFAULT '',
  to_status VARCHAR(35) NOT NULL DEFAULT '',
  notes VARCHAR(500) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (trip_id) REFERENCES trips(id),
  FOREIGN KEY (actor_id) REFERENCES users(id),
  INDEX idx_trip_events (trip_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Trip progress and delay updates recorded by the dispatcher (Objective 1.5).
CREATE TABLE trip_updates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  trip_id INT NOT NULL,
  actor_id INT NOT NULL,
  update_type VARCHAR(20) NOT NULL,
  delay_cause VARCHAR(30) NULL,
  note VARCHAR(500) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (trip_id) REFERENCES trips(id),
  FOREIGN KEY (actor_id) REFERENCES users(id),
  INDEX idx_trip_updates (trip_id),
  CONSTRAINT chk_update_type CHECK (update_type IN ('Progress','Loading','Unloading','Delivery','Delay','Other'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Checklist timer (PDF #11 section G: minimum inspection duration, e.g. 15 minutes).
-- Written when the dispatcher OPENS a checklist; the server refuses the submission until
-- the minimum minutes (setting inspection_min_minutes, editable by the Admin) have passed.
CREATE TABLE checklist_starts (
  trip_id INT NOT NULL,
  attempt_number INT NOT NULL,
  kind VARCHAR(15) NOT NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_by INT NOT NULL,
  PRIMARY KEY (trip_id, attempt_number, kind),
  CONSTRAINT chk_cstart_kind CHECK (kind IN ('Pre-Dispatch','Arrival')),
  FOREIGN KEY (trip_id) REFERENCES trips(id),
  FOREIGN KEY (started_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Vehicle checklists (57 items from the company checklist scans), PDF #11 section G.
--   Pre-Dispatch: before departure, one per trip attempt (all OK -> Ready for Dispatch).
--   Arrival:      when the truck is back after a Completed trip. Until it is done the truck
--                 stays held (On Trip). NOT OK items open a corrective maintenance record.
CREATE TABLE inspections (
  id INT AUTO_INCREMENT PRIMARY KEY,
  trip_id INT NOT NULL,
  vehicle_id INT NOT NULL,
  attempt_number INT NOT NULL,
  kind VARCHAR(15) NOT NULL DEFAULT 'Pre-Dispatch',
  items_json MEDIUMTEXT NOT NULL,
  has_not_ok TINYINT NOT NULL DEFAULT 0,
  odometer_km INT NULL,
  fuel_level VARCHAR(20) NULL,
  remarks VARCHAR(500) NULL,
  damage_notes VARCHAR(1000) NULL COMMENT 'arrival: damage, defects, missing equipment, reported incidents',
  maintenance_job_id INT NULL COMMENT 'arrival: corrective maintenance record opened for the NOT OK items',
  actor_id INT NOT NULL,
  started_at DATETIME NULL COMMENT 'when the checklist was opened (minimum duration timer)',
  completed_at DATETIME NOT NULL,
  UNIQUE KEY uq_inspection_kind (trip_id, attempt_number, kind),
  CONSTRAINT chk_inspection_kind CHECK (kind IN ('Pre-Dispatch','Arrival')),
  FOREIGN KEY (trip_id) REFERENCES trips(id),
  FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
  FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Incident reports (Objective 1.6; PDF #12 Scenario C; Incident Report Form in PDF #13).
-- Status: Reported -> Under Repair -> Repaired - Cleared to Continue (minor) -> Resolved
--                                  -> Resolved (major, after the truck is repaired)
CREATE TABLE incidents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  incident_ref VARCHAR(32) NOT NULL UNIQUE,
  trip_id INT NOT NULL,
  vehicle_id INT NOT NULL,
  attempt_number INT NOT NULL DEFAULT 1,
  incident_type VARCHAR(40) NOT NULL,
  incident_at DATETIME NOT NULL,
  location VARCHAR(200) NOT NULL,
  persons_involved VARCHAR(255) NULL,
  reason VARCHAR(255) NULL,
  problem VARCHAR(255) NOT NULL COMMENT 'reported problem or damage',
  details TEXT NOT NULL,
  severity VARCHAR(10) NULL COMMENT 'Minor or Major (Maintenance assessment)',
  recommendation VARCHAR(500) NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'Reported',
  reported_by INT NOT NULL,
  assessed_by INT NULL,
  assessed_at DATETIME NULL,
  resolved_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (trip_id) REFERENCES trips(id),
  FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
  FOREIGN KEY (reported_by) REFERENCES users(id),
  FOREIGN KEY (assessed_by) REFERENCES users(id),
  INDEX idx_incident_status (status),
  CONSTRAINT chk_incident_status CHECK (status IN ('Reported','Under Repair','Repaired - Cleared to Continue','Resolved')),
  CONSTRAINT chk_incident_severity CHECK (severity IS NULL OR severity IN ('Minor','Major'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- TRIP FUND REQUESTS  (Objective 1.4 and 3.5; PDF #12 steps 4-6; Request for Cash Fund form)
-- Pending Approval -> Ready for Release (Ops Head approved) -> Released (Finance)
--                  -> Rejected (back to the dispatcher, who revises and resubmits)
-- Cancelled when the trip is cancelled before release.
-- =====================================================================
CREATE TABLE fund_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  fund_ref VARCHAR(32) NOT NULL UNIQUE,
  trip_id INT NOT NULL,
  attempt_number INT NOT NULL DEFAULT 1,
  amount DECIMAL(12,2) NOT NULL,
  notes VARCHAR(255) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'Pending Approval',
  requested_by INT NOT NULL,
  approved_by INT NULL, approved_at DATETIME NULL,
  rejected_reason VARCHAR(300) NULL,
  released_by INT NULL, released_at DATETIME NULL,
  disbursement_id INT NULL,
  liquidation_status VARCHAR(20) NOT NULL DEFAULT 'Not Liquidated',
  liquidated_amount DECIMAL(12,2) NULL COMMENT 'actual amount spent',
  returned_amount DECIMAL(12,2) NULL COMMENT 'unused cash returned',
  liquidation_notes VARCHAR(300) NULL,
  liquidated_by INT NULL, liquidated_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_fund_trip_attempt (trip_id, attempt_number),
  FOREIGN KEY (trip_id) REFERENCES trips(id),
  FOREIGN KEY (requested_by) REFERENCES users(id),
  FOREIGN KEY (approved_by) REFERENCES users(id),
  FOREIGN KEY (released_by) REFERENCES users(id),
  FOREIGN KEY (liquidated_by) REFERENCES users(id),
  INDEX idx_fund_status (status),
  CONSTRAINT chk_fund_status CHECK (status IN ('Pending Approval','Ready for Release','Released','Rejected','Cancelled')),
  CONSTRAINT chk_fund_amount CHECK (amount > 0),
  CONSTRAINT chk_fund_liq CHECK (liquidation_status IN ('Not Liquidated','Liquidated'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE fund_request_lines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  fund_request_id INT NOT NULL,
  category_name VARCHAR(80) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  is_preset TINYINT NOT NULL DEFAULT 1,
  FOREIGN KEY (fund_request_id) REFERENCES fund_requests(id) ON DELETE CASCADE,
  CONSTRAINT chk_fund_line_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- PURCHASING AND INVENTORY  (Objective 2.4 - 2.6; PDF #12 Maintenance Module A and B)
-- =====================================================================
CREATE TABLE suppliers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL UNIQUE,
  contact_person VARCHAR(120) NULL,
  contact_number VARCHAR(60) NULL,
  email VARCHAR(120) NULL,
  address VARCHAR(255) NULL,
  active TINYINT NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE parts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  part_number VARCHAR(70) NOT NULL UNIQUE,
  description VARCHAR(200) NOT NULL,
  category VARCHAR(80) NULL,
  unit VARCHAR(20) NOT NULL DEFAULT 'pc',
  unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'latest purchase cost',
  reorder_level INT NOT NULL DEFAULT 0,
  quantity_on_hand INT NOT NULL DEFAULT 0,
  warranty_info VARCHAR(150) NULL,
  active TINYINT NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT chk_parts_qty CHECK (quantity_on_hand >= 0),
  CONSTRAINT chk_parts_cost CHECK (unit_cost >= 0 AND reorder_level >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- MECHANIC MASTERLIST  (supports Corrective Maintenance only)
-- availability: Available (timed in) | Assigned (timed in, has an active repair) | Unavailable (not timed in). See mechanic_attendance.
-- =====================================================================
CREATE TABLE mechanics (
  id INT AUTO_INCREMENT PRIMARY KEY,
  mechanic_code VARCHAR(20) NOT NULL UNIQUE COMMENT 'MEC-001, given by the system',
  full_name VARCHAR(100) NOT NULL,
  duty_shift VARCHAR(5) NULL COMMENT 'Day or Night; assigned by Admin',
  contact_number VARCHAR(40) NULL,
  specialization VARCHAR(150) NULL COMMENT 'e.g. engine, electrical, brakes, welding',
  availability VARCHAR(15) NOT NULL DEFAULT 'Unavailable',
  unavailable_reason VARCHAR(150) NULL,
  active TINYINT NOT NULL DEFAULT 1,
  notes VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mechanic_name (full_name),
  CONSTRAINT chk_mechanic_availability CHECK (availability IN ('Available','Assigned','Unavailable'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- CORRECTIVE MAINTENANCE  (Objective 2.1 - 2.3; PDF #12 Vehicle Maintenance Management)
-- source: Incident (from a trip incident report) or On-Premise (problem found at the garage)
-- status: Reported -> Under Inspection (mechanic assigned) -> Under Repair (diagnosis recorded)
--         -> Completed (repair done) ; or Cancelled
-- The truck stays out of service until the repair is VERIFIED (verified_at), not just Completed.
-- open_key: only ONE unresolved record per truck (database rule).
-- active_mechanic: a mechanic can have only ONE active repair at a time (database rule).
-- =====================================================================
CREATE TABLE maintenance_jobs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  job_ref VARCHAR(32) NOT NULL UNIQUE,
  vehicle_id INT NOT NULL,
  source VARCHAR(15) NOT NULL,
  incident_id INT NULL UNIQUE,
  trip_id INT NULL,
  problem VARCHAR(500) NOT NULL COMMENT 'reported problem or damage',
  reported_on DATE NOT NULL COMMENT 'date reported',
  priority VARCHAR(10) NOT NULL DEFAULT 'Normal',
  severity VARCHAR(10) NULL COMMENT 'Minor or Major (incidents)',
  mechanic_id INT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'Reported',
  findings TEXT NULL COMMENT 'inspection findings',
  diagnosis TEXT NULL,
  expected_completion DATE NULL COMMENT 'expected vehicle availability',
  repair_performed TEXT NULL COMMENT 'corrective action performed',
  completed_on DATE NULL,
  remarks VARCHAR(500) NULL,
  verified_at DATETIME NULL COMMENT 'operational check passed: truck returned to service',
  verified_by INT NULL,
  verification_notes VARCHAR(500) NULL,
  started_by INT NULL, started_at DATETIME NULL,
  completed_by INT NULL, completed_at DATETIME NULL,
  created_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  open_key TINYINT AS (CASE WHEN status = 'Cancelled' OR verified_at IS NOT NULL THEN NULL ELSE 1 END) STORED,
  active_mechanic INT AS (CASE WHEN status IN ('Reported','Under Inspection','Under Repair') THEN mechanic_id ELSE NULL END) STORED,
  FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
  FOREIGN KEY (incident_id) REFERENCES incidents(id),
  FOREIGN KEY (trip_id) REFERENCES trips(id),
  FOREIGN KEY (mechanic_id) REFERENCES mechanics(id),
  FOREIGN KEY (started_by) REFERENCES users(id),
  FOREIGN KEY (completed_by) REFERENCES users(id),
  FOREIGN KEY (verified_by) REFERENCES users(id),
  UNIQUE KEY uq_mjob_one_open_per_truck (vehicle_id, open_key),
  UNIQUE KEY uq_mjob_one_job_per_mechanic (active_mechanic),
  INDEX idx_mjob_status (status, vehicle_id),
  CONSTRAINT chk_mjob_source CHECK (source IN ('Incident','On-Premise')),
  CONSTRAINT chk_mjob_status CHECK (status IN ('Reported','Under Inspection','Under Repair','Completed','Cancelled')),
  CONSTRAINT chk_mjob_priority CHECK (priority IN ('Low','Normal','High','Critical'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Status history of each maintenance record (who moved it, mechanic changes, verification).
CREATE TABLE maintenance_events (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  maintenance_job_id INT NOT NULL,
  from_status VARCHAR(20) NULL,
  to_status VARCHAR(20) NOT NULL,
  mechanic_id INT NULL,
  details VARCHAR(500) NULL,
  actor_id INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (maintenance_job_id) REFERENCES maintenance_jobs(id),
  FOREIGN KEY (mechanic_id) REFERENCES mechanics(id),
  FOREIGN KEY (actor_id) REFERENCES users(id),
  INDEX idx_mevent_job (maintenance_job_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE maintenance_costs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  maintenance_job_id INT NOT NULL,
  cost_type VARCHAR(30) NOT NULL COMMENT 'Labor, Outside Service, Materials, Other',
  description VARCHAR(255) NOT NULL,
  provider VARCHAR(150) NULL,
  amount DECIMAL(12,2) NOT NULL,
  incurred_on DATE NOT NULL,
  recorded_by INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (maintenance_job_id) REFERENCES maintenance_jobs(id),
  FOREIGN KEY (recorded_by) REFERENCES users(id),
  CONSTRAINT chk_mcost_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Truck-parts request (stock-out): Motorpool Custodian -> Purchasing Officer (REQUEST FORM, PDF #13).
CREATE TABLE part_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  request_ref VARCHAR(32) NOT NULL UNIQUE,
  vehicle_id INT NOT NULL,
  maintenance_job_id INT NULL,
  purpose VARCHAR(255) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'Requested',
  requested_by INT NOT NULL,
  processed_by INT NULL,
  processed_at DATETIME NULL,
  notes VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
  FOREIGN KEY (maintenance_job_id) REFERENCES maintenance_jobs(id),
  FOREIGN KEY (requested_by) REFERENCES users(id),
  FOREIGN KEY (processed_by) REFERENCES users(id),
  CONSTRAINT chk_preq_status CHECK (status IN ('Requested','Partially Released','Released','Not Available','Cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE part_request_lines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  part_request_id INT NOT NULL,
  part_id INT NOT NULL,
  quantity INT NOT NULL,
  released_qty INT NOT NULL DEFAULT 0,
  unit_cost DECIMAL(12,2) NULL COMMENT 'cost at the time of release',
  UNIQUE KEY uq_preq_line (part_request_id, part_id),
  FOREIGN KEY (part_request_id) REFERENCES part_requests(id),
  FOREIGN KEY (part_id) REFERENCES parts(id),
  CONSTRAINT chk_preq_qty CHECK (quantity > 0 AND released_qty >= 0 AND released_qty <= quantity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Purchasing budget set by Finance (Objective 3.6).
-- available = amount - reserved_amount - spent_amount
CREATE TABLE budgets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category VARCHAR(30) NOT NULL DEFAULT 'Purchasing',
  amount DECIMAL(14,2) NOT NULL,
  reserved_amount DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'approved POs not yet paid',
  spent_amount DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'actual payments',
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'Active',
  set_by INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (set_by) REFERENCES users(id),
  CONSTRAINT chk_budget_amounts CHECK (amount > 0 AND reserved_amount >= 0 AND spent_amount >= 0),
  CONSTRAINT chk_budget_status CHECK (status IN ('Active','Closed')),
  CONSTRAINT chk_budget_period CHECK (period_end >= period_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Purchase Order Request (stock-in), PDF #12 Maintenance Module B.
-- status: Pending Approval -> Approved | Rejected | Rejected - Insufficient Budget
--         Approved -> Partially Received -> Received   (Closed = undelivered balance cancelled)
-- payment_status (after approval): Pending Payment -> Partially Paid -> Paid
CREATE TABLE purchase_orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  po_ref VARCHAR(32) NOT NULL UNIQUE,
  supplier_id INT NULL COMMENT 'old supplier masterlist link (purchase orders made before the snapshot)',
  supplier_name VARCHAR(150) NULL COMMENT 'supplier details saved with the PO (snapshot)',
  supplier_address VARCHAR(255) NULL,
  supplier_contact VARCHAR(120) NULL COMMENT 'contact person',
  supplier_phone VARCHAR(60) NULL,
  supplier_email VARCHAR(120) NULL,
  part_request_id INT NULL COMMENT 'parts request that started this purchase, if any',
  purpose VARCHAR(255) NOT NULL,
  total_amount DECIMAL(14,2) NOT NULL,
  approver_role VARCHAR(20) NOT NULL COMMENT 'finance (limit and below) or admin (above limit)',
  status VARCHAR(40) NOT NULL DEFAULT 'Pending Approval',
  payment_status VARCHAR(20) NULL,
  budget_id INT NULL,
  reserved_amount DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'part of the budget still reserved for this PO',
  paid_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  supplier_invoice_no VARCHAR(80) NULL,
  requested_by INT NOT NULL,
  decided_by INT NULL,
  decided_at DATETIME NULL,
  decision_notes VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
  FOREIGN KEY (part_request_id) REFERENCES part_requests(id),
  FOREIGN KEY (budget_id) REFERENCES budgets(id),
  FOREIGN KEY (requested_by) REFERENCES users(id),
  FOREIGN KEY (decided_by) REFERENCES users(id),
  INDEX idx_po_status (status, payment_status),
  INDEX idx_po_supplier_name (supplier_name),
  CONSTRAINT chk_po_status CHECK (status IN ('Pending Approval','Approved','Rejected','Rejected - Insufficient Budget','Partially Received','Received','Closed')),
  CONSTRAINT chk_po_pay CHECK (payment_status IS NULL OR payment_status IN ('Pending Payment','Partially Paid','Paid')),
  CONSTRAINT chk_po_amounts CHECK (total_amount > 0 AND reserved_amount >= 0 AND paid_amount >= 0 AND paid_amount <= total_amount),
  CONSTRAINT chk_po_approver CHECK (approver_role IN ('finance','admin'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE purchase_order_lines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  purchase_order_id INT NOT NULL,
  part_id INT NOT NULL,
  quantity INT NOT NULL,
  unit_price DECIMAL(12,2) NOT NULL,
  line_total DECIMAL(14,2) NOT NULL,
  received_qty INT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_po_line (purchase_order_id, part_id),
  FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id),
  FOREIGN KEY (part_id) REFERENCES parts(id),
  CONSTRAINT chk_po_line CHECK (quantity > 0 AND unit_price > 0 AND received_qty >= 0 AND received_qty <= quantity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every stock change (stock-in, stock-out, adjustment) for inventory history (Objective 2.6, 5.5).
CREATE TABLE stock_movements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  part_id INT NOT NULL,
  movement_type VARCHAR(12) NOT NULL,
  quantity_delta INT NOT NULL,
  unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
  reason VARCHAR(150) NOT NULL,
  reference_type VARCHAR(20) NULL COMMENT 'purchase_order, part_request, adjustment',
  reference_id INT NULL,
  vehicle_id INT NULL,
  maintenance_job_id INT NULL,
  actor_id INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (part_id) REFERENCES parts(id),
  FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
  FOREIGN KEY (maintenance_job_id) REFERENCES maintenance_jobs(id),
  FOREIGN KEY (actor_id) REFERENCES users(id),
  INDEX idx_stock_part (part_id, created_at),
  CONSTRAINT chk_stock_type CHECK (movement_type IN ('Stock In','Stock Out','Adjustment')),
  CONSTRAINT chk_stock_delta CHECK (quantity_delta <> 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- FINANCE  (Objective 3.5 and 3.6)
-- =====================================================================
-- Every cash release: trip fund release, PO payment, other bill payment.
-- voucher_number is the payment voucher reference.
CREATE TABLE disbursements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  voucher_number VARCHAR(40) NOT NULL UNIQUE,
  source_type VARCHAR(20) NOT NULL,
  source_id INT NOT NULL,
  payee VARCHAR(180) NOT NULL,
  payment_method VARCHAR(20) NOT NULL,
  cheque_number VARCHAR(60) NULL,
  bank_name VARCHAR(100) NULL,
  reference_number VARCHAR(100) NULL,
  amount DECIMAL(12,2) NOT NULL,
  released_on DATE NOT NULL,
  recorded_by INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (recorded_by) REFERENCES users(id),
  INDEX idx_disb_date (released_on),
  INDEX idx_disb_source (source_type, source_id),
  CONSTRAINT chk_disb_source CHECK (source_type IN ('fund_request','purchase_order','payable')),
  CONSTRAINT chk_disb_method CHECK (payment_method IN ('Cash','Cheque','Bank Transfer','E-wallet')),
  CONSTRAINT chk_disb_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE fund_requests ADD CONSTRAINT fk_fund_disb FOREIGN KEY (disbursement_id) REFERENCES disbursements(id);
ALTER TABLE inspections ADD CONSTRAINT fk_inspection_job FOREIGN KEY (maintenance_job_id) REFERENCES maintenance_jobs(id);

-- Other bills to pay (accounts payable that do not come from a purchase order).
CREATE TABLE payables (
  id INT AUTO_INCREMENT PRIMARY KEY,
  payable_ref VARCHAR(32) NOT NULL UNIQUE,
  supplier_id INT NULL,
  payee VARCHAR(150) NOT NULL,
  category VARCHAR(40) NOT NULL,
  vehicle_id INT NULL,
  description VARCHAR(255) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  due_on DATE NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'Unpaid',
  recorded_by INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
  FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
  FOREIGN KEY (recorded_by) REFERENCES users(id),
  CONSTRAINT chk_pay_status CHECK (status IN ('Unpaid','Partially Paid','Paid')),
  CONSTRAINT chk_pay_amounts CHECK (amount > 0 AND paid_amount >= 0 AND paid_amount <= amount)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Expenses. source tells where the row came from, so nothing is counted twice:
--   Fund Release (trip allowance), PO Payment (parts), Payable (bill paid),
--   Maintenance (repair cost), Manual (Finance typed it).
CREATE TABLE expenses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category VARCHAR(40) NOT NULL,
  trip_id INT NULL,
  vehicle_id INT NULL,
  amount DECIMAL(12,2) NOT NULL,
  incurred_on DATE NOT NULL,
  payee VARCHAR(150) NULL,
  reference_number VARCHAR(100) NULL,
  description VARCHAR(500) NULL,
  source VARCHAR(20) NOT NULL DEFAULT 'Manual',
  source_id INT NULL,
  recorded_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (trip_id) REFERENCES trips(id),
  FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
  FOREIGN KEY (recorded_by) REFERENCES users(id),
  INDEX idx_expense_date (incurred_on),
  INDEX idx_expense_source (source, source_id),
  CONSTRAINT chk_exp_source CHECK (source IN ('Fund Release','PO Payment','Payable','Maintenance','Manual','Liquidation')),
  CONSTRAINT chk_exp_amount CHECK (amount <> 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- BILLING AND COLLECTION  (Objective 3.2 - 3.4; PDF #12 steps 10-12, Accounting Module)
-- One billing record per trip.
-- status:
--   Ongoing     -> billing draft made by the system at Confirm Dispatch (view only)
--   For Billing -> trip completed; Billing verifies documents and adds charges
--   Invoiced    -> invoice finalized, waiting for payment
--   Partially Paid / Paid
--   Cancelled   -> trip was cancelled after dispatch
-- =====================================================================
CREATE TABLE billings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  trip_id INT NOT NULL UNIQUE,
  client_id INT NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'Ongoing',
  distance_km DECIMAL(10,2) NOT NULL,
  rate_per_km DECIMAL(12,2) NOT NULL,
  minimum_charge DECIMAL(12,2) NOT NULL DEFAULT 0,
  base_amount DECIMAL(14,2) NOT NULL COMMENT 'MAX(distance x rate, minimum charge)',
  charges_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_amount DECIMAL(14,2) NOT NULL,
  amount_paid DECIMAL(14,2) NOT NULL DEFAULT 0,
  docs_verified_by INT NULL,
  docs_verified_at DATETIME NULL,
  invoice_number VARCHAR(40) NULL UNIQUE,
  invoice_date DATE NULL,
  due_date DATE NULL,
  finalized_by INT NULL,
  finalized_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (trip_id) REFERENCES trips(id),
  FOREIGN KEY (client_id) REFERENCES clients(id),
  FOREIGN KEY (docs_verified_by) REFERENCES users(id),
  FOREIGN KEY (finalized_by) REFERENCES users(id),
  INDEX idx_billing_status (status, client_id),
  INDEX idx_billing_invoice_date (invoice_date),
  CONSTRAINT chk_billing_status CHECK (status IN ('Ongoing','For Billing','Invoiced','Partially Paid','Paid','Cancelled')),
  CONSTRAINT chk_billing_amounts CHECK (total_amount >= 0 AND amount_paid >= 0 AND amount_paid <= total_amount)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Additional charges and per-trip rate adjustments (peso amounts) requested by Billing before the
-- invoice is finalized. Only status 'Approved' rows of request_type New/Change count in the billing
-- total; Admin approves or rejects (not the requester). A Change or Removal request points to the
-- approved row it replaces (replaces_id); approving it supersedes / removes that row.
CREATE TABLE billing_charges (
  id INT AUTO_INCREMENT PRIMARY KEY,
  billing_id INT NOT NULL,
  kind VARCHAR(20) NOT NULL DEFAULT 'Additional Charge',
  request_type VARCHAR(10) NOT NULL DEFAULT 'New',
  replaces_id INT NULL,
  description VARCHAR(150) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'Pending Approval',
  reason VARCHAR(300) NULL,
  added_by INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_by INT NULL,
  decided_at DATETIME NULL,
  decision_notes VARCHAR(300) NULL,
  FOREIGN KEY (billing_id) REFERENCES billings(id),
  FOREIGN KEY (added_by) REFERENCES users(id),
  CONSTRAINT fk_bcharge_replaces FOREIGN KEY (replaces_id) REFERENCES billing_charges(id),
  CONSTRAINT fk_bcharge_decider FOREIGN KEY (decided_by) REFERENCES users(id),
  CONSTRAINT chk_bcharge_amount CHECK (amount <> 0 AND (kind = 'Rate Adjustment' OR amount > 0)),
  CONSTRAINT chk_bcharge_kind CHECK (kind IN ('Additional Charge','Rate Adjustment')),
  CONSTRAINT chk_bcharge_request CHECK (request_type IN ('New','Change','Removal') AND ((request_type = 'New') = (replaces_id IS NULL))),
  CONSTRAINT chk_bcharge_status CHECK (status IN ('Pending Approval','Approved','Rejected','Superseded','Removed','Withdrawn')),
  CONSTRAINT chk_bcharge_decision CHECK (status <> 'Pending Approval' OR (decided_by IS NULL AND decided_at IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Client payments (needs the acknowledgement receipt file before it is accepted).
CREATE TABLE collections (
  id INT AUTO_INCREMENT PRIMARY KEY,
  billing_id INT NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  payment_date DATE NOT NULL,
  payment_method VARCHAR(20) NOT NULL,
  reference VARCHAR(100) NOT NULL,
  ar_number VARCHAR(40) NOT NULL COMMENT 'acknowledgement receipt number',
  cheque_number VARCHAR(60) NULL,
  bank_name VARCHAR(100) NULL,
  recorded_by INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (billing_id) REFERENCES billings(id),
  FOREIGN KEY (recorded_by) REFERENCES users(id),
  INDEX idx_collection_date (payment_date),
  CONSTRAINT chk_coll_method CHECK (payment_method IN ('Cash','Cheque','Bank Transfer','E-wallet')),
  CONSTRAINT chk_coll_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- CLIENT ROUTES  (Checklist #20; Objective 1.2 and 3.1)
-- Approved routes per client and truck type, with versioned contractual rates.
-- Same tables as database/migrations/2026-11-06_02_client_routes.sql.
-- The older client_rates table (rate per km) is kept and still used by billing.
-- =====================================================================
-- One route of one client for one truck type. Directional: A -> B and B -> A are different routes.
-- active_key: only ONE open or approved route per client + truck type + normalized origin + destination
-- (database rule, also safe when two people submit at the same time). Rejected, Withdrawn and
-- Inactive routes stay as history and do not block a new request.
CREATE TABLE client_routes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  route_ref VARCHAR(32) NOT NULL UNIQUE,
  client_id INT NOT NULL,
  truck_type VARCHAR(25) NOT NULL,
  origin_label VARCHAR(200) NOT NULL COMMENT 'origin as displayed',
  destination_label VARCHAR(200) NOT NULL COMMENT 'destination as displayed',
  origin_key VARCHAR(200) NOT NULL COMMENT 'normalized origin (duplicate check)',
  destination_key VARCHAR(200) NOT NULL COMMENT 'normalized destination (duplicate check)',
  origin_lat DECIMAL(10,7) NULL,
  origin_lng DECIMAL(10,7) NULL,
  destination_lat DECIMAL(10,7) NULL,
  destination_lng DECIMAL(10,7) NULL,
  distance_km DECIMAL(10,2) NOT NULL COMMENT 'operational reference and analytics',
  distance_source VARCHAR(30) NOT NULL DEFAULT 'Manual' COMMENT 'Manual, or the map provider used (provider-independent)',
  notes VARCHAR(500) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'Pending Rate',
  requested_by INT NOT NULL,
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_reason VARCHAR(300) NULL COMMENT 'reason of rejection, withdrawal or deactivation',
  closed_by INT NULL,
  closed_at DATETIME NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  active_key TINYINT AS (CASE WHEN status IN ('Pending Rate','Pending Approval','Approved') THEN 1 ELSE NULL END) STORED,
  FOREIGN KEY (client_id) REFERENCES clients(id),
  FOREIGN KEY (requested_by) REFERENCES users(id),
  FOREIGN KEY (closed_by) REFERENCES users(id),
  UNIQUE KEY uq_route_active (client_id, truck_type, origin_key, destination_key, active_key),
  INDEX idx_route_client (client_id, status),
  INDEX idx_route_status (status),
  CONSTRAINT chk_route_type CHECK (truck_type IN ('Car Carrier','Container','Wing Van')),
  CONSTRAINT chk_route_status CHECK (status IN ('Pending Rate','Pending Approval','Approved','Rejected','Withdrawn','Inactive')),
  CONSTRAINT chk_route_distance CHECK (distance_km > 0 AND distance_km <= 3000),
  CONSTRAINT chk_route_ends CHECK (origin_key <> destination_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Contractual rate versions of a route. Billing enters, Admin approves (separation of duties).
--   Flat   : rate_amount = amount for the whole trip
--   Per Km : rate_amount = amount per km, never lower than minimum_charge
-- An Approved row is never edited: a revision is a NEW row; on approval the old one becomes Superseded.
-- current_key: at most ONE current approved rate per route.  pending_key: at most ONE pending rate per route.
CREATE TABLE client_route_rates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  route_id INT NOT NULL,
  version_no INT NOT NULL,
  rate_type VARCHAR(10) NOT NULL,
  rate_amount DECIMAL(12,2) NOT NULL,
  minimum_charge DECIMAL(12,2) NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'Pending Approval',
  entered_by INT NOT NULL,
  entered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  entry_notes VARCHAR(300) NULL,
  decided_by INT NULL,
  decided_at DATETIME NULL,
  decision_notes VARCHAR(300) NULL,
  effective_from DATETIME NULL COMMENT 'set when approved',
  effective_to DATETIME NULL COMMENT 'set when replaced by a newer approved rate',
  current_key TINYINT AS (CASE WHEN status = 'Approved' THEN 1 ELSE NULL END) STORED,
  pending_key TINYINT AS (CASE WHEN status = 'Pending Approval' THEN 1 ELSE NULL END) STORED,
  FOREIGN KEY (route_id) REFERENCES client_routes(id),
  FOREIGN KEY (entered_by) REFERENCES users(id),
  FOREIGN KEY (decided_by) REFERENCES users(id),
  UNIQUE KEY uq_route_rate_version (route_id, version_no),
  UNIQUE KEY uq_route_rate_current (route_id, current_key),
  UNIQUE KEY uq_route_rate_pending (route_id, pending_key),
  CONSTRAINT chk_route_rate_type CHECK (rate_type IN ('Flat','Per Km')),
  CONSTRAINT chk_route_rate_status CHECK (status IN ('Pending Approval','Approved','Rejected','Superseded')),
  CONSTRAINT chk_route_rate_amount CHECK (rate_amount > 0 AND minimum_charge >= 0),
  CONSTRAINT chk_route_rate_min CHECK (rate_type = 'Per Km' OR minimum_charge = 0),
  CONSTRAINT chk_route_rate_version CHECK (version_no >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- History of each route (who requested, entered the rate, approved, rejected, deactivated).
CREATE TABLE client_route_events (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  route_id INT NOT NULL,
  rate_id INT NULL,
  actor_id INT NULL,
  event_type VARCHAR(80) NOT NULL,
  from_status VARCHAR(20) NOT NULL DEFAULT '',
  to_status VARCHAR(20) NOT NULL DEFAULT '',
  notes VARCHAR(500) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (route_id) REFERENCES client_routes(id),
  FOREIGN KEY (rate_id) REFERENCES client_route_rates(id),
  FOREIGN KEY (actor_id) REFERENCES users(id),
  INDEX idx_route_events (route_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- MAP SERVICES  (Checklist #5: road distance with openrouteservice)
-- Same statements as database/migrations/2026-11-06_04_maps_routing.sql
-- (the ALTER keeps the column order identical to an upgraded database).
-- =====================================================================
CREATE TABLE map_cache (
  cache_key CHAR(64) PRIMARY KEY COMMENT 'sha256 of service + provider + option + rounded points or search text',
  service VARCHAR(20) NOT NULL,
  payload MEDIUMTEXT NOT NULL COMMENT 'JSON answer (no API key, no user data)',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  INDEX idx_map_cache_expiry (expires_at),
  CONSTRAINT chk_map_cache_service CHECK (service IN ('route','search','reverse'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE map_api_usage (
  usage_date DATE NOT NULL,
  service VARCHAR(20) NOT NULL,
  calls INT NOT NULL DEFAULT 0,
  PRIMARY KEY (usage_date, service),
  CONSTRAINT chk_map_usage_service CHECK (service IN ('route','search','reverse'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE map_user_usage (
  user_id INT NOT NULL,
  minute_start DATETIME NOT NULL,
  calls INT NOT NULL DEFAULT 0,
  PRIMARY KEY (user_id, minute_start),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE client_routes
  ADD COLUMN route_option VARCHAR(20) NULL COMMENT 'recommended, avoid_tolls or general_roads (NULL = typed km only)',
  ADD COLUMN distance_computed_km DECIMAL(10,2) NULL COMMENT 'road km computed by the map service (NULL = not computed)',
  ADD COLUMN distance_provider VARCHAR(60) NULL COMMENT 'e.g. openrouteservice/driving-hgv',
  ADD COLUMN distance_computed_at DATETIME NULL,
  ADD COLUMN distance_override_reason VARCHAR(300) NULL COMMENT 'why the final km differs from the computed km',
  ADD COLUMN route_geometry MEDIUMTEXT NULL COMMENT 'route line (encoded polyline) for display',
  ADD CONSTRAINT chk_route_option CHECK (route_option IS NULL OR route_option IN ('recommended','avoid_tolls','general_roads')),
  ADD CONSTRAINT chk_route_computed_km CHECK (distance_computed_km IS NULL OR (distance_computed_km > 0 AND distance_computed_km <= 3000)),
  ADD CONSTRAINT chk_route_distance_source CHECK (distance_source IN ('Manual','Map','Map (edited)'));

-- FRESH_INSTALL_SECURITY_05_06_SYNC_V1
-- Security schema for brand-new installs (schema.sql followed by extension.sql).
-- Keep in sync with migrations 05/06, applied separately to EXISTING databases.
-- Do NOT import this whole extension.sql into a live/upgraded database.
-- =====================================================================
-- Exact schema from database/migrations/2026-11-06_05_account_ownership.sql
-- Security S1: credential ownership / verified password changes.
-- Run ONCE using phpMyAdmin after backup, before testing new PHP/JS.
ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0;
CREATE TABLE IF NOT EXISTS password_change_challenges (
    user_id INT NOT NULL PRIMARY KEY,
    password_hash VARCHAR(255) NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pw_change_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Existing employees remain enrolled; new Admin-created or Admin-reset accounts are forced to change.

-- Exact schema from database/migrations/2026-11-06_06_trusted_devices_email.sql
-- Security S2: Remember this device (hashed server-side tokens) + verified email ownership.
-- Run ONCE in phpMyAdmin, after Security S1 migration and before S2 code deployment.
CREATE TABLE IF NOT EXISTS trusted_login_devices (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id INT NOT NULL,
 token_hash CHAR(64) NOT NULL,
 expires_at DATETIME NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 last_used_at DATETIME NULL,
 UNIQUE KEY uq_trusted_token (token_hash),
 INDEX idx_trusted_user (user_id, expires_at),
 CONSTRAINT fk_trusted_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS email_change_challenges (
 user_id INT NOT NULL PRIMARY KEY,
 new_email VARCHAR(190) NOT NULL,
 code_hash VARCHAR(255) NOT NULL,
 expires_at DATETIME NOT NULL,
 attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
 requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_email_change_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- TRIP ROUTING  (Checklist #5 Phase 3: road distance in Trip Management)
-- Same statements as database/migrations/2026-11-07_07_trip_routing.sql
-- (the ALTER keeps the column order identical to an upgraded database).
-- =====================================================================
ALTER TABLE trips
  ADD COLUMN distance_source VARCHAR(30) NOT NULL DEFAULT 'Manual' COMMENT 'Manual, Map, Map (edited), Client Route, Client Route (edited)',
  ADD COLUMN route_option VARCHAR(20) NULL COMMENT 'recommended, avoid_tolls or general_roads',
  ADD COLUMN distance_computed_km DECIMAL(10,2) NULL COMMENT 'road km computed by the map service, or the km of the approved client route',
  ADD COLUMN distance_provider VARCHAR(60) NULL COMMENT 'e.g. openrouteservice/driving-hgv or Client route RTE-2026-0001',
  ADD COLUMN distance_computed_at DATETIME NULL,
  ADD COLUMN distance_override_reason VARCHAR(300) NULL COMMENT 'why the billable km differs from the computed / route km',
  ADD COLUMN client_route_id INT NULL COMMENT 'approved client route chosen for this trip (history: kept even if the route is later deactivated)',
  ADD CONSTRAINT fk_trip_client_route FOREIGN KEY (client_route_id) REFERENCES client_routes(id),
  ADD CONSTRAINT chk_trip_distance_source CHECK (distance_source IN ('Manual','Map','Map (edited)','Client Route','Client Route (edited)')),
  ADD CONSTRAINT chk_trip_route_option CHECK (route_option IS NULL OR route_option IN ('recommended','avoid_tolls','general_roads')),
  ADD CONSTRAINT chk_trip_computed_km CHECK (distance_computed_km IS NULL OR (distance_computed_km > 0 AND distance_computed_km <= 3000));

CREATE TABLE trip_routes (
  trip_id INT PRIMARY KEY,
  geometry MEDIUMTEXT NOT NULL COMMENT 'route line (encoded polyline) for display',
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (trip_id) REFERENCES trips(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- CHECKLIST_42_ATTENDANCE_PHASE1_V1: fresh-install attendance records.
CREATE TABLE attendance_marks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  duty_date DATE NOT NULL,
  duty_shift VARCHAR(5) NOT NULL,
  personnel_id INT NULL,
  mechanic_id INT NULL,
  status VARCHAR(7) NOT NULL,
  marked_by INT NOT NULL,
  marked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_att_person (duty_date,duty_shift,personnel_id),
  UNIQUE KEY uq_att_mech (duty_date,duty_shift,mechanic_id),
  INDEX idx_att_date (duty_date,duty_shift),
  CONSTRAINT fk_att_person FOREIGN KEY (personnel_id) REFERENCES personnel(id),
  CONSTRAINT fk_att_mech FOREIGN KEY (mechanic_id) REFERENCES mechanics(id),
  CONSTRAINT fk_att_admin FOREIGN KEY (marked_by) REFERENCES users(id),
  CONSTRAINT chk_att_kind CHECK ((personnel_id IS NOT NULL AND mechanic_id IS NULL) OR (personnel_id IS NULL AND mechanic_id IS NOT NULL)),
  CONSTRAINT chk_att_shift CHECK (duty_shift IN ('Day','Night')),
  CONSTRAINT chk_att_status CHECK (status IN ('Present','Absent'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- MECHANIC_ATTENDANCE_V1: Time In / Time Out sessions of mechanics (Maintenance). History is never overwritten.
CREATE TABLE mechanic_attendance (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  mechanic_id INT NOT NULL,
  time_in DATETIME NOT NULL,
  time_out DATETIME NULL,
  time_in_by INT NOT NULL,
  time_out_by INT NULL,
  open_mechanic INT AS (CASE WHEN time_out IS NULL THEN mechanic_id ELSE NULL END) STORED COMMENT 'one open Time In per mechanic (database rule)',
  UNIQUE KEY uq_mattn_one_open (open_mechanic),
  INDEX idx_mattn_mechanic (mechanic_id, time_in),
  CONSTRAINT fk_mattn_mechanic FOREIGN KEY (mechanic_id) REFERENCES mechanics(id),
  CONSTRAINT fk_mattn_in_by FOREIGN KEY (time_in_by) REFERENCES users(id),
  CONSTRAINT fk_mattn_out_by FOREIGN KEY (time_out_by) REFERENCES users(id),
  CONSTRAINT chk_mattn_order CHECK (time_out IS NULL OR time_out >= time_in)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Originals of mechanics set Unavailable by migration 2026-11-10_10 (empty on a fresh install; kept so the schema is identical to an upgraded database).
CREATE TABLE mechanic_availability_backup (
  mechanic_id INT NOT NULL PRIMARY KEY,
  original_availability VARCHAR(15) NOT NULL,
  original_unavailable_reason VARCHAR(150) NULL,
  backed_up_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_mavb_mechanic FOREIGN KEY (mechanic_id) REFERENCES mechanics(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='originals of mechanics set Unavailable by migration 2026-11-10_10; used by its rollback';

-- DAILY_ATTENDANCE_V1: one Operations Attendance mark per driver/helper/mechanic per date (no permanent shifts).
-- attendance_marks above is kept as the legacy shift-based archive (never written by the application any more).
CREATE TABLE attendance_daily (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  duty_date DATE NOT NULL,
  personnel_id INT NULL,
  mechanic_id INT NULL,
  status VARCHAR(7) NOT NULL,
  marked_by INT NOT NULL,
  marked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  legacy_shifts VARCHAR(9) NULL COMMENT 'Day, Night or Day+Night when copied from attendance_marks; NULL for marks made after the migration',
  UNIQUE KEY uq_attd_person (duty_date,personnel_id),
  UNIQUE KEY uq_attd_mech (duty_date,mechanic_id),
  INDEX idx_attd_date (duty_date),
  CONSTRAINT fk_attd_person FOREIGN KEY (personnel_id) REFERENCES personnel(id),
  CONSTRAINT fk_attd_mech FOREIGN KEY (mechanic_id) REFERENCES mechanics(id),
  CONSTRAINT fk_attd_user FOREIGN KEY (marked_by) REFERENCES users(id),
  CONSTRAINT chk_attd_kind CHECK ((personnel_id IS NOT NULL AND mechanic_id IS NULL) OR (personnel_id IS NULL AND mechanic_id IS NOT NULL)),
  CONSTRAINT chk_attd_status CHECK (status IN ('Present','Absent'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migrations 2026-11-10_10 and 2026-11-10_11: their tables are created ABOVE, so a fresh install is already complete.
-- Recorded here, AFTER the tables, so a failure while creating them can never leave a false migration record.
INSERT INTO schema_migrations (id) VALUES ('2026-11-10_10_mechanic_attendance'), ('2026-11-10_11_daily_attendance');

-- Migration 2026-11-12_12 (JOB_HELPER_MECHANICS_V1): assisting mechanics of a repair job. maintenance_jobs.mechanic_id stays the lead.
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

-- Migration 2026-11-12_13 (STOCK_COUNT_RECONCILIATION_V1): physical stock counts reviewed by Admin before posting. See the migration file.
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


-- Additive Dispatcher attendance sessions. Existing attendance_marks and operational rows are untouched.
CREATE TABLE IF NOT EXISTS dispatcher_attendance_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  personnel_id INT NOT NULL,
  dispatcher_role VARCHAR(40) NOT NULL,
  duty_date DATE NOT NULL,
  duty_shift VARCHAR(5) NOT NULL,
  time_in DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  time_out DATETIME NULL,
  timed_in_by INT NOT NULL,
  timed_out_by INT NULL,
  request_key CHAR(36) NOT NULL,
  time_out_key CHAR(36) NULL,
  open_personnel_id INT GENERATED ALWAYS AS (CASE WHEN time_out IS NULL THEN personnel_id ELSE NULL END) STORED,
  UNIQUE KEY uq_dispatcher_open_person (open_personnel_id),
  UNIQUE KEY uq_dispatcher_time_in_key (request_key),
  UNIQUE KEY uq_dispatcher_time_out_key (time_out_key),
  INDEX idx_dispatcher_session_person (personnel_id,id),
  INDEX idx_dispatcher_session_shift (duty_date,duty_shift),
  CONSTRAINT fk_ds_person FOREIGN KEY (personnel_id) REFERENCES personnel(id),
  CONSTRAINT fk_ds_in_user FOREIGN KEY (timed_in_by) REFERENCES users(id),
  CONSTRAINT fk_ds_out_user FOREIGN KEY (timed_out_by) REFERENCES users(id),
  CONSTRAINT chk_ds_role CHECK (dispatcher_role IN ('car_carrier_dispatcher','container_wingvan_dispatcher')),
  CONSTRAINT chk_ds_shift CHECK (duty_shift IN ('Day','Night')),
  CONSTRAINT chk_ds_times CHECK (time_out IS NULL OR time_out>=time_in)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO schema_migrations (id) VALUES ('2026-11-10_09_dispatcher_sessions');
INSERT IGNORE INTO schema_migrations (id) VALUES ('2026-11-10_10_trip_dispatcher_shift');

-- V4.3: reuse the authoritative vehicle/person relationships; never backfill or rewrite assignments.
-- Preflight: SELECT assigned_driver_id, COUNT(*) FROM vehicles WHERE active=1
-- AND assigned_driver_id IS NOT NULL GROUP BY assigned_driver_id HAVING COUNT(*)>1;
-- If duplicates exist the UNIQUE constraint deliberately fails. Resolve them explicitly before retrying.
SET @fleetops_v43_sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='vehicles' AND COLUMN_NAME='active_fixed_driver_id'),
  'DO 0',
  'ALTER TABLE vehicles ADD COLUMN active_fixed_driver_id INT GENERATED ALWAYS AS (CASE WHEN active=1 THEN assigned_driver_id ELSE NULL END) STORED, ADD UNIQUE KEY uq_vehicle_active_fixed_driver (active_fixed_driver_id)'
);
PREPARE fleetops_v43_stmt FROM @fleetops_v43_sql;
EXECUTE fleetops_v43_stmt;
DEALLOCATE PREPARE fleetops_v43_stmt;
SET @fleetops_v43_sql = IF(
  EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='vehicles' AND INDEX_NAME='uq_vehicle_active_fixed_driver'),
  'DO 0',
  'ALTER TABLE vehicles ADD UNIQUE KEY uq_vehicle_active_fixed_driver (active_fixed_driver_id)'
);
PREPARE fleetops_v43_stmt FROM @fleetops_v43_sql;
EXECUTE fleetops_v43_stmt;
DEALLOCATE PREPARE fleetops_v43_stmt;
SET @fleetops_v43_sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='trips' AND COLUMN_NAME='helper_substitution_reason'),
  'DO 0',
  'ALTER TABLE trips ADD COLUMN helper_substitution_reason VARCHAR(255) NULL COMMENT ''Trip-only helper substitution; permanent helper remains on vehicles'''
);
PREPARE fleetops_v43_stmt FROM @fleetops_v43_sql;
EXECUTE fleetops_v43_stmt;
DEALLOCATE PREPARE fleetops_v43_stmt;
INSERT IGNORE INTO schema_migrations (id) VALUES ('2026-11-11_11_operations_v43');

-- Additive, no original ownership/history backfill. Apply before deploying turnover code.
CREATE TABLE IF NOT EXISTS trip_responsibilities (
 trip_id INT NOT NULL PRIMARY KEY,
 initial_dispatcher_id INT NOT NULL,
 current_dispatcher_id INT NOT NULL,
 assigned_by INT NOT NULL,
 assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 FOREIGN KEY (trip_id) REFERENCES trips(id),
 FOREIGN KEY (initial_dispatcher_id) REFERENCES users(id),
 FOREIGN KEY (current_dispatcher_id) REFERENCES users(id),
 FOREIGN KEY (assigned_by) REFERENCES users(id),
 INDEX idx_responsible_account(current_dispatcher_id,trip_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS dispatcher_turnovers (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 outgoing_id INT NOT NULL,
 incoming_id INT NOT NULL,
 dispatcher_role VARCHAR(40) NOT NULL,
 status VARCHAR(10) NOT NULL DEFAULT 'Pending',
 endorsed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 accepted_at DATETIME NULL,
 accepted_by INT NULL,
 remarks VARCHAR(1000) NULL,
 request_key CHAR(36) NOT NULL UNIQUE,
 FOREIGN KEY(outgoing_id) REFERENCES users(id),
 FOREIGN KEY(incoming_id) REFERENCES users(id),
 FOREIGN KEY(accepted_by) REFERENCES users(id),
 INDEX idx_turnover_incoming(incoming_id,status,id),
 INDEX idx_turnover_outgoing(outgoing_id,status,id),
 CHECK (outgoing_id<>incoming_id),
 CHECK (status IN ('Pending','Accepted')),
 CHECK (dispatcher_role IN ('car_carrier_dispatcher','container_wingvan_dispatcher')),
 CHECK ((status='Pending' AND accepted_at IS NULL AND accepted_by IS NULL) OR (status='Accepted' AND accepted_at IS NOT NULL AND YEAR(accepted_at)>=1000 AND YEAR(endorsed_at)>=1000 AND accepted_at>=endorsed_at AND accepted_by IS NOT NULL AND accepted_by=incoming_id))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS dispatcher_turnover_trips (
 turnover_id BIGINT UNSIGNED NOT NULL,
 trip_id INT NOT NULL,
 responsibility_version INT UNSIGNED NOT NULL,
 summary_json LONGTEXT NOT NULL,
 pending_trip_id INT NULL,
 PRIMARY KEY(turnover_id,trip_id),
 UNIQUE KEY uq_pending_trip_turnover(pending_trip_id),
 FOREIGN KEY(turnover_id) REFERENCES dispatcher_turnovers(id),
 FOREIGN KEY(trip_id) REFERENCES trips(id),
 FOREIGN KEY(pending_trip_id) REFERENCES trips(id),
 CHECK(pending_trip_id IS NULL OR pending_trip_id=trip_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO schema_migrations(id) VALUES ('2026-11-12_12_dispatcher_turnover');

-- Additive pricing history. No defaults, backfill, invoice or payment changes.
CREATE TABLE IF NOT EXISTS pricing_settings_history (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 setting_key VARCHAR(40) NOT NULL,
 effective_date DATE NOT NULL,
 value_json LONGTEXT NOT NULL,
 reference VARCHAR(300) NOT NULL,
 actor_id INT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_pricing_effective(setting_key,effective_date),
 FOREIGN KEY(actor_id) REFERENCES users(id),
 CHECK(JSON_VALID(value_json)),
 CHECK(setting_key IN ('diesel','Wing Van','Container','Car Carrier','garage'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS billing_pricing (
 billing_id INT PRIMARY KEY,
 snapshot_json LONGTEXT NOT NULL,
 captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(billing_id) REFERENCES billings(id),
 CHECK(JSON_VALID(snapshot_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO schema_migrations(id) VALUES('2026-11-13_13_fixed_route_fuel');

-- 2026-11-14_14_incident_clearance: Motorpool vehicle clearance (Fit / Not Fit to Continue) and Dispatcher resumption on incidents.
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
