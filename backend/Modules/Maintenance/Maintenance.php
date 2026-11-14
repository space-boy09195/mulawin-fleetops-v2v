<?php
/*
 * =====================================================================
 *  backend/Modules/Maintenance/Maintenance.php  —  CORRECTIVE MAINTENANCE
 * =====================================================================
 *  Objective 2.1  maintenance records (findings, problems, repair activities,
 *                 parts used, repair dates, costs, documents)
 *  Objective 2.2  repairs from trip incident reports + truck availability
 *  Objective 2.3  on-premise repairs (inspection, repair, completion, verification)
 *  Objective 2.6  monitoring of ongoing and completed repairs
 *
 *  Corrective maintenance = an unexpected truck problem that needs inspection,
 *  diagnosis and repair. (There is no monthly preventive maintenance.)
 *
 *  Workflow (Motorpool Custodian):
 *    Reported          problem reported (garage) or incident reported (dispatcher)
 *    Under Inspection  an Available mechanic from the Mechanic Masterlist is assigned
 *                      (incidents: Minor / Major assessment happens here too)
 *    Under Repair      inspection findings and diagnosis recorded
 *    Completed         corrective action, completion date and evidence recorded;
 *                      the mechanic becomes free again
 *    Verify & return   operational check passed -> ONLY NOW the truck is available
 *    Cancelled         garage report made by mistake (no parts released)
 *
 *  Truck availability: a truck with an unresolved record is Under Maintenance and
 *  cannot be assigned (a Minor roadside repair keeps it On Trip). Trips that had the
 *  truck reserved are re-assigned automatically. Database rules: one unresolved
 *  record per truck, one active repair per mechanic.
 * =====================================================================
 */
declare(strict_types=1);
core('FleetState');
core('Notify');
core('Files');
core('Money');

final class Maintenance {
    private const ACT = ['motorpool'];
    public const COST_TYPES = ['Labor', 'Outside Service', 'Materials', 'Other'];
    public const PRIORITIES = ['Low', 'Normal', 'High', 'Critical'];
    public const ACTIVE = ['Reported', 'Under Inspection', 'Under Repair'];

    public static function read(array $u, string $page, array $q): array {
        $p = db();
        if ($page === 'maintenance_overview') return self::overview($u, $p);
        if ($page === 'maint_history') return self::history($u, $p);
        $data = readSections([
            'jobs' => fn() => many($p, "SELECT m.id, m.job_ref, m.vehicle_id, m.source, m.problem, m.reported_on, m.priority, m.severity, m.status, m.mechanic_id, m.trip_id, m.incident_id,
                    m.expected_completion, m.completed_on, m.verified_at, m.created_at, mc.full_name AS mechanic, mc.mechanic_code,
                    v.plate_number, v.truck_number, v.truck_type, v.status AS truck_status,
                    i.incident_ref, i.status AS incident_status, t.trip_ref, t.status AS trip_status,
                    (SELECT COALESCE(SUM(c.amount),0) FROM maintenance_costs c WHERE c.maintenance_job_id=m.id) AS other_cost,
                    (SELECT COALESCE(SUM(l.released_qty*l.unit_cost),0) FROM part_request_lines l JOIN part_requests r ON r.id=l.part_request_id WHERE r.maintenance_job_id=m.id) AS parts_cost
                FROM maintenance_jobs m JOIN vehicles v ON v.id=m.vehicle_id LEFT JOIN mechanics mc ON mc.id=m.mechanic_id
                LEFT JOIN incidents i ON i.id=m.incident_id LEFT JOIN trips t ON t.id=m.trip_id
                ORDER BY FIELD(m.status,'Reported','Under Inspection','Under Repair','Completed','Cancelled'), m.id DESC LIMIT 1000"),
        ] + self::formSections($p));
        // JOB_HELPER_MECHANICS_V1: assisting mechanics of the active repairs (empty until migration 12 is applied).
        $data['helpers'] = self::helpersReady($p) ? many($p, "SELECT h.maintenance_job_id AS job_id, h.mechanic_id, mc.full_name, mc.mechanic_code FROM maintenance_job_helpers h
                JOIN mechanics mc ON mc.id=h.mechanic_id WHERE h.released_at IS NULL ORDER BY h.id") : [];
        $data['helpersReady'] = self::helpersReady($p);
        $data['costTypes'] = self::COST_TYPES;
        $data['can'] = ['act' => hasRole($u, ...self::ACT)];
        return $data;
    }

    /** Trucks and mechanics for the "Report a truck problem" form (Repair Management and Overview). */
    private static function formSections(PDO $p): array {
        return [
            'trucks' => fn() => many($p, "SELECT v.id, v.plate_number, v.truck_number, v.truck_type, v.status,
                    (SELECT m.job_ref FROM maintenance_jobs m WHERE m.vehicle_id=v.id AND m.open_key=1 LIMIT 1) AS open_job,
                    (SELECT t.trip_ref FROM trip_assignments a JOIN trips t ON t.id=a.trip_id WHERE a.resource_type='vehicle' AND a.resource_id=v.id AND t.status IN ('Dispatched','On Hold - Incident Reported','In Transit') LIMIT 1) AS on_road
                FROM vehicles v WHERE v.active=1 ORDER BY v.plate_number"),
            'mechanics' => fn() => many($p, "SELECT id, mechanic_code, full_name, specialization, availability FROM mechanics WHERE active=1 ORDER BY full_name"),
        ];
    }

    /*
     * MAINTENANCE_OVERVIEW_PHASE1_V1 — read-only landing page for the Motorpool Custodian.
     * Uses only existing statuses and records; nothing is written here.
     *   - "For verification" = status Completed with verified_at still empty (the existing
     *     Verify & return to service step; it is not a separate status value).
     *   - Parts request data is included only for Motorpool and roles that may open Parts Requests.
     *   - Recent activity = maintenance_events (+ parts requests and stock-out releases).
     */
    private const OVERVIEW_ATTENTION_LIMIT = 25;
    private const OVERVIEW_ACTIVITY_LIMIT = 15;

    private static function overview(array $u, PDO $p): array {
        // Parts data: Motorpool (its own requests) and the roles that can open the Parts Requests page.
        // Never inventory values, budget, purchase orders or supplier data.
        $parts = hasRole($u, 'motorpool') || Access::canOpen($u, 'parts_requests');
        $data = readSections([
            'summary' => fn() => self::overviewSummary($p, $parts),
            'attention' => fn() => self::overviewAttention($p, $parts),
            'activity' => fn() => self::overviewActivity($p, $parts),
            // STAGE_A_MOTORPOOL_UI: read-only status panels (same values as the Mechanic Masterlist / Truck Masterlist).
            'crew' => fn() => array_map('intval', one($p, "SELECT COUNT(*) AS active,
                    COALESCE(SUM(availability='Available'),0) AS available, COALESCE(SUM(availability='Assigned'),0) AS assigned,
                    COALESCE(SUM(availability='Unavailable'),0) AS unavailable,
                    (SELECT COUNT(*) FROM mechanic_attendance a JOIN mechanics x ON x.id=a.mechanic_id WHERE a.time_out IS NULL AND x.active=1) AS on_duty
                FROM mechanics WHERE active=1")),
            'fleet' => fn() => [
                'active_trucks' => (int)one($p, 'SELECT COUNT(*) AS n FROM vehicles WHERE active=1')['n'],
                'in_repair' => many($p, "SELECT v.id AS vehicle_id, v.plate_number, v.truck_number, v.truck_type, m.id AS job_id, m.job_ref, m.status,
                        (m.status='Completed' AND m.verified_at IS NULL) AS awaiting_verification, m.reported_on
                    FROM maintenance_jobs m JOIN vehicles v ON v.id=m.vehicle_id WHERE m.open_key=1 ORDER BY m.reported_on, m.id"),
            ],
        ] + self::formSections($p));
        $data['can'] = ['act' => hasRole($u, ...self::ACT), 'parts' => $parts, 'requestParts' => hasRole($u, 'motorpool')];
        $data['limits'] = ['attention' => self::OVERVIEW_ATTENTION_LIMIT, 'activity' => self::OVERVIEW_ACTIVITY_LIMIT];
        return $data;
    }

    /** One row per maintenance record / parts request, so related events are never counted twice. */
    private static function overviewSummary(PDO $p, bool $parts): array {
        $j = one($p, "SELECT COALESCE(SUM(status='Reported'),0) AS new_reports,
                COALESCE(SUM(status='Reported' AND source='Incident'),0) AS new_incidents,
                COALESCE(SUM(status='Under Inspection'),0) AS under_inspection,
                COALESCE(SUM(status='Under Repair'),0) AS under_repair,
                COALESCE(SUM(status='Completed' AND verified_at IS NULL),0) AS for_verification
            FROM maintenance_jobs WHERE open_key=1");
        $out = array_map('intval', $j);
        if ($parts) {
            $r = one($p, "SELECT COALESCE(SUM(status='Requested'),0) AS requested, COALESCE(SUM(status='Partially Released'),0) AS partially_released
                FROM part_requests WHERE status IN ('Requested','Partially Released')");
            $out['parts_requested'] = (int)$r['requested'];
            $out['parts_partially_released'] = (int)$r['partially_released'];
            $out['pending_parts'] = (int)$r['requested'] + (int)$r['partially_released'];
        }
        return $out;
    }

    /** Records that wait for an action of the Motorpool Custodian, longest waiting first. */
    private static function overviewAttention(PDO $p, bool $parts): array {
        $lim = self::OVERVIEW_ATTENTION_LIMIT + 1;   // one extra row tells the screen that the list is cut
        $rows = many($p, "SELECT 'job' AS kind, m.id AS job_id, m.job_ref, NULL AS request_id, NULL AS request_ref, m.source, m.status, m.problem AS issue,
                    v.plate_number, v.truck_number, i.incident_ref, t.trip_ref,
                    CASE WHEN m.status='Completed' THEN m.completed_at ELSE m.created_at END AS since,
                    CASE WHEN m.status='Completed' THEN 'verify' WHEN m.source='Incident' THEN 'assess' ELSE 'assign' END AS next_action
                FROM maintenance_jobs m JOIN vehicles v ON v.id=m.vehicle_id LEFT JOIN incidents i ON i.id=m.incident_id LEFT JOIN trips t ON t.id=m.trip_id
                WHERE m.open_key=1 AND (m.status='Reported' OR (m.status='Completed' AND m.verified_at IS NULL))
                ORDER BY since, m.id LIMIT $lim");
        if ($parts) {
            // Parts the Purchasing Officer marked Not Available, for a repair that is still open (or no repair record).
            $rows = array_merge($rows, many($p, "SELECT 'parts' AS kind, m.id AS job_id, m.job_ref, r.id AS request_id, r.request_ref, m.source, r.status, r.purpose AS issue,
                        v.plate_number, v.truck_number, NULL AS incident_ref, NULL AS trip_ref, COALESCE(r.processed_at, r.created_at) AS since, 'parts_follow_up' AS next_action
                    FROM part_requests r JOIN vehicles v ON v.id=r.vehicle_id LEFT JOIN maintenance_jobs m ON m.id=r.maintenance_job_id
                    WHERE r.status='Not Available' AND (r.maintenance_job_id IS NULL OR m.open_key=1)
                    ORDER BY since, r.id LIMIT $lim"));
            usort($rows, fn($a, $b) => [(string)$a['since'], $a['kind'], (int)($a['job_id'] ?? 0)] <=> [(string)$b['since'], $b['kind'], (int)($b['job_id'] ?? 0)]);
        }
        foreach ($rows as &$r) $r['issue'] = cut((string)$r['issue'], 160);
        unset($r);
        return $rows;
    }

    /** Latest maintenance events in plain words. Parts entries only for Motorpool and roles that may open Parts Requests. */
    private static function overviewActivity(PDO $p, bool $parts): array {
        $lim = self::OVERVIEW_ACTIVITY_LIMIT;
        $sql = "SELECT 'job' AS kind, e.created_at AS at, e.from_status, e.to_status, e.details, m.id AS job_id, m.job_ref, NULL AS request_id, NULL AS request_ref,
                    v.plate_number, u.full_name AS actor, mc.full_name AS mechanic, NULL AS units, e.id AS sort_id
                FROM maintenance_events e JOIN maintenance_jobs m ON m.id=e.maintenance_job_id JOIN vehicles v ON v.id=m.vehicle_id
                LEFT JOIN users u ON u.id=e.actor_id LEFT JOIN mechanics mc ON mc.id=e.mechanic_id";
        if ($parts) {
            $sql = "($sql ORDER BY e.id DESC LIMIT $lim)
                UNION ALL
                (SELECT 'parts_requested', r.created_at, NULL, r.status, r.purpose, m.id, m.job_ref, r.id, r.request_ref, v.plate_number, u.full_name, NULL,
                    (SELECT SUM(l.quantity) FROM part_request_lines l WHERE l.part_request_id=r.id), r.id
                 FROM part_requests r JOIN vehicles v ON v.id=r.vehicle_id LEFT JOIN maintenance_jobs m ON m.id=r.maintenance_job_id JOIN users u ON u.id=r.requested_by
                 ORDER BY r.id DESC LIMIT $lim)
                UNION ALL
                (SELECT 'parts_released', g.at, NULL, NULL, NULL, m.id, m.job_ref, r.id, r.request_ref, v.plate_number, u.full_name, NULL, g.units, g.last_id
                 FROM (SELECT reference_id, actor_id, created_at AS at, -SUM(quantity_delta) AS units, MAX(id) AS last_id FROM stock_movements
                       WHERE reference_type='part_request' AND movement_type='Stock Out' GROUP BY reference_id, actor_id, created_at) g
                 JOIN part_requests r ON r.id=g.reference_id JOIN vehicles v ON v.id=r.vehicle_id
                 LEFT JOIN maintenance_jobs m ON m.id=r.maintenance_job_id JOIN users u ON u.id=g.actor_id
                 ORDER BY g.last_id DESC LIMIT $lim)
                ORDER BY at DESC, sort_id DESC LIMIT $lim";
        } else {
            $sql .= " ORDER BY e.created_at DESC, e.id DESC LIMIT $lim";
        }
        $rows = many($p, $sql);
        foreach ($rows as &$r) {
            $r['label'] = self::activityLabel($r);
            $r['details'] = $r['details'] === null ? null : cut((string)$r['details'], 160);
            unset($r['from_status'], $r['sort_id']);
        }
        unset($r);
        return $rows;
    }

    /** Plain-language name of an event. The detail prefixes are the ones written by this module and by Trips. */
    private static function activityLabel(array $e): string {
        if ($e['kind'] === 'parts_requested') return 'Parts request submitted';
        if ($e['kind'] === 'parts_released') return 'Parts released';
        $from = $e['from_status']; $to = $e['to_status']; $det = (string)$e['details'];
        if ($to === 'Cancelled') return 'Record cancelled';
        if ($from === null && $to === 'Reported') return str_starts_with($det, 'from incident') ? 'Trip incident reported' : 'Truck issue reported';
        if (str_starts_with($det, 'assisting mechanic added')) return 'Assisting mechanic added';
        if (str_starts_with($det, 'assisting mechanic removed')) return 'Assisting mechanic removed';
        if (str_starts_with($det, 'mechanic changed')) return 'Mechanic changed';
        if (str_starts_with($det, 'mechanic assigned')) return 'Mechanic assigned';
        if (str_starts_with($det, 'assessed ')) return 'Incident assessed, mechanic assigned';
        if (str_starts_with($det, 'Arrival checklist findings')) return 'Arrival checklist findings added';
        if ($from === 'Under Inspection' && $to === 'Under Repair') return 'Inspection findings recorded';
        if ($from === 'Under Repair' && $to === 'Completed') return 'Repair completed';
        if ($from === 'Completed' && $to === 'Under Repair') return 'Verification failed, back to repair';
        if ($from === 'Completed' && $to === 'Completed' && str_starts_with($det, 'verified')) return 'Verified, returned to service';
        return $to;
    }

    /**
     * Maintenance History: finished and cancelled records with their cost and attachment count.
     * Full detail (incident, photos, parts, costs) opens through details(), which checks the role again.
     */
    private static function history(array $u, PDO $p): array {
        return readSections([
            'records' => fn() => many($p, "SELECT m.id, m.job_ref, m.source, m.problem, m.status, m.reported_on, m.completed_on, m.verified_at, v.plate_number, v.truck_type,
                    mc.full_name AS mechanic, i.incident_ref,
                    (SELECT COALESCE(SUM(c.amount),0) FROM maintenance_costs c WHERE c.maintenance_job_id=m.id) AS other_cost,
                    (SELECT COALESCE(SUM(l.released_qty*l.unit_cost),0) FROM part_request_lines l JOIN part_requests r ON r.id=l.part_request_id WHERE r.maintenance_job_id=m.id) AS parts_cost,
                    (SELECT COUNT(*) FROM documents d WHERE (d.entity_type='maintenance' AND d.entity_id=m.id) OR (m.incident_id IS NOT NULL AND d.entity_type='incident' AND d.entity_id=m.incident_id)) AS attachments
                FROM maintenance_jobs m JOIN vehicles v ON v.id=m.vehicle_id LEFT JOIN mechanics mc ON mc.id=m.mechanic_id LEFT JOIN incidents i ON i.id=m.incident_id
                WHERE m.status IN ('Completed','Cancelled') OR m.verified_at IS NOT NULL
                ORDER BY COALESCE(m.verified_at, m.completed_on, m.created_at) DESC, m.id DESC LIMIT 1000"),
            'trucks' => fn() => many($p, 'SELECT id, plate_number FROM vehicles ORDER BY plate_number'),
        ]);
    }

    public static function action(array $u, string $action, array $d): array {
        return match ($action) {
            'report' => self::report($u, $d),
            'assign_mechanic' => self::assignMechanic($u, $d),
            'helpers_add' => self::helpersAdd($u, $d),
            'helper_remove' => self::helperRemove($u, $d),
            'assess' => self::assess($u, $d),
            'inspect' => self::inspect($u, $d),
            'add_cost' => self::addCost($u, $d),
            'complete' => self::complete($u, $d),
            'verify' => self::verify($u, $d),
            'clearance' => self::clearance($u, $d),
            'cancel' => self::cancel($u, $d),
            'details' => self::details($u, $d),
            default => throw new BusinessRuleException('Unknown action.', 404),
        };
    }

    // ------------------------------------------------------------------ helpers
    private static function lockJob(PDO $p, int $id): array {
        $j = one($p, 'SELECT * FROM maintenance_jobs WHERE id=? FOR UPDATE', [$id]);
        must((bool)$j, 'Maintenance record not found.', 404);
        return $j;
    }

    private static function event(PDO $p, int $jobId, ?string $from, string $to, ?int $actor, string $details = '', ?int $mechanicId = null): void {
        run($p, 'INSERT INTO maintenance_events (maintenance_job_id, from_status, to_status, mechanic_id, details, actor_id) VALUES (?,?,?,?,?,?)',
            [$jobId, $from, $to, $mechanicId, cut($details, 500), $actor]);
    }

    /** Locks the mechanic and checks that a new repair can be given to them. */
    private static function freeMechanic(PDO $p, int $mechanicId, ?int $exceptJob = null): array {
        $m = one($p, 'SELECT * FROM mechanics WHERE id=? FOR UPDATE', [$mechanicId]);
        must($m && (int)$m['active'], 'Choose an active mechanic from the Mechanic Masterlist.');
        // Availability = an open Time In (Mechanic Attendance). The saved state is checked here, not only in the dropdown.
        $in = one($p, 'SELECT id FROM mechanic_attendance WHERE mechanic_id=? AND time_out IS NULL', [$mechanicId]);
        must((bool)$in, $m['full_name'] . ' is Unavailable (not timed in). The Motorpool Custodian must Time In the mechanic in the Mechanic Masterlist first.', 409);
        $busy = one($p, "SELECT job_ref, id FROM maintenance_jobs WHERE active_mechanic=?", [$mechanicId]);
        must(!$busy || (int)$busy['id'] === (int)$exceptJob, $m['full_name'] . ' is already assigned to ' . ($busy['job_ref'] ?? '') . '. Choose an Available mechanic.', 409);
        // JOB_HELPER_MECHANICS_V1: an assisting assignment also makes the mechanic busy (on any job, including this one:
        // remove them as assisting mechanic first before making them the lead).
        if (self::helpersReady($p)) {
            $helps = one($p, 'SELECT m.job_ref FROM maintenance_job_helpers h JOIN maintenance_jobs m ON m.id=h.maintenance_job_id WHERE h.active_mechanic=?', [$mechanicId]);
            must(!$helps, $m['full_name'] . ' is already assisting on ' . ($helps['job_ref'] ?? '') . '. Choose an Available mechanic.', 409);
        }
        return $m;
    }

    /** JOB_HELPER_MECHANICS_V1 — true once migration 2026-11-12_12 created maintenance_job_helpers (checked once per request). */
    private static ?bool $helpersReady = null;
    public static function helpersReady(PDO $p): bool {
        return self::$helpersReady ??= (bool)one($p, "SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='maintenance_job_helpers'");
    }

    /** Releases every active assisting mechanic of a job (completion, cancellation) and recomputes their availability. */
    private static function releaseHelpers(PDO $p, int $jobId, int $actor, string $reason): array {
        if (!self::helpersReady($p)) return [];
        $ids = array_map('intval', array_column(many($p, 'SELECT mechanic_id FROM maintenance_job_helpers WHERE maintenance_job_id=? AND released_at IS NULL FOR UPDATE', [$jobId]), 'mechanic_id'));
        if (!$ids) return [];
        run($p, 'UPDATE maintenance_job_helpers SET released_at=NOW(), released_by=?, release_reason=? WHERE maintenance_job_id=? AND released_at IS NULL', [$actor, cut($reason, 255), $jobId]);
        foreach ($ids as $mid) self::refreshMechanic($p, $mid);
        return $ids;
    }

    /** Recomputes a mechanic's availability from their active repairs and Time In (no open Time In = Unavailable). */
    public static function refreshMechanic(PDO $p, ?int $mechanicId): void {
        if (!$mechanicId) return;
        $m = one($p, 'SELECT availability FROM mechanics WHERE id=? FOR UPDATE', [$mechanicId]);
        if (!$m) return;
        $busy = (bool)one($p, 'SELECT id FROM maintenance_jobs WHERE active_mechanic=?', [$mechanicId])
            || (self::helpersReady($p) && (bool)one($p, 'SELECT id FROM maintenance_job_helpers WHERE active_mechanic=?', [$mechanicId]));
        $in = (bool)one($p, 'SELECT id FROM mechanic_attendance WHERE mechanic_id=? AND time_out IS NULL', [$mechanicId]);
        run($p, 'UPDATE mechanics SET availability=? WHERE id=?', [$busy ? 'Assigned' : ($in ? 'Available' : 'Unavailable'), $mechanicId]);
    }

    private static function setMechanic(PDO $p, array $j, int $mechanicId): void {
        try { run($p, 'UPDATE maintenance_jobs SET mechanic_id=? WHERE id=?', [$mechanicId, $j['id']]); }
        catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) throw new BusinessRuleException('This mechanic already has another active repair.', 409);
            throw $e;
        }
        self::refreshMechanic($p, $mechanicId);
        if ($j['mechanic_id'] && (int)$j['mechanic_id'] !== $mechanicId) self::refreshMechanic($p, (int)$j['mechanic_id']);
    }

    // ------------------------------------------------------------------ actions
    /** Report a truck problem found at the garage (on-premise). The truck goes Under Maintenance at once. */
    private static function report(array $u, array $d): array {
        requireRole($u, ...self::ACT);
        $vid = idField($d, 'vehicle_id');
        $problem = field($d, 'problem', 500);
        $date = dateField($d, 'reported_on');
        must($date <= date('Y-m-d'), 'The date reported cannot be in the future.');
        $priority = 'Normal'; // Legacy DB field; users no longer choose priority (#41).
        $mechanicId = optId($d, 'mechanic_id');
        $remarks = optField($d, 'remarks', 500);
        return transaction(function (PDO $p) use ($u, $vid, $problem, $date, $priority, $mechanicId, $remarks) {
            $v = one($p, 'SELECT * FROM vehicles WHERE id=? FOR UPDATE', [$vid]);
            must($v && (int)$v['active'], 'Truck not found or inactive.', 404);
            $open = FleetState::openJob($p, $vid);
            must(!$open, 'Truck ' . $v['plate_number'] . ' already has an unresolved maintenance record (' . ($open['job_ref'] ?? '') . '). Add the new findings to that record.', 409);
            $trip = FleetState::activeTrip($p, $vid);
            must(!$trip || !in_array($trip['status'], FleetState::ON_ROAD, true), 'Truck ' . $v['plate_number'] . ' is on the road (' . ($trip['trip_ref'] ?? '') . '). The dispatcher reports trouble during a trip with "Report incident".', 409);
            must(!$trip || $trip['status'] !== FleetState::RETURNING, 'Truck ' . $v['plate_number'] . ' is returning from ' . ($trip['trip_ref'] ?? '') . '. Its problems are recorded in the arrival checklist (NOT OK items open a repair record automatically).', 409);
            if ($mechanicId) self::freeMechanic($p, $mechanicId);
            $status = $mechanicId ? 'Under Inspection' : 'Reported';
            $jid = insert($p, 'maintenance_jobs', ['job_ref' => 'TMP-' . bin2hex(random_bytes(6)), 'vehicle_id' => $vid, 'source' => 'On-Premise',
                'problem' => $problem, 'reported_on' => $date, 'priority' => $priority, 'status' => 'Reported', 'remarks' => $remarks, 'created_by' => $u['id']]);
            $ref = refNumber('CM', $jid);
            run($p, 'UPDATE maintenance_jobs SET job_ref=? WHERE id=?', [$ref, $jid]);
            Files::store($p, $u, 'photos', 'maintenance', $jid, 'Inspection Photo', true, 'photos of the damaged parts or truck condition');
            self::event($p, $jid, null, 'Reported', $u['id'], $problem);
            if ($mechanicId) {
                $j = self::lockJob($p, $jid);
                self::setMechanic($p, $j, $mechanicId);
                run($p, "UPDATE maintenance_jobs SET status='Under Inspection', started_by=?, started_at=NOW() WHERE id=?", [$u['id'], $jid]);
                self::event($p, $jid, 'Reported', 'Under Inspection', $u['id'], 'mechanic assigned', $mechanicId);
            }
            $msg = "Problem reported ($ref). Truck " . $v['plate_number'] . ' is Under Maintenance and cannot be assigned to trips.';
            if ($trip) { Actions::load('Trips'); $msg .= ' ' . Trips::resourceLost($p, $u, (int)$trip['id'], 'Truck ' . $v['plate_number'] . ' has a reported problem'); }
            FleetState::reconcile($p, $vid, $u['id'], "Corrective maintenance $ref");
            audit($p, $u['id'], 'Maintenance', 'job ' . $jid, 'truck problem reported', "$ref {$v['plate_number']}: " . cut($problem, 200));
            Notify::roles($p, ['operations_head'], 'Truck under maintenance', $v['plate_number'] . " has a reported problem ($ref). It cannot be assigned until the repair is verified.", 'maintenance', 'job-open-' . $jid);
            return ['job_id' => $jid, 'job_ref' => $ref, 'message' => $msg . ($mechanicId ? '' : ' Assign a mechanic to start the inspection.')];
        });
    }

    /** Assign (or change) the mechanic. Reported -> Under Inspection. */
    private static function assignMechanic(array $u, array $d): array {
        requireRole($u, ...self::ACT);
        $id = idField($d, 'job_id'); $mechanicId = idField($d, 'mechanic_id');
        $reason = optField($d, 'reason', 255);
        return transaction(function (PDO $p) use ($u, $id, $mechanicId, $reason) {
            $j = self::lockJob($p, $id);
            must(in_array($j['status'], self::ACTIVE, true), 'This record is ' . $j['status'] . '.', 409);
            must(!($j['source'] === 'Incident' && $j['status'] === 'Reported'), 'Assess the incident first (Minor or Major); the mechanic is chosen there.', 409);
            if ((int)$j['mechanic_id'] === $mechanicId) return ['message' => 'This mechanic is already assigned.'];
            if ($j['mechanic_id']) must($reason !== null, 'Give the reason for changing the mechanic.');
            $oldMechanic = $j['mechanic_id'] ? one($p, 'SELECT full_name FROM mechanics WHERE id=?', [$j['mechanic_id']]) : null;
            $m = self::freeMechanic($p, $mechanicId, (int)$j['id']);
            self::setMechanic($p, $j, $mechanicId);
            $to = $j['status'] === 'Reported' ? 'Under Inspection' : $j['status'];
            if ($to !== $j['status']) run($p, "UPDATE maintenance_jobs SET status=?, started_by=?, started_at=NOW() WHERE id=?", [$to, $u['id'], $id]);
            self::event($p, $id, $j['status'], $to, $u['id'], ($j['mechanic_id'] ? 'mechanic changed' : 'mechanic assigned') . ($reason ? ': ' . $reason : ''), $mechanicId);
            auditWithChanges($p, $u['id'], 'Maintenance', 'job ' . $id, 'mechanic assigned', $j['job_ref'] . ' -> ' . $m['full_name'],
                ['Assigned mechanic' => $oldMechanic['full_name'] ?? null, 'Repair status' => $j['status']],
                ['Assigned mechanic' => $m['full_name'], 'Repair status' => $to]);
            return ['message' => $m['full_name'] . ' is assigned to ' . $j['job_ref'] . ($to !== $j['status'] ? ' (Under Inspection).' : '.')];
        });
    }

    /**
     * JOB_HELPER_MECHANICS_V1 — add one or more assisting mechanics to an active repair that already has a lead mechanic.
     * Same rules as the lead: active masterlist mechanic, timed in, not on another active repair (lead or assisting).
     * All-or-nothing: one refused mechanic refuses the whole request, so nothing is half-assigned.
     */
    private static function helpersAdd(array $u, array $d): array {
        requireRole($u, ...self::ACT);
        $id = idField($d, 'job_id');
        $raw = $d['mechanic_ids'] ?? [];
        must(is_array($raw) && $raw, 'Choose at least one mechanic.');
        $ids = array_values(array_unique(array_map('intval', $raw)));
        must(count($ids) <= 10 && min($ids) > 0, 'Choose valid mechanics (at most 10 at a time).');
        return transaction(function (PDO $p) use ($u, $id, $ids) {
            must(self::helpersReady($p), 'Assigning several mechanics needs the pending database update (migration 12). Ask the administrator.', 409);
            $j = self::lockJob($p, $id);
            must(in_array($j['status'], self::ACTIVE, true), 'This record is ' . $j['status'] . '.', 409);
            must((bool)$j['mechanic_id'], 'Assign the lead mechanic first.', 409);
            $names = [];
            sort($ids); // fixed lock order on the mechanic rows (no deadlock between two custodians)
            foreach ($ids as $mid) {
                must($mid !== (int)$j['mechanic_id'], 'The lead mechanic is already on this repair.', 409);
                $m = self::freeMechanic($p, $mid, (int)$j['id']);
                must((int)$j['mechanic_id'] !== $mid, $m['full_name'] . ' already leads this repair.', 409);
                // NOW() on both assigned_at and released_at (same clock as the CHECK released_at >= assigned_at).
                try { run($p, 'INSERT INTO maintenance_job_helpers (maintenance_job_id, mechanic_id, assigned_at, assigned_by) VALUES (?,?,NOW(),?)', [$id, $mid, $u['id']]); }
                catch (PDOException $e) {
                    if (str_contains($e->getMessage(), 'Duplicate')) throw new BusinessRuleException($m['full_name'] . ' already has another active repair.', 409);
                    throw $e;
                }
                self::refreshMechanic($p, $mid);
                self::event($p, $id, $j['status'], $j['status'], $u['id'], 'assisting mechanic added: ' . $m['full_name'], $mid);
                $names[] = $m['full_name'];
            }
            auditWithChanges($p, $u['id'], 'Maintenance', 'job ' . $id, 'assisting mechanics added', $j['job_ref'] . ' + ' . implode(', ', $names), [], ['Assisting mechanics added' => implode(', ', $names)]);
            return ['message' => implode(', ', $names) . (count($names) > 1 ? ' are' : ' is') . ' now assisting on ' . $j['job_ref'] . '.'];
        });
    }

    /** JOB_HELPER_MECHANICS_V1 — remove an assisting mechanic (reason required). The row is kept as history (released_at). */
    private static function helperRemove(array $u, array $d): array {
        requireRole($u, ...self::ACT);
        $id = idField($d, 'job_id'); $mid = idField($d, 'mechanic_id'); $reason = field($d, 'reason', 255);
        return transaction(function (PDO $p) use ($u, $id, $mid, $reason) {
            must(self::helpersReady($p), 'Assigning several mechanics needs the pending database update (migration 12).', 409);
            $j = self::lockJob($p, $id);
            must(in_array($j['status'], self::ACTIVE, true), 'This record is ' . $j['status'] . '.', 409);
            $h = one($p, 'SELECT h.id, mc.full_name FROM maintenance_job_helpers h JOIN mechanics mc ON mc.id=h.mechanic_id WHERE h.maintenance_job_id=? AND h.mechanic_id=? AND h.released_at IS NULL FOR UPDATE', [$id, $mid]);
            must((bool)$h, 'This mechanic is not assisting on this repair.', 404);
            run($p, 'UPDATE maintenance_job_helpers SET released_at=NOW(), released_by=?, release_reason=? WHERE id=?', [$u['id'], $reason, $h['id']]);
            self::refreshMechanic($p, $mid);
            self::event($p, $id, $j['status'], $j['status'], $u['id'], 'assisting mechanic removed: ' . $h['full_name'] . ': ' . $reason, $mid);
            auditWithChanges($p, $u['id'], 'Maintenance', 'job ' . $id, 'assisting mechanic removed', $j['job_ref'] . ' - ' . $h['full_name'] . ': ' . $reason,
                ['Assisting mechanic' => $h['full_name']], ['Assisting mechanic' => null]);
            return ['message' => $h['full_name'] . ' was removed from ' . $j['job_ref'] . '.'];
        });
    }

    /** Incident assessment: Minor (on-site repair) or Major (return, repair, redispatch). PDF #12 Scenario C step 3. */
    private static function assess(array $u, array $d): array {
        requireRole($u, ...self::ACT);
        $id = idField($d, 'job_id');
        $sev = choiceField($d, 'severity', ['Minor', 'Major']);
        $rec = field($d, 'recommendation', 500);
        $mechanicId = idField($d, 'mechanic_id');
        $exp = null; // ETA no longer requested during assessment (#43).
        if ($exp) must($exp >= date('Y-m-d'), 'Expected completion cannot be in the past.');
        return transaction(function (PDO $p) use ($u, $id, $sev, $rec, $mechanicId, $exp) {
            $j = self::lockJob($p, $id);
            must($j['source'] === 'Incident' && $j['status'] === 'Reported', 'Only a newly reported incident can be assessed.', 409);
            $inc = one($p, 'SELECT * FROM incidents WHERE id=? FOR UPDATE', [$j['incident_id']]);
            $t = one($p, 'SELECT * FROM trips WHERE id=? FOR UPDATE', [$j['trip_id']]);
            must($t && $t['status'] === 'On Hold - Incident Reported', 'The trip is not On Hold.', 409);
            $m = self::freeMechanic($p, $mechanicId);
            self::setMechanic($p, $j, $mechanicId);
            // UNSUPPORTED_CLASSIFICATION_CLEANUP: the recorded repair plan (Minor / Major) is kept; no Critical / High priority is
            // derived from it any more (no company policy). The legacy priority column and existing values are left unchanged.
            run($p, "UPDATE maintenance_jobs SET status='Under Inspection', severity=?, expected_completion=?, started_by=?, started_at=NOW(), remarks=? WHERE id=?",
                [$sev, $exp, $u['id'], $rec, $id]);
            run($p, "UPDATE incidents SET severity=?, recommendation=?, status='Under Repair', assessed_by=?, assessed_at=NOW() WHERE id=?", [$sev, $rec, $u['id'], $inc['id']]);
            self::event($p, $id, 'Reported', 'Under Inspection', $u['id'], "assessed $sev: $rec", $mechanicId);
            audit($p, $u['id'], 'Maintenance', 'job ' . $id, 'incident assessed', $inc['incident_ref'] . ': ' . $sev . '; mechanic ' . $m['full_name']);
            Notify::resolve($p, 'incident-assess-' . $inc['id']);
            Actions::load('Trips');
            if ($sev === 'Major') {
                // INCIDENT_CLEARANCE_V1: Major damage is Motorpool's Not Fit to Continue decision, recorded with the assessment.
                run($p, "UPDATE incidents SET clearance='Not Fit to Continue', clearance_findings=?, cleared_by=?, cleared_at=NOW() WHERE id=?", [$rec, $u['id'], $inc['id']]);
                Trips::setStatus($p, $u['id'], $t, 'For Reschedule', 'major damage: truck returned for repair', $inc['incident_ref'] . ($exp ? '; expected back ' . $exp : ''));
                $now = FleetState::reconcile($p, (int)$j['vehicle_id'], $u['id'], 'Major damage ' . $inc['incident_ref']);
                Notify::tripRoles($p, $t, [$t['dispatcher_role'], 'operations_head'], 'Major damage', $t['trip_ref'] . ' is For Reschedule. Truck is ' . $now . ($exp ? ' (expected back ' . $exp . ')' : '') . '.', 'trips');
                return ['message' => 'Assessed as MAJOR. ' . $m['full_name'] . ' is assigned. Truck is ' . $now . ' and the trip is For Reschedule.'];
            }
            Trips::event($p, $u['id'], (int)$t['id'], 'minor damage: mechanic sent to the location', $t['status'], $t['status'], $inc['incident_ref'] . '; ' . $m['full_name']);
            Notify::tripRoles($p, $t, [$t['dispatcher_role'], 'operations_head'], 'Minor damage', $t['trip_ref'] . ': ' . $m['full_name'] . ' sent for on-site repair.', 'trips');
            return ['message' => 'Assessed as MINOR. ' . $m['full_name'] . ' is sent to the location. Record the findings, the repair, then verify the truck.'];
        });
    }

    /** Inspection findings + diagnosis. Under Inspection -> Under Repair. */
    private static function inspect(array $u, array $d): array {
        requireRole($u, ...self::ACT);
        $id = idField($d, 'job_id');
        $findings = field($d, 'findings', 2000);
        $diagnosis = field($d, 'diagnosis', 2000);
        $exp = null; // ETA no longer requested during inspection (#43).
        if ($exp) must($exp >= date('Y-m-d'), 'Expected completion cannot be in the past.');
        return transaction(function (PDO $p) use ($u, $id, $findings, $diagnosis, $exp) {
            $j = self::lockJob($p, $id);
            must($j['status'] === 'Under Inspection', 'Findings are recorded while the record is Under Inspection (now: ' . $j['status'] . ').', 409);
            must((bool)$j['mechanic_id'], 'Assign a mechanic first.', 409);
            run($p, "UPDATE maintenance_jobs SET status='Under Repair', findings=?, diagnosis=?, expected_completion=COALESCE(?, expected_completion) WHERE id=?", [$findings, $diagnosis, $exp, $id]);
            Files::store($p, $u, 'photos', 'maintenance', $id, 'Inspection Photo');
            self::event($p, $id, 'Under Inspection', 'Under Repair', $u['id'], 'diagnosis: ' . $diagnosis, (int)$j['mechanic_id']);
            audit($p, $u['id'], 'Maintenance', 'job ' . $id, 'inspection recorded', $j['job_ref'] . ': ' . cut($diagnosis, 200));
            return ['message' => 'Findings and diagnosis saved. ' . $j['job_ref'] . ' is Under Repair.'];
        });
    }

    /** Repair costs (labor, outside service ...). Recorded as maintenance expenses. */
    private static function addCost(array $u, array $d): array {
        requireRole($u, ...self::ACT);
        $id = idField($d, 'job_id');
        $row = ['cost_type' => choiceField($d, 'cost_type', self::COST_TYPES), 'description' => field($d, 'description', 255),
                'provider' => optField($d, 'provider', 150), 'amount' => moneyField($d, 'amount'), 'incurred_on' => dateField($d, 'incurred_on')];
        must($row['incurred_on'] <= date('Y-m-d'), 'The date cannot be in the future.');
        return transaction(function (PDO $p) use ($u, $id, $row) {
            $j = self::lockJob($p, $id);
            must(in_array($j['status'], ['Under Inspection', 'Under Repair'], true), 'Costs are added while the record is Under Inspection or Under Repair.', 409);
            $cid = insert($p, 'maintenance_costs', ['maintenance_job_id' => $id, 'recorded_by' => $u['id']] + $row);
            Money::expense($p, $u, 'Maintenance', $row['amount'], $row['incurred_on'], 'Maintenance', $cid, null, (int)$j['vehicle_id'], $row['provider'], $j['job_ref'], $row['cost_type'] . ': ' . $row['description']);
            audit($p, $u['id'], 'Maintenance', 'job ' . $id, 'repair cost added', $j['job_ref'] . ' ' . $row['cost_type'] . ' PHP ' . $row['amount']);
            return ['message' => 'Cost recorded.'];
        });
    }

    /** Repair done: corrective action + completion date + evidence. The mechanic becomes free; the truck waits for verification. */
    private static function complete(array $u, array $d): array {
        requireRole($u, ...self::ACT);
        $id = idField($d, 'job_id');
        $performed = field($d, 'repair_performed', 2000);
        $done = dateField($d, 'completed_on');
        must($done <= date('Y-m-d'), 'Completion date cannot be in the future.');
        $remarks = optField($d, 'remarks', 500);
        return transaction(function (PDO $p) use ($u, $id, $performed, $done, $remarks) {
            $j = self::lockJob($p, $id);
            must($j['status'] === 'Under Repair', $j['status'] === 'Under Inspection' ? 'Record the inspection findings and diagnosis first.' : 'Only records Under Repair can be completed (now: ' . $j['status'] . ').', 409);
            must($done >= (string)$j['reported_on'], 'Completion date is before the date reported.');
            Files::store($p, $u, 'evidence', 'maintenance', $id, 'Repair Evidence');
            must(Files::count($p, 'maintenance', $id, 'Repair Evidence') > 0, 'Attach at least one photo or document as evidence of the repair.', 422);
            run($p, "UPDATE maintenance_jobs SET status='Completed', repair_performed=?, completed_on=?, remarks=COALESCE(?, remarks), completed_by=?, completed_at=NOW() WHERE id=?",
                [$performed, $done, $remarks, $u['id'], $id]);
            self::refreshMechanic($p, $j['mechanic_id'] ? (int)$j['mechanic_id'] : null);
            self::releaseHelpers($p, $id, (int)$u['id'], 'repair completed');
            self::event($p, $id, 'Under Repair', 'Completed', $u['id'], 'corrective action: ' . $performed, $j['mechanic_id'] ? (int)$j['mechanic_id'] : null);
            audit($p, $u['id'], 'Maintenance', 'job ' . $id, 'repair completed', $j['job_ref'] . ': ' . cut($performed, 200));
            $v = one($p, 'SELECT plate_number FROM vehicles WHERE id=?', [$j['vehicle_id']]);
            return ['message' => 'Repair completed. Truck ' . $v['plate_number'] . ' stays out of service until you verify it (Verify & return to service).'];
        });
    }

    /**
     * Operational verification after the repair. Passed -> the truck returns to service
     * (Available, or Assigned if a trip is waiting for it). Failed -> back to Under Repair.
     */
    private static function verify(array $u, array $d): array {
        requireRole($u, ...self::ACT);
        $id = idField($d, 'job_id');
        $result = choiceField($d, 'result', ['Passed', 'Failed']);
        $notes = field($d, 'verification_notes', 500);
        $mechanicId = optId($d, 'mechanic_id');
        return transaction(function (PDO $p) use ($u, $id, $result, $notes, $mechanicId) {
            $j = self::lockJob($p, $id);
            must($j['status'] === 'Completed' && !$j['verified_at'], 'Only completed repairs waiting for verification can be verified.', 409);
            $v = one($p, 'SELECT plate_number FROM vehicles WHERE id=?', [$j['vehicle_id']]);
            if ($result === 'Failed') {
                $mid = $mechanicId ?: (int)$j['mechanic_id'];
                must($mid > 0, 'Choose the mechanic who will continue the repair.');
                self::freeMechanic($p, $mid, (int)$j['id']);
                run($p, "UPDATE maintenance_jobs SET status='Under Repair', completed_on=NULL, completed_by=NULL, completed_at=NULL WHERE id=?", [$id]);
                self::setMechanic($p, self::lockJob($p, $id), $mid);
                self::event($p, $id, 'Completed', 'Under Repair', $u['id'], 'verification failed: ' . $notes, $mid);
                audit($p, $u['id'], 'Maintenance', 'job ' . $id, 'verification failed', $j['job_ref'] . ': ' . cut($notes, 200));
                return ['message' => 'Verification failed. ' . $j['job_ref'] . ' is back Under Repair; truck ' . $v['plate_number'] . ' stays out of service.'];
            }
            run($p, 'UPDATE maintenance_jobs SET verified_at=NOW(), verified_by=?, verification_notes=? WHERE id=?', [$u['id'], $notes, $id]);
            self::event($p, $id, 'Completed', 'Completed', $u['id'], 'verified, returned to service: ' . $notes);
            $now = FleetState::reconcile($p, (int)$j['vehicle_id'], $u['id'], 'Repair verified ' . $j['job_ref']);
            audit($p, $u['id'], 'Maintenance', 'job ' . $id, 'truck returned to service', $j['job_ref'] . ' ' . $v['plate_number'] . ': ' . cut($notes, 200));
            Notify::resolve($p, 'job-open-' . $id);
            $msg = 'Verified. Truck ' . $v['plate_number'] . ' is back in service (' . $now . ').';
            if ($j['source'] === 'Incident' && $j['severity'] === 'Minor') $msg = 'Repair verified. Truck ' . $v['plate_number'] . ' is ' . $now . ' and stays with its trip.';
            Actions::load('Trips');
            if ($j['source'] === 'Incident') {
                $inc = one($p, 'SELECT * FROM incidents WHERE id=? FOR UPDATE', [$j['incident_id']]);
                $t = one($p, 'SELECT * FROM trips WHERE id=?', [$j['trip_id']]);
                if ($j['severity'] === 'Minor' && $t['status'] === 'For Reschedule') {
                    // Operations moved the trip to For Reschedule after Not Fit: the verified repair closes the incident like a Major one.
                    run($p, "UPDATE incidents SET status='Resolved', resolved_at=NOW() WHERE id=?", [$inc['id']]);
                    Trips::event($p, $u['id'], (int)$t['id'], 'truck repaired after not fit to continue', $t['status'], $t['status'], $inc['incident_ref']);
                    Notify::tripRoles($p, $t, [$t['dispatcher_role'], 'operations_head'], 'Truck available again', $v['plate_number'] . ' is repaired. Reschedule ' . $t['trip_ref'] . '.', 'trips', 'trip-reschedule-' . $inc['id']);
                    $msg .= ' The dispatcher can now reschedule the trip.';
                } elseif ($j['severity'] === 'Minor') {
                    // INCIDENT_CLEARANCE_V1: a verified repair is not by itself a clearance; Motorpool records Fit / Not Fit next.
                    Trips::event($p, $u['id'], (int)$t['id'], 'on-site repair verified; awaiting Motorpool clearance', $t['status'], $t['status'], $inc['incident_ref']);
                    $msg .= ' Record the vehicle clearance (Fit / Not Fit to Continue) for ' . $inc['incident_ref'] . ' so the dispatcher can act on it.';
                } else {
                    run($p, "UPDATE incidents SET status='Resolved', resolved_at=NOW() WHERE id=?", [$inc['id']]);
                    Trips::event($p, $u['id'], (int)$t['id'], 'truck repaired after major damage', $t['status'], $t['status'], $inc['incident_ref']);
                    Notify::tripRoles($p, $t, [$t['dispatcher_role'], 'operations_head'], 'Truck available again', $v['plate_number'] . ' is repaired. Reschedule ' . $t['trip_ref'] . '.', 'trips', 'trip-reschedule-' . $inc['id']);
                    $msg .= ' The dispatcher can now reschedule the trip.';
                }
            } else {
                Notify::roles($p, ['operations_head', 'car_carrier_dispatcher', 'container_wingvan_dispatcher'], 'Truck available again', $v['plate_number'] . ' was repaired, verified and is ' . $now . '.', 'trucks');
                if ($auto = Trips::assignWaiting($p, $u)) $msg .= ' ' . $auto;
            }
            return ['message' => $msg];
        });
    }

    /**
     * INCIDENT_CLEARANCE_V1: Motorpool's vehicle clearance for a trip incident. Minor / Major (severity) stays the damage
     * classification; this is the separate technical decision. Fit to Continue (Minor only) needs the on-site repair
     * record completed and verified; it lets the trip's Dispatcher resume. Not Fit to Continue keeps the trip On Hold.
     * Only the trip's CURRENT incident, for the trip's current attempt and truck, can be cleared; a decision is never
     * overwritten except Not Fit -> Fit after a verified repair (both stay in the trip history and audit).
     */
    private static function clearance(array $u, array $d): array {
        requireRole($u, ...self::ACT);
        $id = idField($d, 'job_id');
        $decision = choiceField($d, 'decision', ['Fit to Continue', 'Not Fit to Continue']);
        $findings = field($d, 'findings', 1000);
        $inspectionOnly = $decision === 'Fit to Continue' && !empty($d['inspection_only']);
        return transaction(function (PDO $p) use ($u, $id, $decision, $findings, $inspectionOnly) {
            $j = self::lockJob($p, $id);
            must($j['source'] === 'Incident' && $j['incident_id'] && $j['trip_id'], 'Clearance is recorded on a trip incident repair record.', 409);
            $inc = one($p, 'SELECT * FROM incidents WHERE id=? FOR UPDATE', [$j['incident_id']]);
            $t = one($p, 'SELECT * FROM trips WHERE id=? FOR UPDATE', [$j['trip_id']]);
            must($inc && $t && (int)$inc['trip_id'] === (int)$t['id'], 'The incident does not belong to this trip.', 409);
            $latest = one($p, 'SELECT id FROM incidents WHERE trip_id=? ORDER BY id DESC LIMIT 1', [$t['id']]);
            must((int)$latest['id'] === (int)$inc['id'] && (int)$inc['attempt_number'] === (int)$t['attempt_number'],
                'This is an earlier incident of the trip; record the clearance on the current incident.', 409);
            must((int)$inc['vehicle_id'] === (int)$j['vehicle_id'] && (int)$inc['vehicle_id'] === (int)$t['vehicle_id'], "The incident truck is not the trip's current truck.", 409);
            must($t['status'] === 'On Hold - Incident Reported', 'The trip is not On Hold for this incident (status: ' . $t['status'] . ').', 409);
            must($inc['severity'] !== null, 'Assess the incident (Minor or Major) first.', 409);
            must($inc['severity'] === 'Minor', 'Major damage is already recorded as Not Fit to Continue; the trip follows For Reschedule.', 409);
            must($inc['clearance'] !== 'Fit to Continue', 'Fit to Continue is already recorded for ' . $inc['incident_ref'] . '.', 409);
            must($inc['clearance'] !== $decision, $decision . ' is already recorded for ' . $inc['incident_ref'] . '.', 409);
            if ($inspectionOnly) {
                // No repair was needed: the inspection itself is recorded; nothing is invented (no repair text, parts, costs or labor).
                must(in_array($j['status'], ['Under Inspection', 'Under Repair'], true), 'Inspection-only clearance is for an open, inspected record (now: ' . $j['status'] . ').', 409);
                must(!one($p, 'SELECT 1 AS x FROM maintenance_costs WHERE maintenance_job_id=?', [$id]), 'Costs were recorded, so a repair took place: complete and verify the repair instead.', 409);
                must(!one($p, "SELECT 1 AS x FROM part_requests WHERE maintenance_job_id=? AND status<>'Cancelled'", [$id]), 'Parts were requested for this record: complete and verify the repair instead.', 409);
            } elseif ($decision === 'Fit to Continue') {
                must($j['status'] === 'Completed' && $j['verified_at'] !== null,
                    'Complete and verify the on-site repair record ' . $j['job_ref'] . ' first, or record an inspection-only clearance when no repair was needed.', 409);
            }
            $before = $inc['clearance'];
            if ($inspectionOnly) {
                run($p, "UPDATE maintenance_jobs SET status='Completed', findings=COALESCE(findings, ?), repair_performed=NULL, completed_on=CURRENT_DATE(), completed_by=?, completed_at=NOW(),
                         verified_at=NOW(), verified_by=?, verification_notes=? WHERE id=?",
                    [$findings, $u['id'], $u['id'], cut('Inspection only - no repair performed: ' . $findings, 500), $id]);
                self::refreshMechanic($p, $j['mechanic_id'] ? (int)$j['mechanic_id'] : null);
                self::releaseHelpers($p, $id, (int)$u['id'], 'inspection only, no repair needed');
                self::event($p, $id, $j['status'], 'Completed', $u['id'], 'inspection only, no repair performed: ' . cut($findings, 400), $j['mechanic_id'] ? (int)$j['mechanic_id'] : null);
            }
            run($p, 'UPDATE incidents SET clearance=?, clearance_findings=?, cleared_by=?, cleared_at=NOW()' . ($decision === 'Fit to Continue' ? ", status='Repaired - Cleared to Continue'" : '') . ' WHERE id=?',
                [$decision, $findings, $u['id'], $inc['id']]);
            Files::store($p, $u, 'evidence', 'incident', (int)$inc['id'], $inspectionOnly ? 'Incident Photo' : 'Repair Evidence');
            $truckNow = FleetState::reconcile($p, (int)$t['vehicle_id'], $u['id'], 'Vehicle clearance ' . $inc['incident_ref'] . ': ' . $decision); // Not Fit -> Under Maintenance
            Actions::load('Trips');
            Trips::event($p, $u['id'], (int)$t['id'], 'vehicle clearance: ' . $decision, $t['status'], $t['status'], $inc['incident_ref'] . ': ' . cut($findings, 400));
            auditWithChanges($p, $u['id'], 'Maintenance', 'incident ' . $inc['id'], 'vehicle clearance recorded', $inc['incident_ref'] . ' (' . $t['trip_ref'] . '): ' . $decision,
                ['Clearance' => $before], ['Clearance' => $decision]);
            if ($decision === 'Fit to Continue') {
                Notify::tripRoles($p, $t, [$t['dispatcher_role']], 'Cleared to continue', $t['trip_ref'] . ': Motorpool recorded Fit to Continue. Resume the trip.', 'trips', 'trip-resume-' . $inc['id']);
                return ['message' => 'Fit to Continue recorded for ' . $inc['incident_ref'] . ($inspectionOnly ? ' (inspection only, no repair performed)' : '') . '. The dispatcher can resume ' . $t['trip_ref'] . '.'];
            }
            Notify::tripRoles($p, $t, [$t['dispatcher_role'], 'operations_head'], 'Not fit to continue', $t['trip_ref'] . ': Motorpool recorded Not Fit to Continue. The trip stays On Hold.', 'trips');
            return ['message' => 'Not Fit to Continue recorded for ' . $inc['incident_ref'] . '. The trip stays On Hold; continue the repair in ' . $j['job_ref'] . '.'];
        });
    }

    /** Cancel a garage report made by mistake. Incident repairs cannot be cancelled. */
    private static function cancel(array $u, array $d): array {
        requireRole($u, ...self::ACT);
        $id = idField($d, 'job_id'); $reason = field($d, 'reason', 255);
        return transaction(function (PDO $p) use ($u, $id, $reason) {
            $j = self::lockJob($p, $id);
            must($j['source'] !== 'Incident', 'Incident repairs cannot be cancelled; complete and verify the repair instead.', 409);
            must(in_array($j['status'], self::ACTIVE, true), 'Already ' . $j['status'] . '.', 409);
            must(!one($p, "SELECT r.id FROM part_requests r JOIN part_request_lines l ON l.part_request_id=r.id WHERE r.maintenance_job_id=? AND l.released_qty>0", [$id]), 'Parts were already released for this record; complete it instead.', 409);
            run($p, "UPDATE maintenance_jobs SET status='Cancelled', remarks=? WHERE id=?", [cut('Cancelled: ' . $reason, 500), $id]);
            run($p, "UPDATE part_requests SET status='Cancelled' WHERE maintenance_job_id=? AND status='Requested'", [$id]);
            self::refreshMechanic($p, $j['mechanic_id'] ? (int)$j['mechanic_id'] : null);
            self::releaseHelpers($p, $id, (int)$u['id'], 'record cancelled');
            self::event($p, $id, $j['status'], 'Cancelled', $u['id'], $reason);
            $now = FleetState::reconcile($p, (int)$j['vehicle_id'], $u['id'], 'Maintenance cancelled ' . $j['job_ref']);
            audit($p, $u['id'], 'Maintenance', 'job ' . $id, 'maintenance cancelled', $j['job_ref'] . ': ' . $reason);
            Notify::resolve($p, 'job-open-' . $id);
            Actions::load('Trips'); Trips::assignWaiting($p, $u);
            return ['message' => 'Record cancelled. Truck is ' . $now . '.'];
        });
    }

    private static function details(array $u, array $d): array {
        must(Access::canOpen($u, 'maintenance'), 'Not allowed.', 403);
        $id = idField($d, 'job_id'); $p = db();
        $j = one($p, 'SELECT m.*, v.plate_number, v.truck_number, v.truck_type, v.status AS truck_status, t.trip_ref, mc.full_name AS mechanic, mc.mechanic_code, mc.contact_number AS mechanic_contact,
                su.full_name AS started_by_name, cu.full_name AS completed_by_name, vu.full_name AS verified_by_name, ru.full_name AS reported_by_name
            FROM maintenance_jobs m JOIN vehicles v ON v.id=m.vehicle_id LEFT JOIN trips t ON t.id=m.trip_id LEFT JOIN mechanics mc ON mc.id=m.mechanic_id
            LEFT JOIN users su ON su.id=m.started_by LEFT JOIN users cu ON cu.id=m.completed_by LEFT JOIN users vu ON vu.id=m.verified_by LEFT JOIN users ru ON ru.id=m.created_by WHERE m.id=?', [$id]);
        must((bool)$j, 'Record not found.', 404);
        $inc = $j['incident_id'] ? one($p, 'SELECT i.*, ru.full_name AS reported_by_name, au.full_name AS assessed_by_name, cu.full_name AS cleared_by_name, su.full_name AS resumed_by_name FROM incidents i JOIN users ru ON ru.id=i.reported_by LEFT JOIN users au ON au.id=i.assessed_by LEFT JOIN users cu ON cu.id=i.cleared_by LEFT JOIN users su ON su.id=i.resumed_by WHERE i.id=?', [$j['incident_id']]) : null;
        $docs = Files::of($p, 'maintenance', $id);
        if ($inc) foreach (Files::of($p, 'incident', (int)$inc['id']) as $doc) $docs[] = $doc;
        // Repair opened by an arrival checklist: show the checklist findings and its photos.
        $arrival = one($p, "SELECT ins.*, us.full_name AS actor FROM inspections ins JOIN users us ON us.id=ins.actor_id WHERE ins.maintenance_job_id=? AND ins.kind='Arrival' ORDER BY ins.id DESC LIMIT 1", [$id]);
        if ($arrival) foreach (Files::of($p, 'trip', (int)$arrival['trip_id']) as $doc) if ($doc['category'] === 'Arrival Inspection Photo') $docs[] = $doc;
        return [
            'job' => $j, 'incident' => $inc, 'arrival' => $arrival, 'documents' => $docs,
            // JOB_HELPER_MECHANICS_V1: assisting mechanics, active and past (who assigned / released them and why).
            'helpers' => self::helpersReady($p) ? many($p, 'SELECT h.mechanic_id, h.assigned_at, h.released_at, h.release_reason, mc.full_name, mc.mechanic_code, au.full_name AS assigned_by_name, ru.full_name AS released_by_name
                FROM maintenance_job_helpers h JOIN mechanics mc ON mc.id=h.mechanic_id JOIN users au ON au.id=h.assigned_by LEFT JOIN users ru ON ru.id=h.released_by
                WHERE h.maintenance_job_id=? ORDER BY h.released_at IS NOT NULL, h.id', [$id]) : [],
            'helpersReady' => self::helpersReady($p),
            'events' => many($p, 'SELECT e.*, u.full_name AS actor, mc.full_name AS mechanic FROM maintenance_events e LEFT JOIN users u ON u.id=e.actor_id LEFT JOIN mechanics mc ON mc.id=e.mechanic_id WHERE e.maintenance_job_id=? ORDER BY e.id', [$id]),
            'history' => many($p, 'SELECT m.id, m.job_ref, m.source, m.problem, m.status, m.reported_on, m.completed_on, mc.full_name AS mechanic FROM maintenance_jobs m LEFT JOIN mechanics mc ON mc.id=m.mechanic_id WHERE m.vehicle_id=? ORDER BY m.id DESC LIMIT 30', [$j['vehicle_id']]),
            'costs' => many($p, 'SELECT c.*, u.full_name AS recorded_by_name FROM maintenance_costs c JOIN users u ON u.id=c.recorded_by WHERE c.maintenance_job_id=? ORDER BY c.id', [$id]),
            'parts' => many($p, 'SELECT r.request_ref, r.status, pt.part_number, pt.description, l.quantity, l.released_qty, l.unit_cost FROM part_requests r
                JOIN part_request_lines l ON l.part_request_id=r.id JOIN parts pt ON pt.id=l.part_id WHERE r.maintenance_job_id=? ORDER BY r.id, l.id', [$id]),
        ];
    }
}
