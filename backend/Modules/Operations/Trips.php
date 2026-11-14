<?php
/*
 * =====================================================================
 *  backend/Modules/Operations/Trips.php  —  TRIP MANAGEMENT AND DISPATCH
 * =====================================================================
 *  Objectives 1.3, 1.5, 1.6 and 4.4. Workflow = PDF #12.
 *
 *  V4.3 intake and assignment
 *    Operations Head creates scheduled trips from approved routes for a group/shift.
 *    Trips begin Awaiting Resources. A Dispatcher selects an eligible Truck;
 *    the server derives its fixed Driver and records the actual trip Helper.
 *    Historical Dispatcher requests and decisions remain read-only.
 *  DISPATCH AND COMPLETION
 *    Dispatcher fund request -> Ops Head approves -> Finance releases (Funds.php)
 *    Dispatcher pre-dispatch checklist (all OK) -> "Ready for Dispatch"
 *    Confirm Dispatch is allowed only when fund = Released AND trip = Ready for Dispatch
 *    -> "Dispatched" (billing draft generated) -> incident / completion actions
 *    -> Complete Trip (completion date/time + waybill + acknowledgement receipt)
 *    -> "Completed" (billing draft moves to For Billing; personnel remain held until arrival)
 *    -> truck drives back: it stays held ("On Trip") until the dispatcher submits the
 *       ARRIVAL CHECKLIST (PDF #11 section G). All OK -> truck Available.
 *       NOT OK -> corrective maintenance record, truck Under Maintenance.
 *  SCENARIO C (breakdown)
 *    Dispatcher incident report -> "On Hold - Incident Reported" -> Maintenance assesses
 *      Minor: repaired on site -> Resume Trip -> "In Transit" (not a second attempt)
 *      Major: truck Under Maintenance, trip "For Reschedule" -> repair -> Reschedule
 *             -> checklist again -> Confirm Redispatch = Second Attempt
 *
 *  Every status change is checked here on the server (allowed "from" statuses),
 *  so an invalid jump (e.g. Completed -> Dispatched) is impossible even by API.
 * =====================================================================
 */
declare(strict_types=1);
core('FleetState');
core('Settings');
core('Notify');
core('Files');
core('BillingDraft');
core('RouteDistance');
core('CrewAttendance');
core('DispatcherResources');
core('DispatcherPlanning');

final class Trips {
    public const STATUSES = ['Pending Review', 'Awaiting Resources', 'Pre-Assigned', 'Ready for Dispatch', 'Dispatched',
                             'On Hold - Incident Reported', 'In Transit', 'For Reschedule', 'Completed', 'Cancelled', 'Rejected'];
    public const CLOSED = ['Completed', 'Cancelled', 'Rejected'];
    public const BEFORE_DISPATCH = ['Pending Review', 'Awaiting Resources', 'Pre-Assigned', 'Ready for Dispatch', 'For Reschedule'];
    public const UPDATE_TYPES = ['Progress', 'Loading', 'Unloading', 'Delivery', 'Delay', 'Other'];
    public const DELAY_CAUSES = ['Client', 'Pending Documents', 'Traffic', 'Weather', 'Operational', 'Other'];
    public const INCIDENT_TYPES = ['Breakdown', 'Flat Tire', 'Accident', 'Cargo Damage', 'Other'];
    public const DOC_REQUEST = 'Trip Request Document';
    public const DOC_WAYBILL = 'Waybill';
    public const DOC_AR = 'Acknowledgement Receipt';
    public const DOC_DR = 'Delivery Receipt';
    public const DOC_ARRIVAL_PHOTO = 'Arrival Inspection Photo';
    // CHECKLIST_44_DIESEL_PO_SOA_ATTACHMENTS_V1: optional attachments only (no approval, purchasing or reconciliation flow).
    public const DOC_DIESEL_PO = 'Diesel PO';
    public const DOC_SUPPLIER_SOA = 'Supplier SOA';
    /** Trip statuses where the dispatcher may attach the Diesel PO (assigned, on the road, or just completed). */
    private const DIESEL_PO_STATUSES = ['Pre-Assigned', 'Ready for Dispatch', 'Dispatched', 'On Hold - Incident Reported', 'In Transit', 'For Reschedule', 'Completed'];
    public const FUEL_LEVELS = ['Full', '3/4', '1/2', '1/4', 'Low'];

    // =================================================================
    // READ
    // =================================================================
    public static function read(array $u, string $page, array $q): array {
        if ($page === 'dispatcher-monitoring') { requireRole($u, 'operations_head'); core('DispatcherMonitoring'); return DispatcherMonitoring::read($u, $q); }
        if (($q['view'] ?? '') === 'approvals') {
            requireRole($u, 'operations_head');
            return self::approvalWorkspace($q);
        }
        if (($q['view'] ?? '') === 'requests') {
            throw new BusinessRuleException('Dispatcher Trip Requests are retired. Historical records remain available to Operations Head.',403);
        }
        if (($q['view'] ?? '') === 'request-result') {
            throw new BusinessRuleException('Dispatcher request-result lookup is retired.',403);

        }
        $p = db();
        if(roleIsDispatcher($u['role'])){core('DispatcherTurnover');must(DispatcherTurnover::ready($p),'Dispatcher account responsibility requires the verified additive turnover migration. Ask Operations Head to assign initial responsibility after installation.',409);}
        [$scope, $scopeArgs] = self::scope($u);
        [$where, $args] = self::filters($q);
        $closed = "'" . implode("','", self::CLOSED) . "'";
        $legacyHistory = hasRole($u,'operations_head') && !empty($q['legacy']);
        $data = readSections([
            // Open trips + completed trips whose truck is still returning (arrival checklist pending).
            'active' => fn() => many($p, self::select() . " WHERE (t.status NOT IN ($closed) AND t.status<>'Pending Review'
                    OR EXISTS (SELECT 1 FROM trip_assignments aa WHERE aa.trip_id=t.id AND aa.resource_type='vehicle')) $scope ORDER BY t.scheduled_at, t.id", $scopeArgs),
            'history' => fn() => many($p, self::select() . " WHERE ".($legacyHistory?"t.status='Pending Review'":"t.status IN ($closed)")." $scope $where ORDER BY t.scheduled_at DESC, t.id DESC LIMIT 1000", array_merge($scopeArgs, $args)),
            'incidents' => fn() => many($p, "SELECT i.*, t.trip_ref, t.status AS trip_status, v.plate_number, c.company_name AS client, ru.full_name AS reported_by_name,
                    (SELECT au.full_name FROM users au WHERE au.id=i.assessed_by) AS assessed_by_name, m.job_ref, m.status AS job_status
                FROM incidents i JOIN trips t ON t.id=i.trip_id JOIN vehicles v ON v.id=i.vehicle_id JOIN clients c ON c.id=t.client_id
                JOIN users ru ON ru.id=i.reported_by LEFT JOIN maintenance_jobs m ON m.incident_id=i.id
                WHERE 1=1 $scope ORDER BY i.id DESC LIMIT 300", $scopeArgs),
        ]);
        $reference=null;
        if(roleIsDispatcher($u['role'])){require_once __DIR__.'/Fleet.php';$reference=Fleet::read($u,'trucks',[]);}
        if (self::canCreate($u) || hasRole($u, 'operations_head', 'admin')) {
            $data = mergeSections($data, [
                'clients' => function()use($p,$u){$types=roleIsDispatcher($u['role'])?DispatcherScope::types($u):[];$condition=$types?' AND EXISTS (SELECT 1 FROM client_routes sr WHERE sr.client_id=c.id AND sr.status=\'Approved\' AND sr.truck_type IN ('.implode(',',array_fill(0,count($types),'?')).'))':'';return many($p, "SELECT c.id, c.company_name, (SELECT GROUP_CONCAT(r.truck_type SEPARATOR '|') FROM client_routes r WHERE r.client_id=c.id AND r.status='Approved') AS rate_types
                    FROM clients c WHERE c.status='Active' $condition ORDER BY c.company_name",$types);},
                'trucks' => fn() => $reference!==null?($reference['trucks']??[]):many($p, "SELECT v.id, v.plate_number, v.truck_number, v.truck_type, v.status, v.assigned_driver_id, v.assigned_helper_id,
                    (SELECT t.trip_ref FROM trip_assignments a JOIN trips t ON t.id=a.trip_id WHERE a.resource_type='vehicle' AND a.resource_id=v.id) AS on_trip
                    FROM vehicles v WHERE v.active=1 ORDER BY v.truck_type, v.plate_number"),
                'crew' => fn() => $reference!==null?($reference['personnel']??[]):many($p, "SELECT pe.id, pe.full_name, pe.person_type, pe.availability, pe.license_expiry,
                    (SELECT t.trip_ref FROM trip_assignments a JOIN trips t ON t.id=a.trip_id WHERE a.resource_type='person' AND a.resource_id=pe.id) AS on_trip
                    FROM personnel pe WHERE pe.active=1 ORDER BY pe.full_name"),
                'places' => fn() => roleIsDispatcher($u['role'])?[]:array_column(many($p, "SELECT x FROM (SELECT pickup_point AS x FROM trips UNION SELECT delivery_point FROM trips) z WHERE x<>'' ORDER BY x LIMIT 500"), 'x'),
                'consignees' => fn() => roleIsDispatcher($u['role'])?[]:array_column(many($p, "SELECT DISTINCT consignee FROM trips WHERE consignee IS NOT NULL ORDER BY consignee LIMIT 300"), 'consignee'),
            ]);
        }
        require_once __DIR__ . '/Inspections/Checklist.php';
        $data['checklist'] = Checklist::sections();
        $data['fundCategories'] = many($p, 'SELECT name, amount FROM fund_categories WHERE active=1 ORDER BY sort_order, name');
        $data['updateTypes'] = self::UPDATE_TYPES;
        $data['delayCauses'] = self::DELAY_CAUSES;
        $data['incidentTypes'] = self::INCIDENT_TYPES;
        $data['statuses'] = self::STATUSES;
        $data['fuelLevels'] = self::FUEL_LEVELS;
        $data['dispatcherAccounts'] = hasRole($u,'operations_head') ? many($p,"SELECT id,full_name,role,shift FROM users WHERE active=1 AND role IN ('car_carrier_dispatcher','container_wingvan_dispatcher') AND shift IN ('Day','Night') ORDER BY full_name,id") : [];
        $data['checklistMinMinutes'] = (int)Settings::value('inspection_min_minutes');
        $data['unitsPerTrip'] = Settings::value('units_per_trip');
        $data['filterOptions'] = roleIsDispatcher($u['role'])?['clients'=>$data['clients']??[],'trucks'=>$reference['trucks']??[],'drivers'=>$reference['personnel']??[],'dispatchers'=>[]]:readSections([
            'clients' => fn() => many($p, 'SELECT id, company_name FROM clients ORDER BY company_name'),
            'trucks' => fn() => many($p, 'SELECT id, plate_number FROM vehicles ORDER BY plate_number'),
            'drivers' => fn() => many($p, "SELECT id, full_name, person_type FROM personnel ORDER BY full_name"),
            'dispatchers' => fn() => many($p, "SELECT id, full_name FROM users WHERE role IN ('car_carrier_dispatcher','container_wingvan_dispatcher') ORDER BY full_name"),
        ]);
        if (hasRole($u, 'operations_head')) {
            core('DispatcherMonitoring');
            $data['dispatcherGroups'] = DispatcherPlanning::groups();
            $data['opsSummary'] = DispatcherMonitoring::operationalCounts($p);
        }
        $data['can'] = ['create' => self::canCreate($u), 'review' => false,
                        'dispatch' => roleIsDispatcher($u['role']), 'assign'=>roleIsDispatcher($u['role']), 'manage' => hasRole($u, 'operations_head')];
        return $data;
    }

    private static function canCreate(array $u): bool { return hasRole($u, 'operations_head'); }

    /** Dashboard read projection, using the same team/creator scope as the trip workspace. */
    public static function dispatcherOverview(array $u): array {
        must(roleIsDispatcher($u['role']), 'Only dispatchers can open this overview.', 403);
        $p = db(); [$scope, $args] = self::scope($u);
        return readSections([
            'active' => fn() => many($p, self::select() . " WHERE (t.status NOT IN ('Completed','Cancelled','Rejected')
                OR EXISTS (SELECT 1 FROM trip_assignments a WHERE a.trip_id=t.id AND a.resource_type='vehicle' AND t.status='Completed')) $scope ORDER BY t.scheduled_at,t.id", $args),
            'incidents' => fn() => many($p, "SELECT i.*, t.trip_ref, t.status AS trip_status, v.plate_number, m.job_ref, m.status AS job_status
                FROM incidents i JOIN trips t ON t.id=i.trip_id JOIN vehicles v ON v.id=i.vehicle_id
                LEFT JOIN maintenance_jobs m ON m.incident_id=i.id WHERE i.status<>'Resolved' $scope ORDER BY i.id DESC LIMIT 300", $args),
            'incidentCount' => fn() => one($p, "SELECT COUNT(*) AS count FROM incidents i JOIN trips t ON t.id=i.trip_id WHERE i.status<>'Resolved' $scope", $args),
            'attendanceWork' => function()use($u){$d=DispatcherAttendance::read($u);return ['not_timed_in'=>count(array_filter($d['rows'],fn($r)=>$r['attendance_status']!=='Timed In')),'shift'=>$d['shift']];},
        ]);
    }

    /** Dispatchers see the trips of their own truck types (and requests they encoded). */
    private static function monitorDetails(array $u, array $d): array {
        requireRole($u, 'operations_head');
        $data = self::details($u, $d);
        $keep = ['id','trip_ref','dispatcher_role','dispatcher_shift','dispatcher_id','dispatcher_name','responsible_dispatcher_id','responsible_dispatcher_name','client','client_route_ref','pickup_point','delivery_point','truck_type','plate_number','driver_name','driver2_name','helper_name','helper_substitution_reason','scheduled_at','status','dispatched_at','completed_at','attempt_number','awaiting_arrival'];
        $data['trip'] = array_intersect_key($data['trip'], array_flip($keep));
        unset($data['billing'], $data['funds']);
        return $data + ['read_only' => true];
    }

    private static function scope(array $u): array {
        if (roleIsDispatcher($u['role'])) return DispatcherScope::tripSql($u);
        return ['', []];
    }

    private static function select(): string {
        return "SELECT t.*, (SELECT r.current_dispatcher_id FROM trip_responsibilities r WHERE r.trip_id=t.id) AS responsible_dispatcher_id, (SELECT u.full_name FROM trip_responsibilities r JOIN users u ON u.id=r.current_dispatcher_id WHERE r.trip_id=t.id) AS responsible_dispatcher_name, c.company_name AS client, v.plate_number, v.truck_number, v.status AS truck_status,
                d1.full_name AS driver_name, d2.full_name AS driver2_name, hp.full_name AS helper_name,
                ds.full_name AS dispatcher_name, cr.full_name AS created_by_name,
                f.id AS fund_id, f.status AS fund_status, f.amount AS fund_amount, f.rejected_reason AS fund_rejected_reason,
                (SELECT ins.has_not_ok FROM inspections ins WHERE ins.trip_id=t.id AND ins.attempt_number=t.attempt_number AND ins.kind='Pre-Dispatch') AS checklist_not_ok,
                (SELECT ia.has_not_ok FROM inspections ia WHERE ia.trip_id=t.id AND ia.attempt_number=t.attempt_number AND ia.kind='Arrival') AS arrival_not_ok,
                EXISTS (SELECT 1 FROM trip_assignments aw WHERE aw.trip_id=t.id AND aw.resource_type='vehicle' AND t.status='Completed') AS awaiting_arrival,
                (SELECT COUNT(*) FROM trip_updates up WHERE up.trip_id=t.id AND up.update_type='Delay') AS delay_count,
                (SELECT i.status FROM incidents i WHERE i.trip_id=t.id ORDER BY i.id DESC LIMIT 1) AS incident_status,
                (SELECT i.severity FROM incidents i WHERE i.trip_id=t.id ORDER BY i.id DESC LIMIT 1) AS incident_severity,
                (SELECT COUNT(*) FROM documents dw WHERE dw.entity_type='trip' AND dw.entity_id=t.id AND dw.category='" . self::DOC_WAYBILL . "') AS waybill_docs,
                (SELECT COUNT(*) FROM documents da WHERE da.entity_type='trip' AND da.entity_id=t.id AND da.category='" . self::DOC_AR . "') AS ar_docs,
                (SELECT COUNT(*) FROM documents dr WHERE dr.entity_type='trip' AND dr.entity_id=t.id AND dr.category='" . self::DOC_DR . "') AS dr_docs,
                (SELECT COUNT(*) FROM documents dp WHERE dp.entity_type='trip' AND dp.entity_id=t.id AND dp.category='" . self::DOC_DIESEL_PO . "') AS diesel_po_docs,
                (SELECT COUNT(*) FROM documents ds WHERE ds.entity_type='trip' AND ds.entity_id=t.id AND ds.category='" . self::DOC_SUPPLIER_SOA . "') AS soa_docs,
                b.status AS billing_status, b.total_amount AS billing_amount
            FROM trips t
            JOIN clients c ON c.id=t.client_id
            LEFT JOIN vehicles v ON v.id=t.vehicle_id
            LEFT JOIN personnel d1 ON d1.id=t.driver_id
            LEFT JOIN personnel d2 ON d2.id=t.driver2_id
            LEFT JOIN personnel hp ON hp.id=t.helper_id
            LEFT JOIN users ds ON ds.id=t.dispatcher_id
            LEFT JOIN users cr ON cr.id=t.created_by
            LEFT JOIN fund_requests f ON f.trip_id=t.id AND f.attempt_number=t.attempt_number
            LEFT JOIN billings b ON b.trip_id=t.id";
    }

    /** Approval is a recorded decision, not an official trip status. Only Scenario B requests belong here. */
    private static function approvalDecisionSql(): string {
        return "CASE
            WHEN (t.approved_by IS NOT NULL AND t.approved_at IS NOT NULL)
                OR EXISTS (SELECT 1 FROM trip_events ae WHERE ae.trip_id=t.id AND ae.event_type='trip details confirmed by Operations Head') THEN 'approved'
            WHEN EXISTS (SELECT 1 FROM trip_events re WHERE re.trip_id=t.id AND re.event_type='trip request rejected' AND re.to_status='Rejected') THEN 'rejected'
            WHEN t.status='Pending Review' THEN 'pending'
            ELSE NULL END";
    }

    /** Search and date filters apply to request creation, not dispatch/assignment dates. */
    private static function approvalFilters(array $q): array {
        $where = "t.intake_source='Dispatcher'"; $args = [];
        foreach (['client_id' => 't.client_id', 'requester_id' => 't.created_by'] as $key => $column) {
            $id = intField($q, $key, 1, false);
            if ($id !== null) { $where .= " AND $column=?"; $args[] = $id; }
        }
        $type = choiceField($q, 'truck_type', TRUCK_TYPES, false);
        if ($type !== null) { $where .= ' AND t.truck_type=?'; $args[] = $type; }
        $from = dateField($q, 'request_from', false); $to = dateField($q, 'request_to', false);
        must(!$from || !$to || $from <= $to, 'Request date range: the start must not be after the end.');
        if ($from) { $where .= ' AND t.created_at>=?'; $args[] = $from . ' 00:00:00'; }
        if ($to) { $where .= ' AND t.created_at<DATE_ADD(?, INTERVAL 1 DAY)'; $args[] = $to; }
        $search = trim((string)($q['q'] ?? ''));
        must(mb_strlen($search) <= 200, 'Search must be at most 200 characters.');
        if ($search !== '') {
            $where .= ' AND (t.trip_ref LIKE ? OR c.company_name LIKE ? OR t.pickup_point LIKE ? OR t.delivery_point LIKE ? OR cr.full_name LIKE ?)';
            array_push($args, ...array_fill(0, 5, '%' . $search . '%'));
        }
        return [$where, $args];
    }

    /** Read-only projection over existing trips and events. No new approval engine/storage. */
    private static function approvalWorkspace(array $q, ?array $dispatcher = null): array {
        $category = choiceField($q, 'approval', ['pending', 'approved', 'rejected'], false) ?? 'pending';
        $requestedPage = intField($q, 'approval_page', 1, false, 1000000) ?? 1;
        [$where, $args] = self::approvalFilters($q);
        [$scope, $scopeArgs] = $dispatcher ? self::scope($dispatcher) : ['', []];
        $where .= $scope; $args = [...$args, ...$scopeArgs];
        $p = db(); $decision = self::approvalDecisionSql();
        $join = ' FROM trips t JOIN clients c ON c.id=t.client_id LEFT JOIN users cr ON cr.id=t.created_by';
        $counts = one($p, "SELECT COALESCE(SUM(decision='pending'),0) AS pending,
            COALESCE(SUM(decision='approved'),0) AS approved, COALESCE(SUM(decision='rejected'),0) AS rejected,
            COALESCE(SUM(decision IS NULL),0) AS unclassified
            FROM (SELECT $decision AS decision $join WHERE $where) decisions", $args);
        $total = (int)$counts[$category]; $size = $dispatcher ? 10 : 12;
        $pages = max(1, (int)ceil($total / $size)); $page = min($requestedPage, $pages); $offset = ($page - 1) * $size;
        $direction = $category === 'pending' ? 'ASC' : 'DESC';
        $rows = many($p, "SELECT t.id, t.trip_ref, t.client_id, c.company_name AS client, t.truck_type,
            t.pickup_point, t.delivery_point, t.scheduled_at, t.created_at, t.created_by, cr.full_name AS created_by_name,
            t.status, t.approved_at, t.approved_by, ($decision) AS approval_category
            $join WHERE $where AND ($decision)=? ORDER BY t.created_at $direction, t.id $direction LIMIT $size OFFSET $offset", [...$args, $category]);
        return [
            'approval' => ['category' => $category, 'counts' => array_map('intval', $counts), 'rows' => $rows,
                'total' => $total, 'page' => $page, 'pages' => $pages, 'pageSize' => $size],
            'filterOptions' => [
                'clients' => many($p, "SELECT DISTINCT c.id, c.company_name FROM clients c JOIN trips t ON t.client_id=c.id WHERE t.intake_source='Dispatcher' $scope ORDER BY c.company_name", $scopeArgs),
                'requesters' => many($p, "SELECT DISTINCT u.id, u.full_name FROM users u JOIN trips t ON t.created_by=u.id WHERE t.intake_source='Dispatcher' $scope ORDER BY u.full_name", $scopeArgs),
            ],
        ];
    }

    /** Filters of the trip history (filter modal): text, status, type, client, truck, driver, dispatcher, dates. */
    public static function filters(array $q, string $a = 't'): array {
        $w = ''; $args = [];
        $from = trim((string)($q['from'] ?? '')); $to = trim((string)($q['to'] ?? ''));
        if ($from !== '' && DateTimeImmutable::createFromFormat('!Y-m-d', $from)) { $w .= " AND DATE($a.scheduled_at)>=?"; $args[] = $from; }
        if ($to !== '' && DateTimeImmutable::createFromFormat('!Y-m-d', $to)) { $w .= " AND DATE($a.scheduled_at)<=?"; $args[] = $to; }
        foreach (['client_id' => "$a.client_id", 'vehicle_id' => "$a.vehicle_id", 'dispatcher_id' => "$a.dispatcher_id"] as $k => $col) {
            if (!empty($q[$k]) && ctype_digit((string)$q[$k])) { $w .= " AND $col=?"; $args[] = (int)$q[$k]; }
        }
        if (!empty($q['person_id']) && ctype_digit((string)$q['person_id'])) {
            $w .= " AND ($a.driver_id=? OR $a.driver2_id=? OR $a.helper_id=?)"; $id = (int)$q['person_id']; array_push($args, $id, $id, $id);
        }
        if (!empty($q['status']) && in_array($q['status'], self::STATUSES, true)) { $w .= " AND $a.status=?"; $args[] = $q['status']; }
        if (!empty($q['truck_type']) && in_array($q['truck_type'], TRUCK_TYPES, true)) { $w .= " AND $a.truck_type=?"; $args[] = $q['truck_type']; }
        $text = trim((string)($q['q'] ?? ''));
        if ($text !== '') {
            $w .= " AND ($a.trip_ref LIKE ? OR $a.pickup_point LIKE ? OR $a.delivery_point LIKE ? OR $a.waybill_number LIKE ? OR $a.booking_reference LIKE ? OR $a.consignee LIKE ?)";
            $like = '%' . $text . '%'; array_push($args, $like, $like, $like, $like, $like, $like);
        }
        return [$w, $args];
    }

    // =================================================================
    // ACTIONS
    // =================================================================
    public static function action(array $u, string $action, array $d): array {
        if(str_starts_with($action,'turnover_')){core('DispatcherTurnover');return DispatcherTurnover::action($u,substr($action,9),$d);}
        return match ($action) {
            'create' => self::create($u, $d),
            'review' => self::review($u, $d),
            'monitor_details' => self::monitorDetails($u, $d),
            'edit' => self::edit($u, $d),
            'auto_assign' => self::retryAssign($u, $d),
            'reassign' => self::reassign($u, $d),
            'crew_options' => self::crewOptions($u, $d),
            'dispatcher_resources' => self::dispatcherResources($u,$d),
            'assign_resources' => self::assignResources($u,$d),
            'diesel_po' => self::attachDieselPo($u, $d),
            'create_batch' => self::createBatch($u, $d),
            'checklist_start' => self::checklistStart($u, $d),
            'checklist' => self::checklist($u, $d),
            'dispatch' => self::dispatch($u, $d),
            'incident' => self::incident($u, $d),
            'resume' => self::resume($u, $d),
            'incident_reschedule' => self::incidentReschedule($u, $d),
            'reschedule' => self::reschedule($u, $d),
            'progress' => self::progress($u,$d),
            'complete' => self::complete($u, $d),
            'arrival' => self::arrival($u, $d),
            'cancel' => self::cancel($u, $d),
            'details' => self::details($u, $d),
            default => throw new BusinessRuleException('Unknown trip action.', 404),
        };
    }

    // ---------- helpers ----------
    private static function lockTrip(PDO $p, int $id): array {
        $t = one($p, 'SELECT * FROM trips WHERE id=? FOR UPDATE', [$id]);
        must((bool)$t, 'Trip not found.', 404);
        $r=one($p,'SELECT current_dispatcher_id FROM trip_responsibilities WHERE trip_id=?',[$id]);$t['responsible_dispatcher_id']=$r['current_dispatcher_id']??null;
        return $t;
    }
    /** Explicit planning ownership is fixed; legacy NULL-shift trips retain group-wide access. */
    private static function mustBeTripDispatcher(array $u, array $t): void {
        must(roleIsDispatcher($u['role']), 'Only the dispatcher can do this step.', 403);
        must($t['dispatcher_role'] === $u['role'], 'This trip belongs to the ' . (ROLE_LABELS[$t['dispatcher_role']] ?? 'other') . ' team.', 403);
        if(isset($t['truck_type']))must(in_array($t['truck_type'],DispatcherScope::types($u),true),'Trip truck type belongs to another operational group.',403);
        must(DispatcherScope::permitsTrip($u,$t),'This account does not hold current operational responsibility for this trip.',403);
    }
    private static function mustBeStatus(array $t, array $allowed, string $what): void {
        must(in_array($t['status'], $allowed, true), "Cannot $what: the trip is " . $t['status'] . ' (allowed only when ' . implode(' / ', $allowed) . ').', 409);
    }
    public static function event(PDO $p, ?int $uid, int $tripId, string $type, string $from, string $to, string $notes = '', array $before = [], array $after = []): void {
        $p->prepare('INSERT INTO trip_events (trip_id, actor_id, event_type, from_status, to_status, notes) VALUES (?,?,?,?,?,?)')
          ->execute([$tripId, $uid, $type, $from, $to, cut($notes, 500)]);
        $message = ($from !== $to ? "$from -> $to. " : '') . $notes;
        // Adjustment #5H-1: Trip status and safe delivery-field changes.
        // Status is a real transition. Preserve any supplied driver/truck edit details.
        if ($from !== $to) {
            $before = ['Trip status' => $from !== '' ? $from : null] + $before;
            $after = ['Trip status' => $to !== '' ? $to : null] + $after;
        }
        if ($after) auditWithChanges($p, $uid, 'Trips', 'trip ' . $tripId, $type, $message, $before, $after);
        else audit($p, $uid, 'Trips', 'trip ' . $tripId, $type, $message);
    }
    public static function setStatus(PDO $p, ?int $uid, array $t, string $to, string $type, string $notes = ''): void {
        run($p, 'UPDATE trips SET status=? WHERE id=?', [$to, $t['id']]);
        self::event($p, $uid, (int)$t['id'], $type, $t['status'], $to, $notes);
    }
    private static function hasRate(PDO $p, int $clientId, ?string $type): bool {
        return $type !== null && (bool)one($p, "SELECT r.id FROM client_routes r JOIN client_route_rates cr ON cr.route_id=r.id AND cr.status='Approved' AND cr.rate_type='Flat' WHERE r.status='Approved' AND r.client_id=? AND r.truck_type=?", [$clientId, $type]);
    }

    /** Longest billable distance accepted (km). Luzon to Mindanao by road is well below this. */
    public const MAX_DISTANCE_KM = 3000;
    /** Philippine bounding box for map pins (keeps a mistyped or swapped coordinate out). */
    private const PH_LAT = [4.0, 21.5];
    private const PH_LNG = [116.0, 127.5];
    /** Set by deliveryFields() when an unchanged schedule is already in the past (shown with the result). */
    private static string $scheduleWarning = '';

    /**
     * Schedule rule (one place for create, review, edit and reschedule):
     *   - a NEW or CHANGED schedule must be the current date and time or later (Asia/Manila,
     *     5-minute grace) and at most 365 days ahead: futureDateTimeField() in bootstrap.php;
     *   - an UNCHANGED schedule of a trip that is not dispatched yet is kept even if its time
     *     has passed, so other details can still be corrected; a warning is returned instead.
     */
    private static function scheduleField(array $d, ?string $current): string {
        $typed = dateTimeField($d, 'scheduled_at');
        if ($current !== null && substr($typed, 0, 16) === substr($current, 0, 16)) {
            self::$scheduleWarning = strtotime($current) < time()
                ? ' Note: the schedule (' . substr($current, 0, 16) . ') has already passed; set a new date and time if the trip will leave later.' : '';
            return $current;
        }
        return futureDateTimeField($d, 'scheduled_at');
    }

    /**
     * CHECKLIST_38_40_TRIP_TIME_GATES_V1 — execution actions follow the trip's CURRENT schedule.
     *   Planning / approval (create, review, edit before dispatch, assignment, fund request and approval,
     *   reschedule, cancel) is allowed any time. Execution is time-gated (Philippine time, server clock):
     *     - pre-dispatch checklist (open and submit): from `checklist_lead_hours` before scheduled_at;
     *     - Confirm Dispatch / Redispatch: from `dispatch_early_minutes` before scheduled_at;
     *     - incident, resume, completion and arrival need a dispatched trip, so they are gated through dispatch;
     *     - fund release: Funds.php (Checklist #40 phase 1, `fund_release_lead_hours`).
     *   Hours are counted, not dates, so a 12:30 AM trip opens its checklist the evening before.
     *   A late dispatch (after scheduled_at) is allowed and noted in the trip history.
     */
    public static function timeGates(array $t, ?int $now = null): array {
        $now ??= time();
        $sched = strtotime((string)$t['scheduled_at']);
        must($sched !== false, 'Invalid trip schedule.', 409);
        $check = $sched - (int)round(Settings::value('checklist_lead_hours') * 3600);
        $disp = $sched - (int)round(Settings::value('dispatch_early_minutes') * 60);
        return ['scheduled_at' => date('Y-m-d H:i:s', $sched),
                'checklist_opens_at' => date('Y-m-d H:i:s', $check), 'checklist_allowed_now' => $now >= $check,
                'dispatch_opens_at' => date('Y-m-d H:i:s', $disp), 'dispatch_allowed_now' => $now >= $disp,
                'late_minutes' => $now > $sched ? intdiv($now - $sched, 60) : 0];
    }
    /** The current attempt's pre-dispatch checklist (null when there is none). */
    private static function currentChecklist(PDO $p, array $t): ?array {
        return one($p, "SELECT id, vehicle_id, completed_at FROM inspections WHERE trip_id=? AND attempt_number=? AND kind='Pre-Dispatch' AND completed_at IS NOT NULL",
            [$t['id'], $t['attempt_number']]) ?: null;
    }
    /**
     * Schedule-change revalidation (approved 2026-11-08). A completed checklist stays valid for a new schedule only if it
     * was completed inside that schedule's checklist window (completed_at >= new scheduled_at - checklist_lead_hours).
     * Earlier schedules and small moves inside the window keep it; a later move outside the window sends the trip back to
     * Pre-Assigned for a new checklist. Assignment, fund, route distance and booking details are not touched.
     * Uses the existing Admin window; no separate expiry duration.
     */
    private static function revalidateChecklistAfterScheduleChange(PDO $p, array $u, array $t, array $row): string {
        if ($t['status'] !== 'Ready for Dispatch' || !array_key_exists('scheduled_at', $row)) return '';
        if (strtotime((string)$row['scheduled_at']) === strtotime((string)$t['scheduled_at'])) return '';
        $new = $t; $new['scheduled_at'] = $row['scheduled_at'];
        $opens = self::timeGates($new)['checklist_opens_at'];
        $c = self::currentChecklist($p, $t);
        if ($c && strtotime((string)$c['completed_at']) >= strtotime($opens)) return '';
        $why = 'The pre-dispatch checklist must be done again: it was completed ' . ($c ? self::phTime((string)$c['completed_at']) : '(not found)')
             . ', before the checklist window for the new schedule (opens ' . self::phTime($opens) . ').';
        self::setStatus($p, $u['id'], $t, 'Pre-Assigned', 'pre-dispatch checklist reset after schedule change', $why);
        return ' ' . $why;
    }
    private static function phTime(string $dt): string { return date('M j, Y g:i A', strtotime($dt)) . ' Philippine time'; }
    /** Refuses the pre-dispatch checklist before its window (re-checked on submit: the schedule may have moved). */
    private static function mustBeChecklistWindow(array $t): void {
        $g = self::timeGates($t);
        must($g['checklist_allowed_now'], 'Too early for the pre-dispatch checklist. It opens ' . self::phTime($g['checklist_opens_at'])
            . ' (' . self::hoursText(Settings::value('checklist_lead_hours')) . ' before the scheduled dispatch, ' . self::phTime($g['scheduled_at']) . ').', 409);
    }
    private static function durationText(int $min): string { return $min >= 60 ? intdiv($min, 60) . ' h ' . ($min % 60) . ' min' : $min . ' min'; }
    private static function hoursText(float $h): string { return $h == 0.0 ? 'at the time' : rtrim(rtrim(number_format($h, 2, '.', ''), '0'), '.') . ' hour(s)'; }

    /** Pickup and delivery coordinates: both or neither for each point, inside the Philippines. */
    private static function coordinates(array $d): array {
        $out = [];
        foreach (['pickup' => 'pickup point', 'delivery' => 'delivery point'] as $k => $label) {
            $lat = trim((string)($d[$k . '_lat'] ?? '')); $lng = trim((string)($d[$k . '_lng'] ?? ''));
            if ($lat === '' && $lng === '') { $out[$k . '_lat'] = null; $out[$k . '_lng'] = null; continue; }
            must(is_numeric($lat) && is_numeric($lng), "The map pin of the $label is incomplete. Pin it again or remove it.");
            must((float)$lat >= self::PH_LAT[0] && (float)$lat <= self::PH_LAT[1] && (float)$lng >= self::PH_LNG[0] && (float)$lng <= self::PH_LNG[1],
                "The map pin of the $label is outside the Philippines. Pin it again.");
            $out[$k . '_lat'] = round((float)$lat, 7); $out[$k . '_lng'] = round((float)$lng, 7);
        }
        return $out;
    }

    /** Common delivery fields of the trip form. $currentSchedule = the saved schedule when editing. */
    private static function deliveryFields(array $d, bool $needsKm, ?string $currentSchedule = null): array {
        self::$scheduleWarning = '';
        $row = [
            'consignee' => ($c = optField($d, 'consignee', 120)) !== null ? strtoupper($c) : null,
            'pickup_point' => strtoupper(field($d, 'pickup_point', 200)),
            'delivery_point' => strtoupper(field($d, 'delivery_point', 200)),
            'scheduled_at' => self::scheduleField($d, $currentSchedule),
            'delivery_details' => optField($d, 'delivery_details', 500),
            'booking_reference' => optField($d, 'booking_reference', 150),
            'units_planned' => intField($d, 'units_planned', 1, false, 100),
            'container_number' => ($x = optField($d, 'container_number', 60)) !== null ? strtoupper($x) : null,
            'container_size' => optField($d, 'container_size', 20),
            'seal_number' => optField($d, 'seal_number', 60),
            'shipment_number' => optField($d, 'shipment_number', 60),
            'distance_km' => numberField($d, 'distance_km', $needsKm, self::MAX_DISTANCE_KM),
        ] + self::coordinates($d);
        must($row['pickup_point'] !== $row['delivery_point'], 'Pickup point and delivery point must be different.');
        if ($needsKm) must($row['distance_km'] > 0, 'Enter the billable distance in km (pickup point to delivery point).');
        return $row;
    }

    // ---------- road distance (Checklist #5 Phase 3) ----------
    /** Distance columns of a trip (the road line itself is kept in trip_routes). */
    private const DISTANCE_COLS = ['distance_source', 'route_option', 'distance_computed_km', 'distance_provider',
                                   'distance_computed_at', 'distance_override_reason', 'client_route_id'];

    /**
     * Distance of the trip form, decided by the SERVER (the km typed or filled in by the browser is
     * never trusted alone). Rules: backend/Core/RouteDistance.php (same as Client Routes).
     *   - approved client route chosen (client_route_id) -> km of that route; must be selectable for
     *     this client and truck type; its map pins are used
     *   - map pins + route option                          -> road distance computed again here
     *   - otherwise / map service unavailable             -> typed km (Manual)
     *   - km different from the reference km              -> reason required; warning above 15%
     * $current = the saved trip (review / edit): when nothing about the distance changed, the saved
     * distance is kept as is (no new map call; an approved route stays even if deactivated later).
     * Returns [$row, $geometry, $note]; $geometry = string (save), null (remove), false (keep).
     */
    private static function distanceColumns(array $u, array $d, array $row, int $clientId, ?string $truckType, ?array $current): array {
        $routeId = trim((string)($d['client_route_id'] ?? '')) === '' ? null : idField($d, 'client_route_id');
        $km = $row['distance_km'] !== null ? (float)$row['distance_km'] : null;
        if ($current !== null && self::distanceUnchanged($d, $row, $routeId, $current)) {
            foreach (self::DISTANCE_COLS as $c) $row[$c] = $current[$c];
            return [$row, false, ''];
        }
        if ($routeId !== null) {
            $res = RouteDistance::fromClientRoute(db(), $routeId, $clientId, $truckType, $d, $km);
            if ($res['points']) {
                [$row['pickup_lat'], $row['pickup_lng']] = $res['points'][0];
                [$row['delivery_lat'], $row['delivery_lng']] = $res['points'][1];
            }
        } else {
            $res = RouteDistance::resolve($u['id'], $d, $km, [$row['pickup_lat'], $row['pickup_lng']], [$row['delivery_lat'], $row['delivery_lng']]);
        }
        $geometry = $res['cols']['geometry'];
        unset($res['cols']['geometry']);
        $row = array_merge($row, $res['cols'], ['client_route_id' => $routeId, 'distance_km' => $res['km']]);
        return [$row, $geometry, $res['warning'] !== '' ? ' ' . $res['warning'] : ''];
    }

    /** True when the form did not change anything the distance depends on. */
    private static function distanceUnchanged(array $d, array $row, ?int $routeId, array $t): bool {
        $same = fn($a, $b) => ($a === null || $a === '') ? ($b === null || $b === '') : ($b !== null && $b !== '' && abs((float)$a - (float)$b) < 0.0000005);
        $opt = is_string($d['route_option'] ?? null) && trim($d['route_option']) !== '' ? trim($d['route_option']) : null;
        $reason = trim((string)($d['distance_override_reason'] ?? ''));
        $savedRoute = $t['client_route_id'] !== null ? (int)$t['client_route_id'] : null;
        $kmSame = ($row['distance_km'] === null && $t['distance_km'] === null)
            || ($row['distance_km'] !== null && $t['distance_km'] !== null && abs(round((float)$row['distance_km'], 2) - (float)$t['distance_km']) < 0.005);
        return ($row['truck_type'] ?? $t['truck_type']) === $t['truck_type'] && $routeId === $savedRoute && ($routeId !== null || $opt === $t['route_option']) && $kmSame
            && $same($row['pickup_lat'], $t['pickup_lat']) && $same($row['pickup_lng'], $t['pickup_lng'])
            && $same($row['delivery_lat'], $t['delivery_lat']) && $same($row['delivery_lng'], $t['delivery_lng'])
            && ($reason === '' || $reason === (string)$t['distance_override_reason']);
    }

    /** Road line of a trip: string = save, null = remove, false = keep. */
    private static function saveGeometry(PDO $p, int $tripId, string|null|false $geometry): void {
        if ($geometry === false) return;
        if ($geometry === null) { run($p, 'DELETE FROM trip_routes WHERE trip_id=?', [$tripId]); return; }
        $p->prepare('INSERT INTO trip_routes (trip_id, geometry) VALUES (?,?) ON DUPLICATE KEY UPDATE geometry=VALUES(geometry)')->execute([$tripId, $geometry]);
    }

    /** Short text for the trip history, e.g. "18.52 km, Map (Recommended)". */
    private static function distanceNote(array $row): string {
        if ($row['distance_km'] === null) return 'no km yet';
        $opt = $row['route_option'] && isset(Routing::OPTIONS[$row['route_option']]) ? Routing::OPTIONS[$row['route_option']]['label'] : '';
        return number_format((float)$row['distance_km'], 2) . ' km, ' . $row['distance_source']
            . ($row['distance_computed_km'] !== null && in_array($row['distance_source'], ['Map (edited)', 'Client Route (edited)'], true) ? ' (reference ' . $row['distance_computed_km'] . ' km)' : '')
            . ($opt !== '' ? ', ' . $opt : '') . ($row['distance_provider'] && str_starts_with((string)$row['distance_provider'], 'Client route') ? ', ' . $row['distance_provider'] : '');
    }

    // ---------- create (Operations Head only) ----------
    private static function create(array $u, array $d): array {
        must(self::canCreate($u), 'Only the Operations Head can create trips in Trip Management.', 403);
        $isOps = hasRole($u, 'operations_head');
        // Reconcile an already-created form before applying new-entry requirements or date validation.
        $key = trim((string)($d['request_key'] ?? ''));
        must($key === '' || (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $key), 'Invalid form key. Reload the page.');
        if($key!==''&&($saved=one(db(),'SELECT id,trip_ref,created_by,dispatcher_role,dispatcher_shift,truck_type FROM trips WHERE request_key=?',[strtolower($key)]))){
            must((int)$saved['created_by']===(int)$u['id'],'Invalid form key. Reload the page.',409);
            if(!$isOps)must(DispatcherScope::permitsTrip($u,$saved),'The recorded request is outside your current operational scope.',403);
            return ['trip_id'=>(int)$saved['id'],'trip_ref'=>$saved['trip_ref'],'duplicate'=>true,'message'=>'Trip '.$saved['trip_ref'].' was already created from this form. No second trip was made.'];
        }
        // km is checked AFTER the road distance (an approved route or the map may supply it).
        $row = self::deliveryFields($d, false);
        $row['client_id'] = idField($d, 'client_id');
        // One key per opened New Trip form: the same form sent twice creates the trip once.
        $row['request_key'] = $key === '' ? null : strtolower($key);
        $type = choiceField($d, 'truck_type', TRUCK_TYPES, $isOps);
        if(!$isOps)must($type!==null,'Choose an authorized truck type.');
        if (!$isOps && $type) must(dispatcherRoleFor($type) === $u['role'], 'This truck type is handled by the other dispatcher team.', 403);
        $row['truck_type'] = $type;
        if($isOps){
            $row += DispatcherPlanning::assignment($type,$d['dispatcher_assignment']??null);
            must(!empty($d['client_route_id']),'Choose an approved client route for every trip entry.');
        }
        if ($type === 'Car Carrier' && !$row['units_planned']) $row['units_planned'] = (int)Settings::value('units_per_trip');
        // Road distance before the transaction (no lock is held while the map service answers).
        [$row, $geometry, $distNote] = self::distanceColumns($u, $d, $row, $row['client_id'], $type, null);
        if ($isOps) must($row['distance_km'] !== null && $row['distance_km'] > 0, 'Enter the billable distance in km (pickup point to delivery point).');
        try { return transaction(function (PDO $p) use ($u, $d, $row, $isOps, $geometry, $distNote) {
            if ($row['request_key'] !== null && ($dup = one($p, 'SELECT id, trip_ref, created_by,dispatcher_role,dispatcher_shift,truck_type FROM trips WHERE request_key=?', [$row['request_key']]))) {
                must((int)$dup['created_by'] === $u['id'], 'Invalid form key. Reload the page.', 409);
                if(!$isOps)must(DispatcherScope::permitsTrip($u,$dup),'The recorded request is outside your current operational scope.',403);
                return ['trip_id' => (int)$dup['id'], 'trip_ref' => $dup['trip_ref'], 'duplicate' => true,
                        'message' => 'Trip ' . $dup['trip_ref'] . ' was already created from this form (sent twice). No second trip was made.'];
            }
            core('DispatcherTurnover');
            $responsible=DispatcherTurnover::planningAccount($p,$row,idField($d,'responsible_dispatcher_id'));
            $c = one($p, 'SELECT company_name, status FROM clients WHERE id=?', [$row['client_id']]);
            must($c && $c['status'] === 'Active', 'Choose an Active client from the client masterlist (clients waiting for documents or rates cannot be used yet).');
            if ($row['truck_type']) must(self::hasRate($p, $row['client_id'], $row['truck_type']), $c['company_name'] . ' has no billing rate for ' . $row['truck_type'] . ' yet.');
            if ($row['client_route_id'] !== null) must((bool)RouteDistance::selectable($p, $row['client_id'], $row['truck_type'], $row['client_route_id']),
                'The chosen client route was just changed and can no longer be used. Reload the page.', 409);
            $row += ['trip_ref' => 'TMP-' . bin2hex(random_bytes(6)), 'created_by' => $u['id'],
                     'intake_source' => $isOps ? 'Operations Head' : 'Dispatcher',
                     'dispatcher_role' => $row['truck_type'] ? dispatcherRoleFor($row['truck_type']) : $u['role'],
                     'status' => 'Awaiting Resources'];
            $id = insert($p, 'trips', $row);
            $ref = refNumber('TRP', $id);
            run($p, 'UPDATE trips SET trip_ref=? WHERE id=?', [$ref, $id]);
            // Nested action reuses this transaction: no trip can commit without its explicit initial owner.
            DispatcherTurnover::action($u,'assign_initial',['trip_id'=>$id,'dispatcher_id'=>$responsible['id']]);
            self::saveGeometry($p, $id, $geometry);
            self::event($p, $u['id'], $id, $isOps ? 'trip created by Operations Head (Scenario A)' : 'delivery request encoded by dispatcher (Scenario B)', '', 'Awaiting Resources',
                'Distance: ' . self::distanceNote($row) . ($row['distance_override_reason'] ? '. Km changed by hand: ' . $row['distance_override_reason'] : '') . ($isOps ? '. Dispatcher: '.ROLE_LABELS[$row['dispatcher_role']].' ('.$row['dispatcher_shift'].').' : ''));
            if ($isOps) {
                Notify::tripRoles($p, self::lockTrip($p, $id), [$row['dispatcher_role']], 'Trip awaiting assignment', "Trip $ref is scheduled for your group/shift. Select its Truck and fixed Driver in Trip Management.", 'trips', 'trip-assign-'.$id);
                return ['trip_id' => $id, 'trip_ref' => $ref, 'message' => "Trip $ref created with initial account responsibility. Awaiting manual Dispatcher resource assignment." . self::$scheduleWarning . $distNote];
            }
        }); } catch (PDOException $e) {
            // A concurrent retry may win the unique request key. Reuse that complete transaction only.
            if ((int)($e->errorInfo[1]??0)===1062 && $row['request_key']!==null) {
                $saved=one(db(),'SELECT id,trip_ref,created_by FROM trips WHERE request_key=?',[$row['request_key']]);
                if($saved){must((int)$saved['created_by']===(int)$u['id'],'Invalid form key. Reload the page.',409);return ['trip_id'=>(int)$saved['id'],'trip_ref'=>$saved['trip_ref'],'duplicate'=>true,'message'=>'Trip '.$saved['trip_ref'].' was already created from this form.'];}
            }
            throw $e;
        }
    }

    /**
     * BATCH_TRIP_PLANNING_V1 — several trips entered in one planning session.
     *   Every row becomes its own trip through the SAME create() rules (client, rates, approved route, schedule,
     *   distance, manual Dispatcher assignment after Operations Head creation). No new approval table.
     *   1) All rows are checked first without writing anything; if any row is wrong, nothing is created and every
     *      problem is listed by row number.
     *   2) Then the rows are created one by one (earliest schedule first, to preserve batch ordering).
     *      A row that fails unexpectedly here is reported "Not created"; the other rows stay created (partial success,
     *      approved 2026-11-08). The screen can retry only those rows with their original keys.
     *   Duplicates are prevented by the per-row form key (trips.request_key, UNIQUE) — NOT by comparing client, route
     *   or time: a client may need several trucks for the same route and time.
     *   Returns one result per row, in the order sent (row = the screen's row number when given as row_no).
     */
    public const BATCH_MAX_ROWS = 20;
    private static function createBatch(array $u, array $d): array {
        must(self::canCreate($u), 'Only the Operations Head can create trips in Trip Management.', 403);
        $rows = $d['rows'] ?? null;
        must(is_array($rows) && array_is_list($rows) && $rows, 'Add at least one trip to the batch.');
        must(count($rows) <= self::BATCH_MAX_ROWS, 'A batch can have at most ' . self::BATCH_MAX_ROWS . ' trips. Split it into smaller batches.');
        $isOps = hasRole($u, 'operations_head');
        $p = db();
        $errors = []; $seenKeys = []; $sched = []; $label = [];
        foreach ($rows as $i => $row) {
            // Row number shown to the user: the screen's own row number on a retry of some rows only.
            $n = $label[$i] = is_array($row) && isset($row['row_no']) && ctype_digit((string)$row['row_no']) && (int)$row['row_no'] >= 1 && (int)$row['row_no'] <= self::BATCH_MAX_ROWS
                ? (int)$row['row_no'] : $i + 1;
            try {
                must(is_array($row), 'Invalid trip entry.');
                $key = strtolower(trim((string)($row['request_key'] ?? '')));
                must((bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $key), 'Invalid form key. Reload the page.');
                must(!isset($seenKeys[$key]), 'Same form key as row ' . ($seenKeys[$key] ?? '') . '. Reload the page.');
                $seenKeys[$key] = $n;
                $prev = one($p, 'SELECT trip_ref, created_by,dispatcher_role,dispatcher_shift,truck_type FROM trips WHERE request_key=?', [$key]);
                must(!$prev || (int)$prev['created_by'] === $u['id'], 'Invalid form key. Reload the page.');
                if($prev&&!$isOps)must(DispatcherScope::permitsTrip($u,$prev),'The recorded request is outside your current operational scope.',403);
                if ($prev) { $sched[$i] = PHP_INT_MAX; continue; }   // already created by an earlier send: reported as duplicate below
                $f = self::deliveryFields($row, false);
                $client = idField($row, 'client_id');
                $type = choiceField($row, 'truck_type', TRUCK_TYPES, $isOps);
                if($isOps){$planning=DispatcherPlanning::assignment($type,$row['dispatcher_assignment']??null);core('DispatcherTurnover');DispatcherTurnover::planningAccount($p,$planning,idField($row,'responsible_dispatcher_id'),false);}
                if(!$isOps)must($type!==null,'Choose an authorized truck type.');
                if (!$isOps && $type) must(dispatcherRoleFor($type) === $u['role'], 'This truck type is handled by the other dispatcher team.', 403);
                $c = one($p, 'SELECT company_name, status FROM clients WHERE id=?', [$client]);
                must($c && $c['status'] === 'Active', 'Choose an Active client.');
                if ($type) must(self::hasRate($p, $client, $type), $c['company_name'] . ' has no billing rate for ' . $type . ' yet.');
                $route = ($row['client_route_id'] ?? '') === '' || ($row['client_route_id'] ?? null) === null ? null : idField($row, 'client_route_id');
                if($isOps)must($route!==null,'Choose an approved client route for every trip entry.');
                if ($route !== null) must((bool)RouteDistance::selectable($p, $client, $type, $route), 'The chosen approved route cannot be used for this client and truck type.');
                $mapRoute = trim((string)($row['route_option'] ?? '')) !== '' && $f['pickup_lat'] !== null && $f['delivery_lat'] !== null;
                if ($isOps) must($route !== null || $mapRoute || ($f['distance_km'] !== null && $f['distance_km'] > 0), 'Enter the billable distance in km, or choose an approved route.');
                $sched[$i] = strtotime((string)$f['scheduled_at']);
            } catch (BusinessRuleException $e) {
                $errors[] = "Row $n: " . $e->getMessage();
            }
        }
        must(!$errors, 'Nothing was created. Fix these rows: ' . implode(' ', $errors), 400);
        $order = array_keys($rows);
        usort($order, fn($a, $b) => [$sched[$a], $a] <=> [$sched[$b], $b]);   // earliest schedule first (stable)
        $results = [];
        foreach ($order as $i) {
            $row = $rows[$i];
            try {
                $r = self::create($u, $row);
                [$resultScope,$resultArgs]=self::scope($u);
                $t = one($p, "SELECT t.trip_ref, t.status, v.plate_number FROM trips t LEFT JOIN vehicles v ON v.id=t.vehicle_id WHERE t.id=? $resultScope", [$r['trip_id'],...$resultArgs]);
                must((bool)$t,'The recorded request is outside your current operational scope.',403);
                $results[$i] = ['row' => $label[$i], 'request_key' => strtolower((string)$row['request_key']), 'result' => !empty($r['duplicate']) ? 'Already created' : 'Created', 'trip_id' => (int)$r['trip_id'],
                                'trip_ref' => $t['trip_ref'], 'trip_status' => $t['status'], 'truck' => $t['plate_number'], 'message' => $r['message']];
            } catch (PDOException $e) {
                // Same batch sent twice at the same moment: the other request inserted this form key first.
                $dup = ($e->errorInfo[1] ?? 0) === 1062 ? one($p, 'SELECT id, trip_ref, status,created_by,dispatcher_role,dispatcher_shift,truck_type FROM trips WHERE request_key=? AND created_by=?', [strtolower((string)$row['request_key']), $u['id']]) : null;
                if($dup&&!$isOps&&!DispatcherScope::permitsTrip($u,$dup))$dup=null;
                if (!$dup) {
                    error_log('Batch planning row ' . $label[$i] . ' failed: ' . $e->getMessage());
                    $results[$i] = ['row' => $label[$i], 'request_key' => strtolower((string)$row['request_key']), 'result' => 'Not created', 'trip_id' => null, 'trip_ref' => null, 'trip_status' => null, 'truck' => null,
                                    'message' => 'This row could not be saved (server error). Retry it; created rows are not affected.'];
                    continue;
                }
                $results[$i] = ['row' => $label[$i], 'request_key' => strtolower((string)$row['request_key']), 'result' => 'Already created', 'trip_id' => (int)$dup['id'], 'trip_ref' => $dup['trip_ref'], 'trip_status' => $dup['status'], 'truck' => null,
                                'message' => 'Trip ' . $dup['trip_ref'] . ' was already created from this row (sent twice). No second trip was made.'];
            } catch (BusinessRuleException $e) {
                $results[$i] = ['row' => $label[$i], 'request_key' => strtolower((string)$row['request_key']), 'result' => 'Not created', 'trip_id' => null, 'trip_ref' => null, 'trip_status' => null, 'truck' => null,
                                'message' => $e->getMessage() . ' Fix the row if needed and retry it; created rows are not affected.'];
            } catch (Throwable $e) {
                // Unexpected failure of one row (database / server): keep the others, report this row, log the detail.
                error_log('Batch planning row ' . $label[$i] . ' failed: ' . get_class($e) . ': ' . $e->getMessage());
                $results[$i] = ['row' => $label[$i], 'request_key' => strtolower((string)$row['request_key']), 'result' => 'Not created', 'trip_id' => null, 'trip_ref' => null, 'trip_status' => null, 'truck' => null,
                                'message' => 'This row could not be saved (server error). Retry it; created rows are not affected.'];
            }
        }
        ksort($results);
        $results = array_values($results);
        $count = fn(string $r) => count(array_filter($results, fn($x) => $x['result'] === $r));
        $summary = sprintf('%d created, %d already created, %d not created', $count('Created'), $count('Already created'), $count('Not created'));
        $waiting = count(array_filter($results, fn($x) => $x['trip_status'] === 'Awaiting Resources'));
        audit($p, $u['id'], 'Trips', 'trip batch', 'trip batch planned', count($rows) . ' row(s): ' . $summary . '. '
            . implode(', ', array_filter(array_map(fn($x) => $x['trip_ref'] ? $x['trip_ref'] . ' ' . $x['trip_status'] : null, $results))));
        return ['results' => $results, 'message' => 'Batch planning: ' . $summary . '.' . ($waiting ? " $waiting trip(s) are Awaiting Resources (manual Dispatcher selection required)." : '')];
    }

    // ---------- Retired approval compatibility endpoint ----------
    private static function review(array $u, array $d): array {
        requireRole($u, 'operations_head');
        throw new BusinessRuleException('Trip-request approval is retired. Historical requests remain read-only; create new work through Add Trip.', 409);
    }

    /** Ops Head edits details before dispatch; the dispatcher may fill in booking / container / units details. */
    private static function edit(array $u, array $d): array {
        $id = idField($d, 'trip_id');
        // Operations Head: form and road distance checked BEFORE the transaction (no lock while the
        // map service answers); status checked again under the lock.
        $prepared = null;
        if (hasRole($u, 'operations_head')) {
            $pre = one(db(), 'SELECT * FROM trips WHERE id=?', [$id]);
            must((bool)$pre, 'Trip not found.', 404);
            must(!in_array($pre['status'], self::CLOSED, true), 'The trip is already closed.', 409);
            self::mustBeStatus($pre, ['Awaiting Resources', 'Pre-Assigned', 'Ready for Dispatch', 'For Reschedule'], 'edit the delivery details');
            // An approved route, required at planning (create), is not dropped by an edit that does not send it;
            // removing it on purpose is refused like a new trip without a route. Legacy trips without a route are unchanged.
            if (!array_key_exists('client_route_id', $d) && $pre['client_route_id']) $d['client_route_id'] = (string)$pre['client_route_id'];
            must(!$pre['client_route_id'] || trim((string)$d['client_route_id']) !== '', 'Choose an approved client route for the trip; it cannot be removed.');
            $prepared = self::distanceColumns($u, $d, self::deliveryFields($d, false, (string)$pre['scheduled_at']), (int)$pre['client_id'], $pre['truck_type'], $pre);
            must($prepared[0]['distance_km'] !== null && $prepared[0]['distance_km'] > 0, 'Enter the billable distance in km (pickup point to delivery point).');
        }
        return transaction(function (PDO $p) use ($u, $d, $id, $prepared) {
            $t = self::lockTrip($p, $id);
            // CHECKLIST_27_TRIP_DETAILS_LOCK_V1: booking and delivery fields are
            // immutable once a truck is dispatched. A rescheduled attempt gets a
            // new editable pre-dispatch phase; completion/incident/arrival use
            // their separate workflow actions and remain available.
            self::mustBeStatus($t, ['Awaiting Resources', 'Pre-Assigned', 'Ready for Dispatch', 'For Reschedule'], 'edit the delivery / booking details');
            $geometry = false; $distNote = '';
            if (hasRole($u, 'operations_head')) {
                self::mustBeStatus($t, ['Awaiting Resources', 'Pre-Assigned', 'Ready for Dispatch', 'For Reschedule'], 'edit the delivery details');
                [$row, $geometry, $distNote] = $prepared;
            } else {
                self::mustBeTripDispatcher($u, $t);
                $row = ['booking_reference' => optField($d, 'booking_reference', 150), 'units_planned' => intField($d, 'units_planned', 1, false, 100),
                        'container_number' => ($x = optField($d, 'container_number', 60)) !== null ? strtoupper($x) : null,
                        'container_size' => optField($d, 'container_size', 20), 'seal_number' => optField($d, 'seal_number', 60),
                        'shipment_number' => optField($d, 'shipment_number', 60), 'delivery_details' => optField($d, 'delivery_details', 500)];
            }
            $cols = implode(',', array_map(fn($c) => "$c=?", array_keys($row)));
            run($p, "UPDATE trips SET $cols WHERE id=?", array_merge(array_values($row), [$id]));
            self::saveGeometry($p, $id, $geometry);
            // Audit only operational fields; omit free-text/identifiers that may contain private data.
            $labels = ['scheduled_at' => 'Scheduled at', 'pickup_point' => 'Pickup point',
                'delivery_point' => 'Delivery point', 'truck_type' => 'Truck type',
                'distance_km' => 'Distance (km)', 'units_planned' => 'Units planned',
                'distance_source' => 'Distance source', 'route_option' => 'Route option', 'client_route_id' => 'Client route'];
            $oldFields = []; $newFields = [];
            foreach ($labels as $key => $label) if (array_key_exists($key, $row)) {
                $oldFields[$label] = $t[$key] ?? null;
                $newFields[$label] = $row[$key];
            }
            self::event($p, $u['id'], $id, 'trip details updated', $t['status'], $t['status'], '', $oldFields, $newFields);
            $recheck = self::revalidateChecklistAfterScheduleChange($p, $u, $t, $row);
            return ['message' => 'Trip details updated.' . self::$scheduleWarning . $distNote . $recheck];
        });
    }

    /** Apply a Dispatcher-selected Truck and its server-derived fixed Driver. */
    private static function applyAssignment(PDO $p, ?int $uid, array $t, array $res, string $mode, ?string $keepStatus = null): void {
        $oldVehicle = $t['vehicle_id'] ? (int)$t['vehicle_id'] : null;
        FleetState::hold($p, (int)$t['id'], $res['vehicle_id'], ['driver' => $res['driver_id'], 'driver2' => $res['driver2_id'], 'helper' => $res['helper_id']]);
        $to = $keepStatus ?? 'Pre-Assigned';
        run($p, 'UPDATE trips SET vehicle_id=?, driver_id=?, driver2_id=?, helper_id=?, assignment_mode=?, status=? WHERE id=?',
            [$res['vehicle_id'], $res['driver_id'], $res['driver2_id'], $res['helper_id'], $mode, $to, $t['id']]);
        FleetState::reconcile($p, $res['vehicle_id'], $uid, 'Assigned to ' . $t['trip_ref']);
        if ($oldVehicle && $oldVehicle !== $res['vehicle_id']) FleetState::reconcile($p, $oldVehicle, $uid, 'Released from ' . $t['trip_ref']);
        $names = many($p, 'SELECT id, full_name FROM personnel WHERE id IN (?,?,?)', [$res['driver_id'], $res['driver2_id'] ?? 0, $res['helper_id'] ?? 0]);
        $nm = array_column($names, 'full_name', 'id');
        self::event($p, $uid, (int)$t['id'], $mode === 'Auto' ? 'system auto-assigned truck and crew' : 'truck and crew assigned manually', $t['status'], $to,
            'Truck ' . $res['plate'] . '; driver ' . ($nm[$res['driver_id']] ?? '') . ($res['driver2_id'] ? '; driver 2 ' . ($nm[$res['driver2_id']] ?? '') : '') . ($res['helper_id'] ? '; helper ' . ($nm[$res['helper_id']] ?? '') : '')
            . (!empty($res['shift_fallback_note']) ? '. ' . $res['shift_fallback_note'] : '') . (!empty($res['helper_substitution_reason']) ? '. Helper substitution: '.$res['helper_substitution_reason'] : ''));
        if (!empty($res['shift_fallback_note'])) {
            audit($p, $uid, 'Trips', 'trip ' . $t['id'], 'crew shift fallback', $t['trip_ref'] . ': ' . $res['shift_fallback_note']);
            Notify::roles($p, ['operations_head'], 'Crew shift fallback', $t['trip_ref'] . ': ' . $res['shift_fallback_note'], 'trips', 'trip-shift-fallback-' . $t['id'] . '-' . $t['attempt_number'] . '-' . $res['driver_id'] . '-' . ($res['driver2_id'] ?? 0) . '-' . ($res['helper_id'] ?? 0));
        }
        if ($to === 'Pre-Assigned') Notify::tripRoles($p, $t, [$t['dispatcher_role']], 'Pre-assigned trip', $t['trip_ref'] . ' is pre-assigned to truck ' . $res['plate'] . '. Submit the fund request and do the pre-dispatch checklist.', 'trips', 'trip-prep-' . $t['id'] . '-' . $t['attempt_number']);
    }

    /** Compatibility hook for resource-release callers; waiting trips require manual selection. */
    public static function assignWaiting(PDO $p, array $u): string {
        // Compatibility for Maintenance/Fleet/completion callers. V4.3 never allocates automatically.
        return '';
    }

    public static function resourceLost(PDO $p, array $u, int $tripId, string $why): string {
        $t = self::lockTrip($p, $tripId);
        if (!in_array($t['status'], ['Pre-Assigned', 'Ready for Dispatch', 'For Reschedule'], true)) return '';
        $freed = FleetState::release($p, $tripId);
        run($p, 'UPDATE trips SET vehicle_id=NULL,driver_id=NULL,driver2_id=NULL,helper_id=NULL,helper_substitution_reason=NULL WHERE id=?', [$tripId]);
        if ($freed) FleetState::reconcile($p, $freed, $u['id'], 'Released from '.$t['trip_ref']);
        $next = $t['status'] === 'For Reschedule' ? 'For Reschedule' : 'Awaiting Resources';
        self::setStatus($p, $u['id'], $t, $next, 'resources released; manual assignment required', $why);
        Notify::tripRoles($p, $t, ['operations_head', $t['dispatcher_role']], 'Trip needs resources', $t['trip_ref'].': '.$why.'. Dispatcher must select another intact fixed pair.', 'trips');
        return 'Trip '.$t['trip_ref'].' requires manual Dispatcher assignment.';
    }

    private static function retryAssign(array $u, array $d): array {
        must(hasRole($u, 'operations_head') || roleIsDispatcher($u['role']), 'Not allowed.', 403);
        throw new BusinessRuleException('Automatic allocation is retired. A Dispatcher must select a Truck and its fixed Driver.', 409);
    }

    /** Ops Head manual override (PDF #11: Ops Head approves truck and driver assignments). */
    /**
     * Crew choices for the Operations Head's manual assignment, labelled for the trip's planning shift:
     * shift, attendance, Absent / Shift mismatch / No shift, availability. The server rechecks everything on save.
     */
    private static function crewOptions(array $u, array $d): array {
        requireRole($u, 'operations_head');
        $p = db();
        $t = one($p, 'SELECT * FROM trips WHERE id=?', [idField($d, 'trip_id')]);
        must((bool)$t, 'Trip not found.', 404);
        $plan = CrewAttendance::planningDay($t);
        $out = [];
        foreach (many($p, "SELECT id FROM personnel WHERE active=1 AND person_type IN ('Driver','Helper') ORDER BY person_type, full_name") as $r) {
            $id = (int)$r['id'];
            $c = CrewAttendance::assignmentCheck($p, $id, $plan);
            $block = FleetState::personBlocker($p, $id, (int)$t['id']);
            $current = in_array($id, array_map('intval', [$t['driver_id'], $t['driver2_id'], $t['helper_id']]), true);
            $labels = [];
            if ($c['absent']) $labels[] = 'Absent';
            if ($block) $labels[] = 'Not available';
            $out[] = ['id' => $id, 'name' => $c['name'], 'type' => $c['type'], 'attendance' => $c['attendance'], 'current' => $current,
                      'labels' => $labels, 'blocker' => $block, 'allowed' => !$c['absent'] && !$block];
        }
        return ['mode' => CrewAttendance::modeName(), 'day_text' => CrewAttendance::dayText($plan), 'crew' => $out];
    }

    private static function dispatcherResources(array $u,array $d): array {
        DispatcherScope::types($u);$p=db();$t=self::lockTrip($p,idField($d,'trip_id'));
        must((bool)$t,'Trip not found.',404);self::mustBeTripDispatcher($u,$t);self::mustBeStatus($t,['Awaiting Resources','For Reschedule'],'assign resources');
        return DispatcherResources::candidates($p,$u,$t)+['trip'=>['id'=>$t['id'],'trip_ref'=>$t['trip_ref'],'truck_type'=>$t['truck_type']]];
    }
    private static function assignResources(array $u,array $d): array {
        DispatcherScope::types($u);$id=idField($d,'trip_id');
        return transaction(function(PDO $p)use($u,$d,$id){$t=self::lockTrip($p,$id);self::mustBeTripDispatcher($u,$t);self::mustBeStatus($t,['Awaiting Resources','For Reschedule'],'assign resources');
            $res=DispatcherResources::choose(DispatcherResources::candidates($p,$u,$t,true),$t,$d);
            self::applyAssignment($p,$u['id'],$t,$res,'Manual',$t['status']==='For Reschedule'?'For Reschedule':null);
            run($p, 'UPDATE trips SET helper_substitution_reason=? WHERE id=?', [$res['helper_substitution_reason'] ?? null, $t['id']]);
            return ['message'=>'Resources assigned to '.$t['trip_ref'].'. '.($t['status']==='For Reschedule'?'Ready for the existing reschedule action.':'Status: Pre-Assigned.')];
        });
    }

    private static function reassign(array $u, array $d): array {
        requireRole($u, 'operations_head');
        throw new BusinessRuleException('Independent Truck/Driver overrides are retired. Use Dispatcher manual fixed-pair assignment.', 409);
    }

    private static function checklistStart(array $u, array $d): array {
        $id = idField($d, 'trip_id');
        $kind = choiceField($d, 'kind', ['Pre-Dispatch', 'Arrival']);
        return transaction(function (PDO $p) use ($u, $id, $kind) {
            $t = self::lockTrip($p, $id);
            self::mustBeTripDispatcher($u, $t);
            if ($kind === 'Pre-Dispatch') { self::mustBeStatus($t, ['Pre-Assigned'], 'do the pre-dispatch checklist'); self::mustBeChecklistWindow($t); }
            else must($t['status'] === 'Completed' && FleetState::awaitingArrival($p, $id), 'The arrival checklist is not pending for this trip.', 409);
            $p->prepare('INSERT IGNORE INTO checklist_starts (trip_id, attempt_number, kind, started_by) VALUES (?,?,?,?)')
              ->execute([$id, $t['attempt_number'], $kind, $u['id']]);
            return self::timerState($p, $t, $kind);
        });
    }

    /** Start time, minimum minutes and seconds left (computed with the database clock). */
    private static function timerState(PDO $p, array $t, string $kind): array {
        $min = (int)Settings::value('inspection_min_minutes');
        $s = one($p, 'SELECT started_at, TIMESTAMPDIFF(SECOND, started_at, NOW()) AS secs FROM checklist_starts WHERE trip_id=? AND attempt_number=? AND kind=?',
                 [$t['id'], $t['attempt_number'], $kind]);
        return ['started_at' => $s['started_at'] ?? null, 'min_minutes' => $min,
                'remaining_seconds' => $s ? max(0, $min * 60 - (int)$s['secs']) : $min * 60];
    }

    /** Refuses a checklist submitted before the minimum duration. Returns the start time. */
    private static function mustRespectTimer(PDO $p, array $t, string $kind): ?string {
        $st = self::timerState($p, $t, $kind);
        if ($st['min_minutes'] <= 0) return $st['started_at'];
        $label = $kind === 'Arrival' ? 'arrival checklist' : 'pre-dispatch checklist';
        must($st['started_at'] !== null, 'Open the ' . $label . ' first. The ' . $st['min_minutes'] . '-minute inspection timer starts when the checklist is opened.', 409);
        $left = (int)$st['remaining_seconds'];
        must($left <= 0, 'The ' . $label . ' must take at least ' . $st['min_minutes'] . ' minute(s) (started ' . substr((string)$st['started_at'], 11, 5)
            . '). You can submit in ' . intdiv($left, 60) . ' min ' . ($left % 60) . ' sec.', 409);
        return $st['started_at'];
    }

    private static function checklist(array $u, array $d): array {
        $id = idField($d, 'trip_id');
        require_once __DIR__ . '/Inspections/Checklist.php';
        $check = Checklist::validate($d);
        $km = intField($d, 'odometer_km', 0, false, 9999999);
        $fuel = optField($d, 'fuel_level', 20);
        $remarks = optField($d, 'remarks', 500);
        return transaction(function (PDO $p) use ($u, $id, $check, $km, $fuel, $remarks) {
            $t = self::lockTrip($p, $id);
            self::mustBeTripDispatcher($u, $t);
            self::mustBeStatus($t, ['Pre-Assigned'], 'do the pre-dispatch checklist');
            self::mustBeChecklistWindow($t);
            $v = one($p, 'SELECT status, plate_number FROM vehicles WHERE id=?', [$t['vehicle_id']]);
            must($v && $v['status'] === 'Assigned', 'The assigned truck is ' . ($v['status'] ?? 'missing') . '.', 409);
            $started = self::mustRespectTimer($p, $t, 'Pre-Dispatch');
            $p->prepare("INSERT INTO inspections (trip_id, vehicle_id, attempt_number, kind, items_json, has_not_ok, odometer_km, fuel_level, remarks, actor_id, started_at, completed_at)
                         VALUES (?,?,?,'Pre-Dispatch',?,?,?,?,?,?,?,NOW())
                         ON DUPLICATE KEY UPDATE vehicle_id=VALUES(vehicle_id), items_json=VALUES(items_json), has_not_ok=VALUES(has_not_ok), odometer_km=VALUES(odometer_km),
                           fuel_level=VALUES(fuel_level), remarks=VALUES(remarks), actor_id=VALUES(actor_id), started_at=VALUES(started_at), completed_at=NOW()")
              ->execute([$id, $t['vehicle_id'], $t['attempt_number'], json_encode($check, JSON_UNESCAPED_UNICODE), $check['has_not_ok'] ? 1 : 0, $km, $fuel, $remarks, $u['id'], $started]);
            if ($check['has_not_ok']) {
                $bad = []; foreach (Checklist::sections() as $s) foreach ($s['items'] as $i => $label) { $k = $s['key'] . '_' . str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT); if ($check['items'][$k]['condition'] === 'NOT OK') $bad[] = $label; }
                self::event($p, $u['id'], $id, 'pre-dispatch checklist has NOT OK items', $t['status'], $t['status'], implode(', ', $bad), [],
                    ['Inspection result' => 'NOT OK', 'NOT OK item count' => count($bad)]);
                Notify::roles($p, ['motorpool'], 'Checklist finding', $t['trip_ref'] . ' / ' . $v['plate_number'] . ' NOT OK: ' . implode(', ', array_slice($bad, 0, 6)), 'maintenance');
                return ['warning' => 'Checklist saved with NOT OK items (' . implode(', ', array_slice($bad, 0, 5)) . '). The trip cannot be Ready for Dispatch until every item is OK. Fix the items and submit the checklist again.'];
            }
            run($p, 'UPDATE trips SET status=? WHERE id=?', ['Ready for Dispatch', $id]);
            self::event($p, $u['id'], $id, 'pre-dispatch checklist completed', $t['status'], 'Ready for Dispatch',
                'All items OK' . ($km ? "; odometer $km" : ''), [], ['Inspection result' => 'All OK']);
            $fund = one($p, 'SELECT status FROM fund_requests WHERE trip_id=? AND attempt_number=?', [$id, $t['attempt_number']]);
            $msg = 'Trip is Ready for Dispatch.';
            if ($fund && $fund['status'] === 'Released') $msg .= ' Fund is Released, so Confirm Dispatch is now enabled.';
            elseif ($fund) $msg .= ' Confirm Dispatch is enabled once the fund is Released (now: ' . $fund['status'] . ').';
            elseif ((int)$t['attempt_number'] === 1) $msg .= ' Submit the fund request; Confirm Dispatch needs a Released fund.';
            else $msg .= ' Confirm Redispatch is now enabled.';
            return ['message' => $msg];
        });
    }

    /**
     * AUTOMATIC DISPATCH VALIDATION (PDF #12 step 8): both must be true
     *   a. Fund Status: Released   b. Trip Status: Ready for Dispatch
     * Redispatch (Second Attempt): a new fund request is optional ("kung applicable");
     * when one exists it must be Released.
     */
    public static function dispatchBlockers(PDO $p, array $t, bool $lock = false): array {
        $why = [];
        if ($t['status'] !== 'Ready for Dispatch') $why[] = 'Trip status is ' . $t['status'] . ' (needs Ready for Dispatch).';
        else {
            // Not status-only: the checklist must exist for the CURRENT attempt and the CURRENTLY assigned truck.
            $c = self::currentChecklist($p, $t);
            if (!$c) $why[] = 'No pre-dispatch checklist for attempt #' . $t['attempt_number'] . ' (the Dispatcher must select a fixed pair or reschedule so the checklist can be done again).';
            elseif ((int)$c['vehicle_id'] !== (int)$t['vehicle_id']) $why[] = 'The pre-dispatch checklist was done for another truck (the Dispatcher must select a fixed pair so the checklist can be done for the assigned truck).';
        }
        $fund = one($p, 'SELECT status FROM fund_requests WHERE trip_id=? AND attempt_number=?', [$t['id'], $t['attempt_number']]);
        if ($fund && $fund['status'] !== 'Released') $why[] = 'Fund status is ' . $fund['status'] . ' (needs Released).';
        if (!$fund && (int)$t['attempt_number'] === 1) $why[] = 'No fund request yet (needs a Released fund).';
        $g = self::timeGates($t);
        if (!$g['dispatch_allowed_now']) $why[] = 'Scheduled dispatch is ' . self::phTime($g['scheduled_at']) . '; Confirm Dispatch opens ' . self::phTime($g['dispatch_opens_at']) . '.';
        // CHECKLIST_42_CREW_COMPLETENESS_V1 (both modes): Car Carrier = 2 different drivers; others = driver + helper.
        foreach (CrewAttendance::crewCompleteness($p, $t) as $c) $why[] = $c;
        // CHECKLIST_42_ATTENDANCE_ENFORCEMENT_V1 (Enforce): attendance for the ACTUAL dispatch time (now), every crew
        // member Present on their own shift, and the existing availability rules (Present never bypasses them).
        if (CrewAttendance::mode() === CrewAttendance::MODE_ENFORCE) {
            foreach (CrewAttendance::dispatchFindings($p, $t, null, $lock)['crew'] as $c) {
                if ($c['problem']) $why[] = $c['problem'];
                elseif ($block = FleetState::personBlocker($p, $c['id'], (int)$t['id'])) $why[] = 'Crew not available: ' . $block . '.';
            }
        }
        if(in_array($t['status'],['Pre-Assigned','Ready for Dispatch','For Reschedule'],true))foreach(DispatcherResources::tripBlockers($p,$t,$lock) as $block)$why[]=$block;
        return $why;
    }

    private static function dispatch(array $u, array $d): array {
        $id = idField($d, 'trip_id');
        return transaction(function (PDO $p) use ($u, $id) {
            $t = self::lockTrip($p, $id);
            self::mustBeTripDispatcher($u, $t);
            $why = self::dispatchBlockers($p, $t, true);
            must(!$why, 'Confirm Dispatch is not enabled yet. ' . implode(' ', $why), 409);
            // Warn mode: dispatch goes ahead; unconfirmed attendance is recorded with the dispatch (never "verified").
            $attNote = '';
            if (CrewAttendance::mode() === CrewAttendance::MODE_WARN) {
                $att = CrewAttendance::dispatchFindings($p, $t, null, true);
                $issues = array_values(array_filter(array_column($att['crew'], 'problem')));
                if ($issues) $attNote = 'Attendance not confirmed (Warn mode): ' . implode(' ', $issues);
            }
            $v = one($p, 'SELECT status, plate_number FROM vehicles WHERE id=? FOR UPDATE', [$t['vehicle_id']]);
            must($v && $v['status'] === 'Assigned', 'The truck is ' . ($v['status'] ?? 'missing') . '.', 409);
            $redispatch = (int)$t['attempt_number'] > 1;
            $late = self::timeGates($t)['late_minutes'];
            $lateNote = $late > 0 ? 'Dispatched ' . self::durationText($late) . ' after the scheduled time (' . substr((string)$t['scheduled_at'], 0, 16) . ').' : '';
            run($p, "UPDATE trips SET status='Dispatched', dispatched_at=NOW(), dispatcher_id=? WHERE id=?", [$u['id'], $id]);
            self::event($p, $u['id'], $id, $redispatch ? 'redispatch confirmed (Second Attempt)' : 'dispatch confirmed', $t['status'], 'Dispatched',
                trim(($redispatch ? 'Attempt #' . $t['attempt_number'] . ', linked to the original trip and incident report. ' : '') . $lateNote . ($attNote !== '' ? ' ' . $attNote : '')));
            FleetState::reconcile($p, (int)$t['vehicle_id'], $u['id'], 'Dispatched ' . $t['trip_ref']);
            BillingDraft::createFor($p, $t);
            Notify::resolve($p, 'trip-prep-' . $id . '-*');
            Notify::roles($p, ['operations_head', 'finance', 'billing'], $redispatch ? 'Trip redispatched' : 'Trip dispatched', $t['trip_ref'] . ' left with truck ' . $v['plate_number'] . '.', 'trips');
            return ['message' => ($redispatch ? 'Redispatch confirmed (Second Attempt).' : 'Dispatch confirmed.') . ' The billing draft was generated.' . ($lateNote !== '' ? ' Note: ' . $lateNote : '') . ($attNote !== '' ? ' ' . $attNote : '')];
        });
    }

    /** INCIDENT REPORT (PDF #12 Scenario C steps 1-2; Incident Report Form of PDF #13). */
    private static function incident(array $u, array $d): array {
        $id = idField($d, 'trip_id');
        $row = ['incident_type' => choiceField($d, 'incident_type', self::INCIDENT_TYPES),
                'incident_at' => dateTimeField($d, 'incident_at'),
                'location' => field($d, 'location', 200),
                'persons_involved' => optField($d, 'persons_involved', 255),
                'reason' => optField($d, 'reason', 255),
                'problem' => field($d, 'problem', 255),
                'details' => field($d, 'details', 2000)];
        must(strtotime($row['incident_at']) <= time() + 300, 'The incident date and time cannot be in the future.');
        return transaction(function (PDO $p) use ($u, $id, $row) {
            $t = self::lockTrip($p, $id);
            self::mustBeTripDispatcher($u, $t);
            self::mustBeStatus($t, ['Dispatched', 'In Transit'], 'report an incident');
            must(strtotime($row['incident_at']) >= strtotime((string)$t['dispatched_at']) - 60, 'The incident time is before the dispatch time.');
            $iid = insert($p, 'incidents', ['incident_ref' => 'TMP-' . bin2hex(random_bytes(6)), 'trip_id' => $id, 'vehicle_id' => $t['vehicle_id'],
                'attempt_number' => $t['attempt_number'], 'status' => 'Reported', 'reported_by' => $u['id']] + $row);
            $iref = refNumber('INC', $iid);
            run($p, 'UPDATE incidents SET incident_ref=? WHERE id=?', [$iref, $iid]);
            Files::store($p, $u, 'photos', 'incident', $iid, 'Incident Photo');
            Files::store($p, $u, 'attachments', 'incident', $iid, 'Incident Document');
            // Appears at once in the Maintenance module (PDF #12: "magrereflect sa Maintenance Module").
            $jid = insert($p, 'maintenance_jobs', ['job_ref' => 'TMP-' . bin2hex(random_bytes(6)), 'vehicle_id' => $t['vehicle_id'], 'source' => 'Incident',
                'incident_id' => $iid, 'trip_id' => $id, 'problem' => cut($row['problem'] . ' @ ' . $row['location'], 500), 'status' => 'Reported',
                'reported_on' => substr($row['incident_at'], 0, 10), 'priority' => 'High', 'created_by' => $u['id']]);
            run($p, 'INSERT INTO maintenance_events (maintenance_job_id, from_status, to_status, details, actor_id) VALUES (?,?,?,?,?)', [$jid, null, 'Reported', 'from incident ' . $iref, $u['id']]);
            run($p, 'UPDATE maintenance_jobs SET job_ref=? WHERE id=?', [refNumber('JOB', $jid), $jid]);
            self::setStatus($p, $u['id'], $t, 'On Hold - Incident Reported', 'incident reported', $iref . ': ' . $row['problem'] . ' @ ' . $row['location']);
            $plate = one($p, 'SELECT plate_number FROM vehicles WHERE id=?', [$t['vehicle_id']])['plate_number'];
            Notify::roles($p, ['motorpool'], 'Incident reported', "$iref on $plate (" . $t['trip_ref'] . '): ' . $row['problem'] . ' @ ' . $row['location'] . '. Please assess (Minor or Major).', 'maintenance', 'incident-assess-' . $iid);
            Notify::roles($p, ['operations_head'], 'Incident reported', "$iref on $plate (" . $t['trip_ref'] . '). Trip is On Hold.', 'trips');
            return ['incident_id' => $iid, 'message' => "Incident $iref reported. The trip is On Hold while Maintenance assesses the damage."];
        });
    }

    /** Scenario C.1 step 7: Resume Trip after a minor repair (In Transit, NOT a second attempt). */
    private static function resume(array $u, array $d): array {
        $id = idField($d, 'trip_id');
        return transaction(function (PDO $p) use ($u, $id) {
            $t = self::lockTrip($p, $id);
            self::mustBeTripDispatcher($u, $t);
            self::mustBeStatus($t, ['On Hold - Incident Reported'], 'resume the trip');
            $inc = one($p, 'SELECT * FROM incidents WHERE trip_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE', [$id]);
            // INCIDENT_CLEARANCE_V1: only Motorpool's recorded Fit to Continue for the trip's CURRENT incident, attempt and truck.
            must($inc && $inc['status'] === 'Repaired - Cleared to Continue' && $inc['clearance'] === 'Fit to Continue',
                'Wait for Motorpool to record Fit to Continue for the current incident.', 409);
            must((int)$inc['attempt_number'] === (int)$t['attempt_number'] && (int)$inc['vehicle_id'] === (int)$t['vehicle_id'],
                'The recorded clearance is for another attempt or truck of this trip.', 409);
            run($p, "UPDATE incidents SET status='Resolved', resolved_at=NOW(), resumed_by=?, resumed_at=NOW() WHERE id=?", [$u['id'], $inc['id']]);
            self::setStatus($p, $u['id'], $t, 'In Transit', 'trip resumed after minor repair', 'Continuation of the original trip (not a second attempt).');
            FleetState::reconcile($p, (int)$t['vehicle_id'], $u['id'], 'Resumed ' . $t['trip_ref']);
            Notify::roles($p, ['operations_head'], 'Trip resumed', $t['trip_ref'] . ' resumed after a minor repair.', 'trips');
            Notify::resolve($p, 'trip-resume-' . $inc['id']);
            return ['message' => 'Trip resumed. Status: In Transit.'];
        });
    }

    /**
     * INCIDENT_CLEARANCE_V1: Operations Head authorizes For Reschedule when Motorpool recorded Not Fit to Continue on the
     * trip's CURRENT Minor incident. The Minor classification and incident history stay; the repair continues and the
     * truck shows Under Maintenance (FleetState). The responsible Dispatcher reschedules after the verified repair
     * (existing reschedule step), or selects another eligible fixed pair.
     */
    private static function incidentReschedule(array $u, array $d): array {
        requireRole($u, 'operations_head');
        $id = idField($d, 'trip_id');
        $reason = field($d, 'reason', 300);
        return transaction(function (PDO $p) use ($u, $id, $reason) {
            $t = self::lockTrip($p, $id);
            self::mustBeStatus($t, ['On Hold - Incident Reported'], 'move the trip to For Reschedule');
            $inc = one($p, 'SELECT * FROM incidents WHERE trip_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE', [$id]);
            must($inc && (int)$inc['attempt_number'] === (int)$t['attempt_number'] && (int)$inc['vehicle_id'] === (int)$t['vehicle_id'], 'The trip has no current incident for this attempt and truck.', 409);
            must($inc['severity'] === 'Minor' && $inc['clearance'] === 'Not Fit to Continue',
                'For Reschedule from On Hold needs Motorpool\'s Not Fit to Continue on the current Minor incident (Major damage is rescheduled by the assessment).', 409);
            self::setStatus($p, $u['id'], $t, 'For Reschedule', 'not fit to continue: rescheduling authorized by the Operations Head',
                $inc['incident_ref'] . ' (Minor, Not Fit to Continue): ' . $reason);
            $now = FleetState::reconcile($p, (int)$t['vehicle_id'], $u['id'], 'Not fit to continue ' . $inc['incident_ref']);
            audit($p, $u['id'], 'Trips', 'trip ' . $id, 'for reschedule after not fit', $t['trip_ref'] . ' / ' . $inc['incident_ref'] . ': ' . $reason);
            Notify::tripRoles($p, $t, [$t['dispatcher_role']], 'Trip for reschedule', $t['trip_ref'] . ' is For Reschedule (truck not fit to continue, ' . $inc['incident_ref'] . '). Reschedule after the repair is verified, or select another eligible fixed pair.', 'trips', 'trip-reschedule-' . $inc['id']);
            return ['message' => $t['trip_ref'] . ' is For Reschedule. Truck is ' . $now . '; the Minor classification and incident history are kept.'];
        });
    }

    /**
     * Scenario C.2 step 7: Reschedule Trip after the major repair (or with another truck).
     * New schedule, pre-dispatch checklist again (fund request if applicable);
     * Confirm Redispatch records the Second Attempt.
     */
    private static function reschedule(array $u, array $d): array {
        $id = idField($d, 'trip_id');
        $when = futureDateTimeField($d, 'scheduled_at');
        return transaction(function (PDO $p) use ($u, $id, $when) {
            $t = self::lockTrip($p, $id);
            self::mustBeTripDispatcher($u, $t);
            self::mustBeStatus($t, ['For Reschedule'], 'reschedule');
            if (!$t['vehicle_id']) {
                $next = (int)$t['attempt_number'] + 1;
                run($p, "UPDATE trips SET attempt_number=?,status='Awaiting Resources',scheduled_at=? WHERE id=?", [$next,$when,$id]);
                self::event($p,$u['id'],$id,'trip rescheduled; manual assignment required','For Reschedule','Awaiting Resources','Attempt #'.$next.'; select an eligible fixed pair.');
                return ['message'=>'Trip rescheduled. Select a Truck and fixed Driver before pre-dispatch.'];
            }
            $v = one($p, 'SELECT status, plate_number FROM vehicles WHERE id=? FOR UPDATE', [$t['vehicle_id']]);
            must($v && $v['status'] === 'Assigned', 'Truck ' . ($v['plate_number'] ?? '') . ' is still ' . ($v['status'] ?? 'missing') . '. Maintenance must confirm the repair first, or the Dispatcher can select another eligible fixed pair.', 409);
            $next = (int)$t['attempt_number'] + 1;
            run($p, "UPDATE trips SET attempt_number=?, status='Pre-Assigned', scheduled_at=? WHERE id=?", [$next, $when, $id]);
            self::event($p, $u['id'], $id, 'trip rescheduled', 'For Reschedule', 'Pre-Assigned', 'Attempt #' . $next . ' on ' . $when . ' with truck ' . $v['plate_number'] . '. Pre-dispatch checklist again; Confirm Redispatch records the Second Attempt.');
            Notify::roles($p, ['operations_head'], 'Trip rescheduled', $t['trip_ref'] . ' rescheduled to ' . $when . '.', 'trips');
            foreach (many($p, 'SELECT id FROM incidents WHERE trip_id=?', [$id]) as $ii) Notify::resolve($p, 'trip-reschedule-' . $ii['id']);
            return ['message' => 'Trip rescheduled (attempt #' . $next . '). Do the pre-dispatch checklist again, then Confirm Redispatch.'];
        });
    }

    /**
     * CHECKLIST_44_DIESEL_PO_SOA_ATTACHMENTS_V1: the trip's dispatcher attaches the Diesel PO (PDF or photo) from Trip
     * Details. Stored with the secure document system (type / size checked, private storage, audit) and linked to the
     * trip; visible in Trip Details and Documents (Finance can view and download trip documents). Attachment only.
     */
    private static function attachDieselPo(array $u, array $d): array {
        $id = idField($d, 'trip_id');
        return transaction(function (PDO $p) use ($u, $id) {
            $t = self::lockTrip($p, $id);
            self::mustBeTripDispatcher($u, $t);
            self::mustBeStatus($t, self::DIESEL_PO_STATUSES, 'attach the Diesel PO');
            $ids = Files::store($p, $u, 'diesel_po', 'trip', $id, self::DOC_DIESEL_PO, true, 'the Diesel PO file');
            self::event($p, $u['id'], $id, 'diesel PO attached', $t['status'], $t['status'], count($ids) . ' file(s)');
            return ['message' => 'Diesel PO attached to ' . $t['trip_ref'] . ' (' . count($ids) . ' file(s)).'];
        });
    }

    /**
     * TRIP COMPLETION (PDF #12 step 11): completion date/time + required supporting
     * documents (waybill, acknowledgement receipt and delivery receipt). The system refuses
     * completion while anything is missing and says what is missing.
     */
    private static function progress(array $u,array $d): array {
        must(roleIsDispatcher($u['role']),'Only Dispatchers record progress.',403);
        $id=idField($d,'trip_id');$kind=choiceField($d,'update_type',self::UPDATE_TYPES);
        $note=field($d,'note',500);$cause=$kind==='Delay'?choiceField($d,'delay_cause',self::DELAY_CAUSES):null;
        return transaction(function(PDO $p)use($u,$id,$kind,$note,$cause){
            $t=self::lockTrip($p,$id);self::mustBeTripDispatcher($u,$t);self::mustBeStatus($t,['Dispatched','In Transit','On Hold - Incident Reported'],'record progress');
            $key=insert($p,'trip_updates',['trip_id'=>$id,'actor_id'=>$u['id'],'update_type'=>$kind,'delay_cause'=>$cause,'note'=>$note]);
            self::event($p,$u['id'],$id,'progress recorded',$t['status'],$t['status'],$kind.': '.$note);
            return ['message'=>'Progress recorded.','update_id'=>$key];
        });
    }

    private static function complete(array $u, array $d): array {
        $id = idField($d, 'trip_id');
        $when = dateTimeField($d, 'completed_at');
        $waybill = strtoupper(field($d, 'waybill_number', 100));
        $units = intField($d, 'units_delivered', 0, false, 100);
        $notes = optField($d, 'delivery_notes', 500);
        return transaction(function (PDO $p) use ($u, $id, $when, $waybill, $units, $notes) {
            $t = self::lockTrip($p, $id);
            self::mustBeTripDispatcher($u, $t);
            self::mustBeStatus($t, ['Dispatched', 'In Transit'], 'complete the trip');
            must(strtotime($when) <= time() + 300, 'The completion date and time cannot be in the future.');
            must(strtotime($when) >= strtotime(substr((string)$t['dispatched_at'], 0, 16)), 'The completion time is before the dispatch time (' . substr((string)$t['dispatched_at'], 0, 16) . ').');
            if ($t['truck_type'] === 'Car Carrier') must($units !== null && $units > 0, 'Enter the number of units delivered.');
            Files::store($p, $u, 'waybill', 'trip', $id, self::DOC_WAYBILL);
            Files::store($p, $u, 'ack_receipt', 'trip', $id, self::DOC_AR);
            Files::store($p, $u, 'delivery_receipt', 'trip', $id, self::DOC_DR);
            Files::store($p, $u, 'other', 'trip', $id, 'Other Trip Document');
            $soa = Files::store($p, $u, 'supplier_soa', 'trip', $id, self::DOC_SUPPLIER_SOA);   // optional (#44)
            $missing = [];
            if (!Files::count($p, 'trip', $id, self::DOC_WAYBILL)) $missing[] = 'waybill file';
            if (!Files::count($p, 'trip', $id, self::DOC_AR)) $missing[] = 'acknowledgement receipt file';
            if (!Files::count($p, 'trip', $id, self::DOC_DR)) $missing[] = 'delivery receipt (DR) file';
            must(!$missing, 'Trip cannot be completed yet. Missing: ' . implode(', ', $missing) . '.', 422);
            run($p, "UPDATE trips SET status='Completed', completed_at=?, waybill_number=?, units_delivered=?, delivery_notes=? WHERE id=?",
                [$when, $waybill, $units, $notes, $id]);
            self::event($p, $u['id'], $id, 'trip completed', $t['status'], 'Completed', 'Completed ' . $when . '; waybill ' . $waybill . ($soa ? '; supplier SOA attached (' . count($soa) . ')' : ''));
            // Delivery completion is not vehicle return. Retain truck and personnel holds;
            // the existing arrival transaction releases them after the inspection is recorded.
            FleetState::reconcile($p, (int)$t['vehicle_id'], $u['id'], 'Trip ' . $t['trip_ref'] . ' completed, truck returning');
            BillingDraft::forBilling($p, $id);
            Notify::roles($p, ['billing'], 'For billing', $t['trip_ref'] . ' is completed. Verify the documents and finalize the billing.', 'billing', 'billing-verify-' . $id);
            Notify::roles($p, ['operations_head'], 'Trip completed', $t['trip_ref'] . ' completed. The truck is returning (arrival checklist pending).', 'trips');
            Notify::tripRoles($p, $t, [$t['dispatcher_role']], 'Arrival checklist', $t['trip_ref'] . ': do the arrival checklist when the truck is back.', 'trips', 'trip-arrival-' . $id);
            $auto = self::assignWaiting($p, $u);
            return ['message' => 'Trip completed. The billing record moved to For Billing. The truck and assigned personnel remain held until you submit the arrival checklist.' . ($auto ? ' ' . $auto : '')];
        });
    }

    /**
     * ARRIVAL CHECKLIST (PDF #11 section G; PDF #11 Car Carrier "Complete the arrival checklist").
     * Done by the trip's dispatcher when the truck is back after a Completed trip.
     * Same 57 items as the pre-dispatch checklist so the condition before departure and
     * upon arrival can be compared item by item.
     *   All OK  -> the truck is released and becomes Available.
     *   NOT OK  -> photos and a description are required; a corrective maintenance record is
     *              opened (or the findings are added to the truck's open record) and the
     *              truck becomes Under Maintenance.
     */
    private static function arrival(array $u, array $d): array {
        $id = idField($d, 'trip_id');
        require_once __DIR__ . '/Inspections/Checklist.php';
        $check = Checklist::validate($d);
        $km = intField($d, 'odometer_km', 0, false, 9999999);
        $fuel = choiceField($d, 'fuel_level', self::FUEL_LEVELS);
        $remarks = optField($d, 'remarks', 500);
        $damage = optField($d, 'damage_notes', 1000);
        if ($check['has_not_ok']) must($damage !== null, 'Describe the damage, defects or missing equipment of the NOT OK items.');
        return transaction(function (PDO $p) use ($u, $id, $check, $km, $fuel, $remarks, $damage) {
            $t = self::lockTrip($p, $id);
            self::mustBeTripDispatcher($u, $t);
            self::mustBeStatus($t, ['Completed'], 'do the arrival checklist');
            must(FleetState::awaitingArrival($p, $id), 'The arrival checklist of ' . $t['trip_ref'] . ' is already done.', 409);
            $started = self::mustRespectTimer($p, $t, 'Arrival');
            $vid = (int)$t['vehicle_id'];
            $plate = one($p, 'SELECT plate_number FROM vehicles WHERE id=? FOR UPDATE', [$vid])['plate_number'];

            // Compare with the pre-dispatch checklist of the same attempt.
            $pre = one($p, "SELECT items_json, odometer_km, fuel_level FROM inspections WHERE trip_id=? AND attempt_number=? AND kind='Pre-Dispatch'", [$id, $t['attempt_number']]);
            if ($km && $pre && $pre['odometer_km']) must($km >= (int)$pre['odometer_km'], 'The arrival odometer (' . $km . ' km) is lower than at departure (' . $pre['odometer_km'] . ' km).');
            $before = $pre ? (json_decode((string)$pre['items_json'], true)['items'] ?? []) : [];
            $bad = []; $changed = [];
            foreach (Checklist::sections() as $s) foreach ($s['items'] as $i => $label) {
                $k = $s['key'] . '_' . str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT);
                if ($check['items'][$k]['condition'] !== 'NOT OK') continue;
                $bad[] = $label . ($check['items'][$k]['remarks'] !== '' ? ' (' . $check['items'][$k]['remarks'] . ')' : '');
                if (($before[$k]['condition'] ?? null) === 'OK') $changed[] = $label;
            }
            Files::store($p, $u, 'photos', 'trip', $id, self::DOC_ARRIVAL_PHOTO, (bool)$bad, 'photos of the NOT OK items (damage, defects or missing equipment)');

            $jobId = null; $jobRef = null;
            if ($bad) {
                $problem = cut('Arrival checklist ' . $t['trip_ref'] . ' - NOT OK: ' . implode('; ', $bad) . '. ' . $damage, 500);
                $open = FleetState::openJob($p, $vid);
                if ($open) {
                    // One open record per truck: add the findings to it.
                    $jobId = (int)$open['id']; $jobRef = $open['job_ref'];
                    run($p, 'INSERT INTO maintenance_events (maintenance_job_id, from_status, to_status, details, actor_id) VALUES (?,?,?,?,?)',
                        [$jobId, $open['status'], $open['status'], cut('Arrival checklist findings: ' . $problem, 500), $u['id']]);
                } else {
                    $jobId = insert($p, 'maintenance_jobs', ['job_ref' => 'TMP-' . bin2hex(random_bytes(6)), 'vehicle_id' => $vid, 'source' => 'On-Premise',
                        'trip_id' => $id, 'problem' => $problem, 'reported_on' => date('Y-m-d'), 'priority' => 'Normal', 'status' => 'Reported',
                        'remarks' => cut('From the arrival checklist of ' . $t['trip_ref'], 500), 'created_by' => $u['id']]);
                    $jobRef = refNumber('CM', $jobId);
                    run($p, 'UPDATE maintenance_jobs SET job_ref=? WHERE id=?', [$jobRef, $jobId]);
                    run($p, 'INSERT INTO maintenance_events (maintenance_job_id, from_status, to_status, details, actor_id) VALUES (?,?,?,?,?)',
                        [$jobId, null, 'Reported', cut('from the arrival checklist of ' . $t['trip_ref'], 500), $u['id']]);
                }
            }
            $p->prepare("INSERT INTO inspections (trip_id, vehicle_id, attempt_number, kind, items_json, has_not_ok, odometer_km, fuel_level, remarks, damage_notes, maintenance_job_id, actor_id, started_at, completed_at)
                         VALUES (?,?,?,'Arrival',?,?,?,?,?,?,?,?,?,NOW())")
              ->execute([$id, $vid, $t['attempt_number'], json_encode($check, JSON_UNESCAPED_UNICODE), $bad ? 1 : 0, $km, $fuel, $remarks, $damage, $jobId, $u['id'], $started]);

            FleetState::release($p, $id);
            $truck = FleetState::reconcile($p, $vid, $u['id'], 'Arrival checklist ' . $t['trip_ref'] . ($bad ? ' (NOT OK items, ' . $jobRef . ')' : ' (all OK)'));
            $summary = ($bad ? count($bad) . ' NOT OK item(s): ' . implode('; ', $bad) : 'All items OK') . ($km ? "; odometer $km" : '') . "; fuel $fuel"
                     . ($changed ? '. Changed since departure: ' . implode(', ', $changed) : '');
            self::event($p, $u['id'], $id, 'arrival checklist completed', 'Completed', 'Completed', cut($summary, 500), [],
                ['Inspection result' => $bad ? 'NOT OK' : 'All OK', 'NOT OK item count' => count($bad)]);
            Notify::resolve($p, 'trip-arrival-' . $id);
            if ($bad) {
                Notify::roles($p, ['motorpool'], 'Arrival checklist finding', "$plate back from " . $t['trip_ref'] . ' with NOT OK items (' . $jobRef . '). Assign a mechanic.', 'maintenance', 'job-open-' . $jobId);
                Notify::roles($p, ['operations_head'], 'Truck under maintenance', "$plate came back from " . $t['trip_ref'] . " with NOT OK items ($jobRef). It cannot be assigned until the repair is verified.", 'maintenance');
                return ['warning' => 'Arrival checklist saved. NOT OK items were found, so ' . $jobRef . ' was ' . ($open ? 'updated' : 'opened') . ' in Corrective Maintenance. Truck ' . $plate . ' is now ' . $truck . '.'];
            }
            $auto = self::assignWaiting($p, $u);
            return ['message' => 'Arrival checklist saved (all OK). Truck ' . $plate . ' is now ' . $truck . '.' . ($auto ? ' ' . $auto : '')];
        });
    }

    /** Ops Head cancels a trip that is not on the road. */
    private static function cancel(array $u, array $d): array {
        requireRole($u, 'operations_head');
        $id = idField($d, 'trip_id'); $reason = field($d, 'reason', 300);
        return transaction(function (PDO $p) use ($u, $id, $reason) {
            $t = self::lockTrip($p, $id);
            must($t['status'] !== 'Pending Review', 'Historical trip requests are read-only.', 409);
            self::mustBeStatus($t, self::BEFORE_DISPATCH, 'cancel');
            run($p, 'UPDATE trips SET cancel_reason=? WHERE id=?', [$reason, $id]);
            self::setStatus($p, $u['id'], $t, 'Cancelled', 'trip cancelled', $reason);
            Notify::resolve($p, 'trip-review-' . $id); Notify::resolve($p, 'trip-prep-' . $id . '-*');
            $freed = FleetState::release($p, $id);
            if ($freed) FleetState::reconcile($p, $freed, $u['id'], 'Released by cancelled ' . $t['trip_ref']);
            BillingDraft::cancel($p, $id);
            $msg = 'Trip cancelled.';
            foreach (many($p, "SELECT * FROM fund_requests WHERE trip_id=? AND status IN ('Pending Approval','Ready for Release','Rejected','Released') FOR UPDATE", [$id]) as $f) {
                if ($f['status'] === 'Released') {
                    Notify::roles($p, ['finance'], 'Liquidation needed', $t['trip_ref'] . ' was cancelled after fund ' . $f['fund_ref'] . ' was released. Record the liquidation (returned cash).', 'funds');
                    $msg .= ' The fund was already released; Finance was asked to record the liquidation.';
                } else {
                    run($p, "UPDATE fund_requests SET status='Cancelled' WHERE id=?", [$f['id']]);
                    audit($p, $u['id'], 'Funds', 'fund ' . $f['id'], 'fund request cancelled (trip cancelled)', $f['fund_ref']);
                }
            }
            Notify::tripRoles($p, $t, [$t['dispatcher_role']], 'Trip cancelled', $t['trip_ref'] . ' was cancelled: ' . $reason, 'trips');
            $auto = self::assignWaiting($p, $u);
            return ['message' => $msg . ($auto ? ' ' . $auto : '')];
        });
    }

    /** Everything about one trip (details drawer). */
    private static function details(array $u, array $d): array {
        must(Access::canOpen($u, 'trips') || Access::canOpen($u, 'funds') || Access::canOpen($u, 'billing'), 'Not allowed.', 403);
        $id = idField($d, 'trip_id'); $p = db();
        [$scope, $args] = self::scope($u);
        $t = one($p, self::select() . " WHERE t.id=? $scope", array_merge([$id], $args));
        must((bool)$t, 'Trip not found.', 404);
        $t['dispatch_blockers'] = self::dispatchBlockers($p, $t);
        $t['time_gates'] = self::timeGates($t);
        if (in_array($t['status'], ['Pre-Assigned', 'Ready for Dispatch', 'For Reschedule'], true)) {
            $att = CrewAttendance::dispatchFindings($p, $t);
            $sched = CrewAttendance::planningShift($t);   // scheduled shift (current shift once overdue)
            foreach ($att['crew'] as &$c) $c['outside_scheduled_shift'] = false;
            unset($c);
            $t['crew_attendance'] = ['mode' => CrewAttendance::modeName(), 'dispatch_shift' => CrewAttendance::shiftText($att['shift']),
                'scheduled_shift' => CrewAttendance::shiftText($sched), 'crew' => $att['crew'], 'missing' => CrewAttendance::crewCompleteness($p, $t)];
        }
        core('TripPricing');$pricingBill=one($p,'SELECT id FROM billings WHERE trip_id=?',[$t['id']]);$t['pricing_estimate']=$pricingBill?TripPricing::saved($p,(int)$pricingBill['id']):TripPricing::quote($p,$t,date('Y-m-d H:i:s'));
        if($t['pricing_estimate']===null)$t['pricing_estimate']=['fuel'=>null,'missing'=>['Legacy billing; historical amounts preserved']];
        $t['fuel_estimate']=$t['pricing_estimate']['fuel'];
        $t['route_geometry'] = one($p, 'SELECT geometry FROM trip_routes WHERE trip_id=?', [$id])['geometry'] ?? null;
        $t['client_route_ref'] = $t['client_route_id'] ? (one($p, 'SELECT route_ref FROM client_routes WHERE id=?', [$t['client_route_id']])['route_ref'] ?? null) : null;
        $funds = many($p, 'SELECT f.*, ru.full_name AS requested_by_name, au.full_name AS approved_by_name, lu.full_name AS released_by_name
            FROM fund_requests f JOIN users ru ON ru.id=f.requested_by LEFT JOIN users au ON au.id=f.approved_by LEFT JOIN users lu ON lu.id=f.released_by WHERE f.trip_id=? ORDER BY f.id', [$id]);
        foreach ($funds as &$f) $f['lines'] = many($p, 'SELECT category_name, amount, is_preset FROM fund_request_lines WHERE fund_request_id=? ORDER BY id', [$f['id']]);
        unset($f);
        $incidents = many($p, 'SELECT i.*, ru.full_name AS reported_by_name, au.full_name AS assessed_by_name, cu.full_name AS cleared_by_name, su.full_name AS resumed_by_name, m.job_ref, m.status AS job_status FROM incidents i JOIN users ru ON ru.id=i.reported_by LEFT JOIN users au ON au.id=i.assessed_by LEFT JOIN users cu ON cu.id=i.cleared_by LEFT JOIN users su ON su.id=i.resumed_by LEFT JOIN maintenance_jobs m ON m.incident_id=i.id WHERE i.trip_id=? ORDER BY i.id', [$id]);
        $docs = Files::of($p, 'trip', $id);
        foreach ($incidents as $i) foreach (Files::of($p, 'incident', (int)$i['id']) as $doc) $docs[] = $doc + ['for' => $i['incident_ref']];
        return [
            'trip' => $t,
            'events' => many($p, 'SELECT e.*, us.full_name AS actor FROM trip_events e LEFT JOIN users us ON us.id=e.actor_id WHERE e.trip_id=? ORDER BY e.id', [$id]),
            'updates' => many($p, 'SELECT up.*, us.full_name AS actor FROM trip_updates up JOIN users us ON us.id=up.actor_id WHERE up.trip_id=? ORDER BY up.id DESC', [$id]),
            'inspections' => many($p, 'SELECT i.*, us.full_name AS actor, m.job_ref, m.status AS job_status FROM inspections i JOIN users us ON us.id=i.actor_id
                LEFT JOIN maintenance_jobs m ON m.id=i.maintenance_job_id WHERE i.trip_id=? ORDER BY i.attempt_number, i.kind DESC', [$id]),
            'funds' => $funds,
            'incidents' => $incidents,
            'documents' => $docs,
            'billing' => one($p, 'SELECT * FROM billings WHERE trip_id=?', [$id]),
        ];
    }
}
