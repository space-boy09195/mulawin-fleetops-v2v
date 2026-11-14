<?php
/*
 * scripts/showcase/scenarios.php — the connected scenarios of the showcase dataset (all through application actions).
 * Included by populate.php inside the open transaction. Variables from populate.php: $p, $NOW.
 *
 *  Period: Mon 2026-08-03 .. now (Asia/Manila). Week = Monday 00:00 .. Sunday 23:59:59.
 *  Historical (Aug 3 - Nov 12): completed trips with funds, liquidations, billing, invoices and collections; trip incidents
 *  (minor: resumed; major: repaired, rescheduled, redispatched); arrival-checklist findings; garage repairs with costs,
 *  repeat repairs and assisting mechanics; rejected and cancelled trips; payables and expenses.
 *  Current (Nov 12 - now): active, held, waiting and pending trips; open repairs; parts requests, purchase orders,
 *  approvals, deliveries and PO payments within the November purchasing budget; pending management approvals.
 */
declare(strict_types=1);

$NOWR = $NOW - ($NOW % 300) - 600;               // "now" for current scenarios: 10 minutes ago, 5-minute boundary
$TODAY = date('Y-m-d', $NOWR);
$H = fn(string $s) => strtotime($s);
$fmt = fn(int $ts) => date('Y-m-d H:i:s', $ts);
$ODO = [];
$CTR = ['wb' => 48120, 'ar' => 24510];
$U = fn(string $k) => Actors::$u[$k];

// ------------------------------------------------------------------ master data used by the scenarios
$TERMS = [1 => 30, 2 => 30, 3 => 15, 4 => 45, 5 => 30, 6 => 30, 7 => 45, 8 => 30, 9 => 15, 10 => 30, 11 => 30, 12 => 45, 13 => 30];
$RATES = [
    'Car Carrier' => [2 => [118, 15000], 5 => [122, 15500], 8 => [120, 15000], 9 => [115, 14500]],
    'Container' => [1 => [96, 12000], 3 => [92, 11500], 4 => [98, 12500], 6 => [95, 12000], 7 => [97, 12000], 10 => [94, 11800], 11 => [95, 12000], 12 => [99, 12500], 13 => [93, 11800]],
    'Wing Van' => [1 => [84, 9500], 3 => [82, 9200], 6 => [85, 9500], 10 => [83, 9300], 11 => [86, 9800], 13 => [82, 9200]],
];
$ROUTES = [
    'Container' => [['MANILA INTERNATIONAL CONTAINER TERMINAL, TONDO, MANILA', 'CALAMBA, LAGUNA', 56], ['MANILA INTERNATIONAL CONTAINER TERMINAL, TONDO, MANILA', 'CARMONA, CAVITE', 38],
        ['SOUTH HARBOR, PORT AREA, MANILA', 'MEYCAUAYAN, BULACAN', 24], ['SOUTH HARBOR, PORT AREA, MANILA', 'SAN FERNANDO, PAMPANGA', 72], ['BATANGAS PORT, BATANGAS CITY', 'LIPA, BATANGAS', 31],
        ['MANILA INTERNATIONAL CONTAINER TERMINAL, TONDO, MANILA', 'STO. TOMAS, BATANGAS', 74], ['SOUTH HARBOR, PORT AREA, MANILA', 'GENERAL TRIAS, CAVITE', 41]],
    'Wing Van' => [['VALENZUELA CITY', 'TAGUIG CITY', 26], ['PASIG CITY', 'CLARK FREEPORT, PAMPANGA', 92], ['CALAMBA, LAGUNA', 'QUEZON CITY', 58], ['MARILAO, BULACAN', 'MUNTINLUPA CITY', 47],
        ['CABUYAO, LAGUNA', 'CALOOCAN CITY', 63], ['TANAUAN, BATANGAS', 'MAKATI CITY', 68]],
    'Car Carrier' => [['STA. ROSA, LAGUNA', 'PASAY CITY', 41], ['STA. ROSA, LAGUNA', 'QUEZON CITY', 53], ['BATANGAS PORT, BATANGAS CITY', 'MAKATI CITY', 112], ['SUBIC BAY FREEPORT, ZAMBALES', 'CALOOCAN CITY', 128],
        ['STA. ROSA, LAGUNA', 'BACOOR, CAVITE', 31], ['STA. ROSA, LAGUNA', 'ANGELES CITY, PAMPANGA', 121], ['SOUTH HARBOR, PORT AREA, MANILA', 'CEBU-BOUND RORO, BATANGAS PORT', 110]],
];
$CLIENTS_BY_TYPE = array_map(fn($r) => array_keys($r), $RATES);
$DETAILS = ['Container' => ['1x40 HC general cargo', '1x20 STD machine parts', '1x40 HC household appliances', '1x20 STD canned goods'],
    'Wing Van' => ['Palletized consumer goods, 12 pallets', 'Packaged beverages, 14 pallets', 'Boxed electronics, 10 pallets', 'Paper products, 16 pallets'],
    'Car Carrier' => ['Brand-new sedans for dealer delivery', 'Brand-new SUVs for dealer delivery', 'Mixed passenger vehicles for dealer delivery']];

// ------------------------------------------------------------------ helpers bound to the scenario state
$tripOf = fn(int $id) => one($p, 'SELECT * FROM trips WHERE id=?', [$id]);
$fundOf = fn(int $id, int $attempt = 1) => one($p, 'SELECT * FROM fund_requests WHERE trip_id=? AND attempt_number=?', [$id, $attempt]);
$billOf = fn(int $id) => one($p, 'SELECT * FROM billings WHERE trip_id=?', [$id]);
$fundLines = function (string $type, float $km) {
    $toll = max(100, (int)(round($km * 6.5 / 10) * 10));
    if ($type === 'Car Carrier') return ['categories' => ['Driver Meal Allowance', 'Passway'], 'extra' => [['name' => 'Second Driver Meal Allowance', 'amount' => '300.00'], ['name' => 'Expressway Toll', 'amount' => money2($toll)]]];
    $c = ['Driver Meal Allowance', 'Helper Meal Allowance', 'Passway'];
    if (mt_rand(0, 2) === 0) $c[] = 'Forklift Operator';
    if (mt_rand(0, 3) === 0) $c[] = 'Checker';
    return ['categories' => $c, 'extra' => [['name' => 'Expressway Toll', 'amount' => money2($toll)]]];
};
$freeMechanic = function (array $pool, array $exclude = []) use ($p): ?int {
    foreach ($pool as $mid) {
        if (in_array($mid, $exclude, true)) continue;
        $busy = one($p, "SELECT id FROM maintenance_jobs WHERE mechanic_id=? AND status IN ('Reported','Under Inspection','Under Repair')", [$mid])
             ?: one($p, 'SELECT h.id FROM maintenance_job_helpers h JOIN maintenance_jobs j ON j.id=h.maintenance_job_id WHERE h.mechanic_id=? AND h.released_at IS NULL', [$mid]);
        if (!$busy) return $mid;
    }
    return null;
};
$clockIn = function (int $mid) use ($p, $U) {
    if (!one($p, 'SELECT id FROM mechanic_attendance WHERE mechanic_id=? AND time_out IS NULL', [$mid])) act($U('mp'), 'Mechanics', 'time_in', ['id' => $mid]);
};
$clockOutIfFree = function (array $mids) use ($p, $U) {
    foreach ($mids as $mid) {
        if (!one($p, 'SELECT id FROM mechanic_attendance WHERE mechanic_id=? AND time_out IS NULL', [$mid])) continue;
        $busy = one($p, "SELECT id FROM maintenance_jobs WHERE mechanic_id=? AND status IN ('Reported','Under Inspection','Under Repair')", [$mid])
             ?: one($p, 'SELECT h.id FROM maintenance_job_helpers h JOIN maintenance_jobs j ON j.id=h.maintenance_job_id WHERE h.mechanic_id=? AND h.released_at IS NULL', [$mid]);
        if (!$busy) act($U('mp'), 'Mechanics', 'time_out', ['id' => $mid]);
    }
};
$HIST_POOL = [5, 6, 7];   // mechanics with no open attendance session today; their sessions are created per repair

// ------------------------------------------------------------------ 0. actors and client activation (week before the period)
Sim::at('2026-07-27 08:00', 'actors', fn() => Actors::create($p, '2026-07-27 08:00:00'));
foreach (array_keys($TERMS) as $i => $cid) {
    Sim::at($H('2026-07-27 09:00') + $i * 900, "client $cid information", function () use ($p, $cid, $U) {
        $c = one($p, 'SELECT * FROM clients WHERE id=?', [$cid]);
        act($U('ops'), 'Clients', 'update', ['id' => $cid, 'company_name' => $c['company_name'], 'trade_name' => $c['trade_name'], 'nature_of_business' => $c['nature_of_business'],
            'tin' => $c['tin'], 'business_address' => $c['business_address'] ?: 'Metro Manila (address not yet recorded)', 'contact_person' => $c['contact_person'] ?: 'Accounts Department',
            'contact_position' => $c['contact_position'], 'contact_number' => $c['contact_number'] ?: '0000 000 0000', 'email' => $c['email'], 'notes' => $c['notes']]);
        $files = [];
        foreach (Clients::REQUIRED_DOCS as $input => $cat) $files[$input] = [$input === 'signature' ? 'png' : 'pdf', $cat, $c['client_code']];
        act($U('ops'), 'Clients', 'documents', ['id' => $cid], $files);
    });
    Sim::at($H('2026-07-28 10:00') + $i * 900, "client $cid rates", function () use ($cid, $U, $RATES, $TERMS) {
        $rates = [];
        foreach ($RATES as $type => $byClient) if (isset($byClient[$cid])) $rates[] = ['truck_type' => $type, 'rate_per_km' => money2($byClient[$cid][0]), 'minimum_charge' => money2($byClient[$cid][1])];
        act($U('bill'), 'Clients', 'rate_save', ['id' => $cid, 'rates' => $rates, 'payment_terms_days' => $TERMS[$cid]]);
    });
}

// ------------------------------------------------------------------ trip scenario builder
/**
 * $c: type, client, route, D (dispatch ts), path A|B, variant normal|minor|major|notok, billing true|false,
 *     current: stop after a stage (null = full history), mech (current incidents: mechanic id)
 */
$trip = function (array $c) use (&$trip, $p, $U, $fmt, $tripOf, $fundOf, $billOf, $fundLines, $freeMechanic, $clockIn, $clockOutIfFree, $HIST_POOL, &$ODO, &$CTR, $TERMS, $NOWR, $DETAILS): stdClass {
    $S = (object)['id' => 0, 'c' => $c];
    [$from, $to, $km] = $c['route'];
    $type = $c['type']; $D = $c['D']; $stop = $c['stop'] ?? null;
    $dur = 120 + (int)round($km * 1.6) + mt_rand(0, 60);
    $form = fn(array $t = null) => ['client_id' => $c['client'], 'truck_type' => $type, 'pickup_point' => $from, 'delivery_point' => $to,
        'scheduled_at' => $t ? str_replace(' ', 'T', substr((string)$t['scheduled_at'], 0, 16)) : date('Y-m-d\TH:i', time() + 3600 * ($c['sched_hours'] ?? 1)),
        'distance_km' => (string)$km, 'delivery_details' => $DETAILS[$type][array_rand($DETAILS[$type])],
        'booking_reference' => 'BKG-' . date('ymd', $D) . '-' . mt_rand(100, 999),
        'units_planned' => $type === 'Car Carrier' ? (string)mt_rand(4, 7) : '', 'container_size' => $type === 'Container' ? (mt_rand(0, 1) ? '40HC' : '20STD') : ''];
    $live = fn() => $tripOf($S->id);
    // 1. create (A: Operations Head; B: dispatcher request, then review)
    $createAt = $c['created'] ?? ($c['path'] === 'B' ? $D - 3 * 86400 + 3600 : $D - 2 * 86400 + 4 * 3600);
    if ($c['path'] === 'B') {
        Sim::at($createAt, 'trip request', function () use ($S, $type, $form) {
            $r = act(Actors::dispatcher($type, Sim::$cur), 'Trips', 'create', $form());
            $S->id = (int)$r['trip_id'];
        });
        if (($c['variant'] ?? '') === 'rejected') {
            Sim::at($createAt + 3 * 3600, 'trip review reject', fn() => act($U('ops'), 'Trips', 'review', ['trip_id' => $S->id, 'decision' => 'reject', 'reason' => $c['reason']]));
            Sim::at($createAt + 3 * 3600 + 60, 'schedule', fn() => Sim::schedFinal($S->id, $createAt + 3 * 86400, 'requested pickup date of a rejected request'));
            return $S;
        }
        if ($stop !== 'pending_review') Sim::at($createAt + (isset($c['created']) && $createAt > $NOWR - 30 * 3600 ? 3600 : 22 * 3600), 'trip review', function () use ($S, $U, $form, $live) {
            act($U('ops'), 'Trips', 'review', ['trip_id' => $S->id, 'decision' => 'approve'] + $form($live()));
        });
    } else {
        Sim::at($createAt, 'trip create', function () use ($S, $U, $form) { $r = act($U('ops'), 'Trips', 'create', $form()); $S->id = (int)$r['trip_id']; });
    }
    if ($stop === 'pending_review') return $S;
    if ($stop === 'awaiting') return $S;
    $must = function (array $st) use ($S, $live) { $t = $live(); if (!in_array($t['status'], $st, true)) throw new RuntimeException($t['trip_ref'] . ' is ' . $t['status'] . ' (expected ' . implode('/', $st) . ')'); return $t; };
    // 2. fund request and approval
    $fundAt = $c['fund_at'] ?? ($D - 86400 + 4 * 3600);
    if (($c['fund'] ?? true) && $stop !== 'assigned_nofund') {
        Sim::at($fundAt, 'fund submit', function () use ($S, $type, $km, $fundLines, $must) {
            $must(['Pre-Assigned']);
            act(Actors::dispatcher($type, Sim::$cur), 'Funds', 'submit', ['trip_id' => $S->id, 'notes' => 'Trip expenses for ' . $type . ' run'] + $fundLines($type, $km));
        });
        if ($stop !== 'fund_pending') Sim::at($fundAt + 2 * 3600, 'fund approve', fn() => act($U('ops'), 'Funds', 'decide', ['fund_id' => (int)$fundOf($S->id)['id'], 'decision' => 'approve']));
    }
    if (in_array($stop, ['assigned_nofund', 'fund_pending', 'fund_approved'], true)) return $S;
    if (isset($c['cancel_before_checklist'])) {
        Sim::at($fundAt + 5 * 3600, 'trip cancel', fn() => act($U('ops'), 'Trips', 'cancel', ['trip_id' => $S->id, 'reason' => $c['cancel_before_checklist']]));
        Sim::at($fundAt + 5 * 3600 + 60, 'schedule', fn() => Sim::schedFinal($S->id, $D, 'planned dispatch time of a cancelled trip'));
        return $S;
    }
    // 3. pre-dispatch checklist, fund release, dispatch
    $checklist = function (int $at, string $tag) use ($S, $type, &$ODO, $live) {
        Sim::at($at - 35 * 60, "checklist open $tag", function () use ($S, $type) { Sim::schedForGates($S->id); act(Actors::dispatcher($type, Sim::$cur), 'Trips', 'checklist_start', ['trip_id' => $S->id, 'kind' => 'Pre-Dispatch']); });
        Sim::at($at - 15 * 60, "checklist submit $tag", function () use ($S, $type, &$ODO, $live) {
            Sim::schedForGates($S->id);
            $t = $live(); $ODO[$t['vehicle_id']] ??= 84000 + (int)$t['vehicle_id'] * 3700;
            act(Actors::dispatcher($type, Sim::$cur), 'Trips', 'checklist', ['trip_id' => $S->id, 'items' => checklistItems(), 'odometer_km' => (string)$ODO[$t['vehicle_id']], 'fuel_level' => 'Full', 'remarks' => '']);
        });
    };
    $checklist($D, '1');
    if ($c['fund'] ?? true) Sim::at($D - 25 * 60, 'fund release', function () use ($S, $U, $fundOf) { Sim::schedForGates($S->id); act($U('fin'), 'Funds', 'release', ['fund_id' => (int)$fundOf($S->id)['id']]); });
    if ($stop === 'ready') { Sim::at($D - 14 * 60, 'schedule', fn() => Sim::schedFinal($S->id, $c['final_sched'], 'upcoming dispatch time of a Ready for Dispatch trip')); return $S; }
    if (isset($c['cancel_after_release'])) {
        Sim::at($D - 10 * 60, 'trip cancel', fn() => act($U('ops'), 'Trips', 'cancel', ['trip_id' => $S->id, 'reason' => $c['cancel_after_release']]));
        Sim::at($D - 9 * 60, 'schedule', fn() => Sim::schedFinal($S->id, $D, 'planned dispatch time of a cancelled trip'));
        Sim::at($D + 86400, 'fund liquidate (cancelled trip)', function () use ($S, $U, $fundOf) {
            act($U('fin'), 'Funds', 'liquidate', ['fund_id' => (int)$fundOf($S->id)['id'], 'liquidated_amount' => '0.00', 'liquidation_notes' => 'Trip cancelled before dispatch; full amount returned in cash.']);
        });
        return $S;
    }
    $dispatch = function (int $at, string $tag) use ($S, $type) {
        Sim::at($at, "dispatch $tag", function () use ($S, $type, $at) {
            Sim::schedForGates($S->id);
            act(Actors::dispatcher($type, Sim::$cur), 'Trips', 'dispatch', ['trip_id' => $S->id]);
            Sim::schedFinal($S->id, $at - mt_rand(0, 4) * 5 * 60, 'scheduled dispatch time (set relative to the recorded dispatch)');
        });
    };
    $dispatch($D, '1');
    if ($stop === 'dispatched') return $S;
    $done = $D + $dur * 60;
    // 4. incidents
    $variant = $c['variant'] ?? 'normal';
    $mechs = [];
    if ($variant === 'minor' || $variant === 'major') {
        $I = $D + (int)($dur * 0.35) * 60;
        $inc = $c['incident'];
        Sim::at($I, 'incident', function () use ($S, $type, $inc, $I) {
            act(Actors::dispatcher($type, Sim::$cur), 'Trips', 'incident', ['trip_id' => $S->id, 'incident_type' => $inc[0], 'incident_at' => date('Y-m-d\TH:i', $I),
                'location' => $inc[1], 'problem' => $inc[2], 'details' => $inc[3], 'reason' => $inc[4] ?? '', 'persons_involved' => 'Driver and crew of the trip']);
        });
        if ($stop === 'incident_reported') return $S;
        $jobOf = fn() => one($p, "SELECT * FROM maintenance_jobs WHERE trip_id=? AND source='Incident' ORDER BY id DESC LIMIT 1", [$S->id]);
        $sev = $variant === 'minor' ? 'Minor' : 'Major';
        Sim::at($I + 45 * 60, 'incident assess', function () use ($S, $U, $sev, $c, $jobOf, $freeMechanic, $clockIn, $HIST_POOL, &$mechs) {
            $mid = $c['mech'] ?? $freeMechanic($HIST_POOL);
            if (!$mid) throw new RuntimeException('No free mechanic for the incident assessment.');
            if (!isset($c['mech'])) $clockIn($mid);
            $mechs[] = $mid;
            act($U('mp'), 'Maintenance', 'assess', ['job_id' => (int)$jobOf()['id'], 'severity' => $sev, 'mechanic_id' => $mid,
                'recommendation' => $sev === 'Minor' ? 'Repair on site; the truck can continue after the repair.' : 'Tow the truck to the garage for repair; reschedule the delivery.']);
        });
        if ($stop === 'incident_assessed') return $S;
        $rep = $c['repair'];
        $t1 = $sev === 'Minor' ? $I + 90 * 60 : strtotime(date('Y-m-d 09:00', $I + 86400));
        $t2 = $sev === 'Minor' ? $I + 150 * 60 : strtotime(date('Y-m-d 15:00', $I + 2 * 86400));
        Sim::at($t1, 'incident inspect', fn() => act($U('mp'), 'Maintenance', 'inspect', ['job_id' => (int)$jobOf()['id'], 'findings' => $rep[0], 'diagnosis' => $rep[1]]));
        if ($stop === 'incident_repair') return $S;
        foreach ($rep[3] as $k => [$ctype, $desc, $amt, $prov]) Sim::at($t1 + ($k + 1) * 600, 'incident cost', fn() => act($U('mp'), 'Maintenance', 'add_cost',
            ['job_id' => (int)$jobOf()['id'], 'cost_type' => $ctype, 'description' => $desc, 'amount' => money2($amt), 'provider' => $prov, 'incurred_on' => date('Y-m-d', Sim::$cur)]));
        Sim::at($t2, 'incident repair complete', function () use ($U, $jobOf, $rep) {
            $j = $jobOf();
            act($U('mp'), 'Maintenance', 'complete', ['job_id' => (int)$j['id'], 'repair_performed' => $rep[2], 'completed_on' => date('Y-m-d', Sim::$cur)], ['evidence' => ['png', 'Repair Evidence', $j['job_ref']]]);
        });
        Sim::at($t2 + 20 * 60, 'incident verify', fn() => act($U('mp'), 'Maintenance', 'verify', ['job_id' => (int)$jobOf()['id'], 'result' => 'Passed', 'verification_notes' => 'Road test done; truck cleared.']));
        if (!isset($c['mech'])) Sim::at($t2 + 40 * 60, 'mechanic time out', function () use ($clockOutIfFree, &$mechs) { $clockOutIfFree($mechs); });
        if ($sev === 'Minor') {
            Sim::at($t2 + 50 * 60, 'trip resume', fn() => act(Actors::dispatcher($type, Sim::$cur), 'Trips', 'resume', ['trip_id' => $S->id]));
            if ($stop === 'in_transit') return $S;
            $done = $t2 + 50 * 60 + (int)($dur * 0.65) * 60;
        } else {
            $D2 = strtotime(date('Y-m-d 08:00', $t2 + 86400));
            Sim::at($D2 - 3 * 3600, 'trip reschedule', function () use ($S, $type, $D2, $p) {
                $before = Sim::maxIds();
                act(Actors::dispatcher($type, Sim::$cur), 'Trips', 'reschedule', ['trip_id' => $S->id, 'scheduled_at' => date('Y-m-d\TH:i', time() + 180)]);
                $real = (string)one($p, 'SELECT scheduled_at FROM trips WHERE id=?', [$S->id])['scheduled_at'];
                Sim::retext($real, date('Y-m-d H:i:s', $D2), $before); Sim::retext(substr($real, 0, 16), date('Y-m-d H:i', $D2), $before);
            });
            $checklist($D2, '2');
            $dispatch($D2, '2');
            $done = $D2 + $dur * 60;
        }
    }
    // 5. completion and arrival checklist
    $units = null;
    Sim::at($done, 'trip complete', function () use ($S, $type, $done, &$CTR, $live) {
        $t = $live(); $wb = 'WB-' . ($CTR['wb']++);
        act(Actors::dispatcher($type, Sim::$cur), 'Trips', 'complete', ['trip_id' => $S->id, 'completed_at' => date('Y-m-d\TH:i', $done), 'waybill_number' => $wb,
            'units_delivered' => $type === 'Car Carrier' ? (string)$t['units_planned'] : '', 'delivery_notes' => 'Delivered and received by the consignee.'],
            ['waybill' => ['pdf', 'Waybill', $wb], 'ack_receipt' => ['pdf', 'Acknowledgement Receipt', $t['trip_ref']], 'delivery_receipt' => ['pdf', 'Delivery Receipt', $t['trip_ref']]]);
    });
    if ($stop === 'returning') return $S;
    $notOk = $variant === 'notok';
    Sim::at($done + 60 * 60, 'arrival open', fn() => act(Actors::dispatcher($type, Sim::$cur), 'Trips', 'checklist_start', ['trip_id' => $S->id, 'kind' => 'Arrival']));
    Sim::at($done + 80 * 60, 'arrival submit', function () use ($S, $type, $km, $notOk, $c, &$ODO, $live) {
        $t = $live(); $vid = (int)$t['vehicle_id'];
        $ODO[$vid] = ($ODO[$vid] ?? 84000 + $vid * 3700) + (int)($km * 2) + mt_rand(4, 25);
        $bad = [];
        if ($notOk) { $keys = array_keys(checklistKeys()); $bad[$keys[$c['notok_items'][0]]] = $c['notok_items'][1]; $bad[$keys[$c['notok_items'][2]]] = $c['notok_items'][3]; }
        act(Actors::dispatcher($type, Sim::$cur), 'Trips', 'arrival', ['trip_id' => $S->id, 'items' => checklistItems($bad), 'odometer_km' => (string)$ODO[$vid],
            'fuel_level' => pick(['3/4', '1/2', '1/4']), 'remarks' => $notOk ? '' : 'Truck returned in good condition.', 'damage_notes' => $notOk ? $c['notok_damage'] : ''],
            $notOk ? ['photos' => ['png', 'Arrival Inspection Photo', $t['trip_ref']]] : []);
    });
    if ($notOk) {
        $jobOf = fn() => one($p, "SELECT * FROM maintenance_jobs WHERE trip_id=? AND source='On-Premise' ORDER BY id DESC LIMIT 1", [$S->id]);
        $R = strtotime(date('Y-m-d 08:30', $done + 86400)); $rep = $c['repair'];
        Sim::at($R, 'arrival repair assign', function () use ($U, $jobOf, $freeMechanic, $clockIn, $HIST_POOL, &$mechs) {
            $mid = $freeMechanic($HIST_POOL); if (!$mid) throw new RuntimeException('No free mechanic.');
            $clockIn($mid); $mechs[] = $mid;
            act($U('mp'), 'Maintenance', 'assign_mechanic', ['job_id' => (int)$jobOf()['id'], 'mechanic_id' => $mid]);
        });
        Sim::at($R + 5400, 'arrival repair inspect', fn() => act($U('mp'), 'Maintenance', 'inspect', ['job_id' => (int)$jobOf()['id'], 'findings' => $rep[0], 'diagnosis' => $rep[1]]));
        foreach ($rep[3] as $k => [$ctype, $desc, $amt, $prov]) Sim::at($R + 7200 + $k * 600, 'arrival repair cost', fn() => act($U('mp'), 'Maintenance', 'add_cost',
            ['job_id' => (int)$jobOf()['id'], 'cost_type' => $ctype, 'description' => $desc, 'amount' => money2($amt), 'provider' => $prov, 'incurred_on' => date('Y-m-d', Sim::$cur)]));
        Sim::at($R + 6 * 3600, 'arrival repair complete', function () use ($U, $jobOf, $rep) { $j = $jobOf(); act($U('mp'), 'Maintenance', 'complete', ['job_id' => (int)$j['id'], 'repair_performed' => $rep[2], 'completed_on' => date('Y-m-d', Sim::$cur)], ['evidence' => ['png', 'Repair Evidence', $j['job_ref']]]); });
        Sim::at($R + 7 * 3600, 'arrival repair verify', fn() => act($U('mp'), 'Maintenance', 'verify', ['job_id' => (int)$jobOf()['id'], 'result' => 'Passed', 'verification_notes' => 'Checked by the custodian; truck returned to service.']));
        Sim::at($R + 7 * 3600 + 1200, 'mechanic time out', function () use ($clockOutIfFree, &$mechs) { $clockOutIfFree($mechs); });
    }
    // 6. fund liquidation, billing, invoice, collections
    $lq = strtotime(date('Y-m-d 15:00', $done + 86400));
    if ($lq <= $NOWR && ($c['fund'] ?? true) && !($c['no_liquidation'] ?? false)) Sim::at($lq, 'fund liquidate', function () use ($S, $U, $fundOf) {
        $f = $fundOf($S->id); $ret = mt_rand(0, 9) < 6 ? 0 : mt_rand(1, 6) * 50;
        act($U('fin'), 'Funds', 'liquidate', ['fund_id' => (int)$f['id'], 'liquidated_amount' => money2((float)$f['amount'] - $ret),
            'liquidation_notes' => $ret ? 'Unused toll and meal allowance returned in cash.' : 'Fully spent as requested.']);
    });
    if (!($c['billing'] ?? true)) return $S;
    $vt = strtotime(date('Y-m-d 10:00', $done + 86400));
    if (isset($c['charge'])) {
        Sim::at($vt - 1800, 'billing charge request', function () use ($S, $U, $billOf, $c) {
            act($U('bill'), 'Billing', 'charge_add', ['billing_id' => (int)$billOf($S->id)['id'], 'kind' => 'Additional Charge', 'description' => $c['charge'][0], 'amount' => money2($c['charge'][1]), 'reason' => $c['charge'][2]]);
        });
        if (!($c['charge_pending'] ?? false)) Sim::at($vt - 600, 'billing charge decision', function () use ($S, $U, $p) {
            $ch = one($p, "SELECT bc.id FROM billing_charges bc JOIN billings b ON b.id=bc.billing_id WHERE b.trip_id=? AND bc.status='Pending Approval'", [$S->id]);
            act($U('adm'), 'Billing', 'charge_decide', ['charge_id' => (int)$ch['id'], 'decision' => 'approve', 'notes' => 'Supported by the consignee gate pass.']);
        });
        if ($c['charge_pending'] ?? false) return $S;
    }
    if ($vt > $NOWR) return $S;
    Sim::at($vt, 'billing verify', fn() => act($U('bill'), 'Billing', 'verify', ['billing_id' => (int)$billOf($S->id)['id']]));
    $inv = strtotime(date('Y-m-d 11:00', $done + 2 * 86400));
    if ($inv > $NOWR || ($c['no_invoice'] ?? false)) return $S;
    Sim::at($inv, 'invoice finalize', fn() => act($U('bill'), 'Billing', 'finalize', ['billing_id' => (int)$billOf($S->id)['id'], 'invoice_date' => date('Y-m-d', Sim::$cur)]));
    // collections: plan from the invoice age and the client's payment terms (all dates <= now)
    $terms = $TERMS[$c['client']]; $age = (int)floor(($NOWR - $inv) / 86400); $r = mt_rand(1, 100);
    $plan = $age > $terms + 5 ? ($r <= 80 ? 'full' : ($r <= 90 ? 'partial' : 'none')) : ($age > 7 ? ($r <= 45 ? 'full' : ($r <= 62 ? 'partial' : 'none')) : ($r <= 15 ? 'full' : 'none'));
    if ($plan === 'none') return $S;
    $payDay = $plan === 'full' ? $inv + 86400 * min($age - 1, max(5, $terms + mt_rand(-10, 8))) : $inv + 86400 * min($age - 1, max(4, $terms - mt_rand(5, 12)));
    $payAt = strtotime(date('Y-m-d', $payDay) . ' ' . pick(['09:30', '10:45', '14:15', '15:40']));
    if ($payAt > $NOWR || $payAt <= $inv) return $S;
    $collect = function (int $at, ?float $share) use ($S, $U, $billOf, &$CTR) {
        Sim::at($at, 'collection', function () use ($S, $U, $billOf, $share, &$CTR) {
            $b = $billOf($S->id); $bal = round((float)$b['total_amount'] - (float)$b['amount_paid'], 2);
            $amt = $share === null ? $bal : round(floor((float)$b['total_amount'] * $share / 100) * 100, 2);
            $ar = 'AR-' . ($CTR['ar']++);
            act($U('bill'), 'Billing', 'collect', ['billing_id' => (int)$b['id'], 'amount' => money2($amt), 'payment_method' => 'Cash', 'payment_date' => date('Y-m-d', Sim::$cur), 'ar_number' => $ar],
                ['ack_receipt' => ['pdf', 'Payment Acknowledgement Receipt', $ar]]);
        });
    };
    if ($plan === 'full' && mt_rand(1, 100) <= 20 && $payAt - 10 * 86400 > $inv) { $collect($payAt - 10 * 86400, 0.5); $collect($payAt, null); }
    elseif ($plan === 'full') $collect($payAt, null);
    else $collect($payAt, mt_rand(0, 1) ? 0.5 : 0.4);
    return $S;
};

// ------------------------------------------------------------------ 1. historical trips (weeks of Aug 3 .. Nov 9)
$INCIDENTS = [
    'minor' => [
        [['Flat Tire', 'SLEX NORTHBOUND, SAN PEDRO, LAGUNA', 'Rear inner tire blown out', 'Rear left inner tire blew out on the expressway; crew moved the truck to the shoulder and called the garage.', 'Road debris'],
         ['Rear left inner tire tread separated; rim not damaged.', 'Tire failure from road debris.', 'Replaced the rear left inner tire with the spare and checked the wheel nuts.', [['Labor', 'On-site tire change and wheel nut torque check', 1200, null]]]],
        [['Breakdown', 'NLEX SOUTHBOUND, MARILAO, BULACAN', 'Engine stalled; fuel warning light', 'Engine lost power and stalled; the fuel filter warning light was on.', 'Clogged fuel filter'],
         ['Primary fuel filter clogged with sediment.', 'Fuel starvation from a clogged filter.', 'Replaced the primary fuel filter on site and bled the fuel system.', [['Labor', 'On-site fuel filter replacement and bleeding', 1500, null], ['Materials', 'Fuel filter element bought on the road', 980, 'Marilao Truck Supply']]]],
        [['Breakdown', 'CAVITEX, PARANAQUE CITY', 'Air pressure low; brakes dragging', 'Air pressure dropped below the safe level and the brakes started dragging.', 'Air line leak'],
         ['Split air line at the trailer coupling.', 'Air leak at the coupling hose.', 'Replaced the split air coupling hose and tested the brake air pressure.', [['Labor', 'On-site air hose replacement', 1300, null], ['Materials', 'Air coupling hose', 1450, 'Paranaque Air Brake Parts']]]],
        [['Flat Tire', 'STAR TOLLWAY, LIPA, BATANGAS', 'Front right tire punctured', 'Front right tire punctured by a nail; truck stopped at the shoulder.', 'Nail on the road'],
         ['Nail puncture on the tread; tire repairable.', 'Puncture.', 'Patched the tire on site and reinflated; checked pressure of all tires.', [['Labor', 'On-site tire repair', 900, null]]]],
    ],
    'major' => [
        [['Accident', 'MACARTHUR HIGHWAY, MALOLOS, BULACAN', 'Side swipe; left mirror and door damaged', 'A passing bus side-swiped the truck; no injuries; left mirror and door panel damaged. Police report taken at the scene.', 'Third-party vehicle'],
         ['Left door panel dented, mirror bracket broken, door does not close fully.', 'Body damage from the collision.', 'Straightened and repainted the left door panel; replaced the mirror bracket and both mirrors.', [['Outside Service', 'Body repair and repaint of the left door', 14500, 'Malolos Body & Paint Works'], ['Materials', 'Side mirror set and bracket', 3800, 'Malolos Body & Paint Works'], ['Labor', 'Door alignment and mirror installation', 2200, null]]]],
        [['Breakdown', 'MAHARLIKA HIGHWAY, STO. TOMAS, BATANGAS', 'Clutch slipping; truck cannot climb', 'Clutch slipped under load and the truck could not continue up the grade.', 'Worn clutch'],
         ['Clutch disc worn to the rivets; pressure plate glazed.', 'Clutch failure from wear.', 'Towed to the garage; replaced the clutch disc, pressure plate and release bearing.', [['Outside Service', 'Towing to the garage', 6500, 'Batangas Heavy Towing'], ['Outside Service', 'Clutch overhaul', 18500, 'Northline Clutch & Brake Service'], ['Labor', 'Clutch removal and installation assist', 3000, null]]]],
    ],
];
$NOTOK = [
    [[5, 'Cracked lens', 9, 'Loose'], 'Right tail lamp lens cracked; mud flap bracket loose.', ['Right tail lamp lens cracked; mud flap bracket bolts missing.', 'Wear and minor impact damage.', 'Replaced the tail lamp lens and refitted the mud flap bracket.', [['Materials', 'Tail lamp lens and bracket bolts', 1150, 'Valenzuela Truck Parts Center'], ['Labor', 'Lamp and bracket installation', 800, null]]]],
    [[20, 'Low tread', 21, 'Uneven wear'], 'Front tires worn unevenly; steering pulls to the right.', ['Front tires worn on the inner edge; toe-in out of specification.', 'Wheel misalignment.', 'Wheel alignment done; front tires rotated to the rear.', [['Outside Service', 'Wheel alignment', 2500, 'Caloocan Wheel Alignment Center'], ['Labor', 'Tire rotation', 700, null]]]],
    [[33, 'Not working', 34, 'Not working'], 'Hazard and left signal lamps not working.', ['Corroded connector at the left lamp harness.', 'Electrical fault from corrosion.', 'Cleaned and replaced the lamp harness connector; all lamps tested.', [['Materials', 'Harness connector and terminals', 650, 'Valenzuela Auto Electrical Shop'], ['Labor', 'Electrical repair', 1100, null]]]],
];
$weekStart = $H('2026-08-03 00:00');
$histTrips = [];
$n = 0; $minorLeft = 4; $majorLeft = 2; $notokLeft = 3; $chargeLeft = 3;
for ($w = 0; $w < 15; $w++) {
    $ws = $weekStart + $w * 7 * 86400;
    $count = $w === 14 ? 5 : 6 + mt_rand(0, 3);
    for ($i = 0; $i < $count; $i++) {
        $r = mt_rand(1, 100); $type = $r <= 40 ? 'Car Carrier' : ($r <= 75 ? 'Container' : 'Wing Van');
        $day = $w === 14 ? mt_rand(0, 1) : mt_rand(0, 5);
        $night = mt_rand(1, 100) <= 30;
        $hour = $night ? pick([18, 19, 20, 21, 22]) : pick([6, 7, 8, 9, 10, 13, 14]);
        $D = $ws + $day * 86400 + $hour * 3600 + pick([0, 15, 30, 45]) * 60;
        $n++;
        $variant = 'normal'; $inc = null; $rep = null; $extra = [];
        if ($w >= 2 && $w <= 12 && $n % 11 === 0 && $minorLeft > 0) { $variant = 'minor'; [$inc, $rep] = $INCIDENTS['minor'][4 - $minorLeft]; $minorLeft--; }
        elseif ($w >= 3 && $w <= 11 && $n % 23 === 0 && $majorLeft > 0) { $variant = 'major'; [$inc, $rep] = $INCIDENTS['major'][2 - $majorLeft]; $majorLeft--; }
        elseif ($w >= 1 && $w <= 12 && $n % 17 === 0 && $notokLeft > 0) { $variant = 'notok'; $x = $NOTOK[3 - $notokLeft]; $notokLeft--; $extra = ['notok_items' => $x[0], 'notok_damage' => $x[1]]; $rep = $x[2]; }
        if ($w >= 4 && $w <= 12 && $n % 19 === 0 && $chargeLeft > 0) { $extra['charge'] = pick([['Waiting time at the port (6 hours)', 1500, 'Truck waited 6 hours for container release; gate pass on file.'], ['Additional drop-off point', 2000, 'Consignee requested a second drop-off point.']]); $chargeLeft--; }
        $client = pick($CLIENTS_BY_TYPE[$type]);
        $histTrips[] = $trip(['type' => $type, 'client' => $client, 'route' => pick($ROUTES[$type]), 'D' => $D, 'path' => mt_rand(1, 100) <= 30 ? 'B' : 'A',
            'variant' => $variant, 'incident' => $inc, 'repair' => $rep] + $extra);
    }
}
if ($minorLeft || $majorLeft || $notokLeft) Sim::$notes[] = "incident variants not placed: minor $minorLeft, major $majorLeft, arrival NOT OK $notokLeft";

// rejected requests and cancelled trips
foreach ([['Container', '2026-08-19 09:00', 'Client moved the shipment to its consignee\'s own trucks.'], ['Car Carrier', '2026-09-23 10:00', 'Duplicate of an existing booking for the same units.'], ['Wing Van', '2026-10-21 14:00', 'Pickup point is outside the service area agreed with the client.']] as [$type, $at, $why])
    $trip(['type' => $type, 'client' => pick($CLIENTS_BY_TYPE[$type]), 'route' => pick($ROUTES[$type]), 'D' => $H($at) + 3 * 86400, 'created' => $H($at), 'path' => 'B', 'variant' => 'rejected', 'reason' => $why]);
$trip(['type' => 'Container', 'client' => 6, 'route' => $ROUTES['Container'][1], 'D' => $H('2026-08-27 08:00'), 'path' => 'A', 'fund' => false, 'stop' => null, 'cancel_before_checklist' => 'Client postponed the shipment indefinitely.', 'fund_at' => $H('2026-08-26 10:00')]);
$trip(['type' => 'Wing Van', 'client' => 10, 'route' => $ROUTES['Wing Van'][0], 'D' => $H('2026-09-16 07:00'), 'path' => 'A', 'cancel_before_checklist' => 'Consignee warehouse closed for inventory; delivery cancelled by the client.']);
$trip(['type' => 'Car Carrier', 'client' => 5, 'route' => $ROUTES['Car Carrier'][0], 'D' => $H('2026-10-08 06:30'), 'path' => 'A', 'cancel_after_release' => 'Dealer could not receive the units; client cancelled on the morning of dispatch.']);
$trip(['type' => 'Container', 'client' => 12, 'route' => $ROUTES['Container'][3], 'D' => $H('2026-10-29 09:00'), 'path' => 'B', 'cancel_before_checklist' => 'Container was held by customs; booking cancelled by the client.']);

// ------------------------------------------------------------------ 2. historical garage repairs (Motorpool), incl. repeat repairs and assisting mechanics
$GARAGE = [
    ['2026-08-11 08:00', 8, 'Air brake pressure drops within minutes after parking.', ['Rear brake chamber diaphragm leaking.', 'Air leak at the rear brake chamber.', 'Replaced the rear brake chamber diaphragm; leak test passed.', [['Materials', 'Brake chamber diaphragm kit', 2400, 'Northline Clutch & Brake Service'], ['Labor', 'Brake chamber repair', 1800, null]]], 2, false],
    ['2026-08-20 08:00', 20, 'Engine overheats on long climbs; coolant level low.', ['Upper radiator hose cracked near the clamp.', 'Coolant leak from the radiator hose.', 'Replaced the upper radiator hose and clamps; refilled coolant; pressure-tested.', [['Materials', 'Radiator hose and clamps', 1650, 'Valenzuela Truck Parts Center'], ['Labor', 'Cooling system repair', 1200, null]]], 1, false],
    ['2026-08-28 08:30', 24, 'Battery warning light; batteries not charging.', ['Alternator output below 24 V at idle.', 'Alternator regulator failure.', 'Alternator rewound and regulator replaced by the electrical shop; charging tested.', [['Outside Service', 'Alternator rewind and regulator replacement', 6500, 'Valenzuela Auto Electrical Shop'], ['Labor', 'Alternator removal and installation', 900, null]]], 1, false],
    ['2026-09-07 08:00', 3, 'Car deck hydraulic ramp leaking oil.', ['Ramp cylinder seals worn; oil on the lower deck.', 'Hydraulic seal wear.', 'Resealed the ramp cylinder and refilled hydraulic oil; ramp cycled 10 times.', [['Materials', 'Hydraulic seal kit and oil', 3200, 'Malabon Machine Works'], ['Labor', 'Ramp cylinder reseal', 2800, null]]], 2, true],
    ['2026-09-15 08:00', 8, 'Vibration at highway speed.', ['Propeller shaft center bearing worn.', 'Driveline vibration from the center bearing.', 'Replaced the propeller shaft center bearing; road-tested at 80 km/h.', [['Outside Service', 'Center bearing replacement', 9800, 'Malabon Machine Works'], ['Labor', 'Propeller shaft removal assist', 2000, null]]], 2, false],
    ['2026-09-24 08:30', 27, 'Uneven tire wear on the front axle.', ['Front axle toe-in out of specification.', 'Misalignment.', 'Wheel alignment done and steering linkage checked.', [['Outside Service', 'Wheel alignment', 2500, 'Caloocan Wheel Alignment Center']]], 1, false],
    ['2026-10-02 08:00', 11, 'Black smoke and loss of power under load.', ['Two injectors with poor spray pattern.', 'Injector wear.', 'Injectors calibrated and two nozzles replaced by the calibration center.', [['Outside Service', 'Injector calibration and two nozzles', 12500, 'Meycauayan Diesel Calibration Center'], ['Labor', 'Injector removal and installation', 1800, null]]], 2, false],
    ['2026-10-09 08:00', 20, 'Engine overheats again in traffic.', ['Radiator fins clogged; fan clutch slipping.', 'Cooling system airflow problem.', 'Radiator flushed and cleaned; fan clutch replaced.', [['Outside Service', 'Radiator cleaning and flushing', 3500, 'Valenzuela Radiator Shop'], ['Materials', 'Fan clutch', 4200, 'Valenzuela Truck Parts Center'], ['Labor', 'Fan clutch replacement', 1000, null]]], 1, false],
    ['2026-10-16 08:00', 24, 'Rear left leaf spring broken.', ['Main leaf of the rear left spring broken.', 'Spring fatigue.', 'Replaced the rear left leaf spring assembly and U-bolts.', [['Materials', 'Leaf spring assembly and U-bolts', 5800, 'Malabon Machine Works'], ['Labor', 'Spring replacement', 1500, null]]], 2, false],
    ['2026-10-23 08:30', 13, 'Turbocharger whistling and loss of power.', ['Turbo shaft play excessive; oil seal leaking.', 'Turbocharger wear.', 'Turbocharger rebuilt by the outside shop; boost pressure tested.', [['Outside Service', 'Turbocharger rebuild', 22000, 'Meycauayan Diesel Calibration Center'], ['Labor', 'Turbo removal and installation', 2500, null]]], 2, false],
    ['2026-10-28 08:00', 8, 'Air dryer purging continuously.', ['Air dryer cartridge saturated; purge valve sticking.', 'Air dryer failure.', 'Replaced the air dryer cartridge and purge valve.', [['Materials', 'Air dryer cartridge and purge valve', 5600, 'Northline Clutch & Brake Service'], ['Labor', 'Air dryer service', 900, null]]], 1, false],
    ['2026-11-04 08:00', 26, 'Headlamp and turn signals intermittent.', ['Ground strap corroded at the cab.', 'Poor ground connection.', 'Replaced the cab ground strap and cleaned the terminals.', [['Materials', 'Ground strap and terminals', 950, 'Valenzuela Auto Electrical Shop'], ['Labor', 'Electrical repair', 600, null]]], 1, false],
];
foreach ($GARAGE as $g => [$at, $vid, $problem, $rep, $days, $failFirst]) {
    $R = $H($at); $J = (object)['id' => 0, 'mechs' => []];
    Sim::at($R, 'garage report', function () use ($J, $U, $p, $vid, $problem, $R) {
        $v = one($p, 'SELECT * FROM vehicles WHERE id=?', [$vid]);
        $free = fn($id) => !FleetState::openJob($p, $id) && !FleetState::activeTrip($p, $id);
        $use = $free($vid) ? $vid : (int)(one($p, "SELECT v.id FROM vehicles v WHERE v.active=1 AND v.truck_type=? AND v.status='Available' AND NOT EXISTS (SELECT 1 FROM trip_assignments a WHERE a.resource_type='vehicle' AND a.resource_id=v.id) ORDER BY v.id LIMIT 1", [$v['truck_type']])['id'] ?? 0);
        if (!$use) throw new RuntimeException('No free truck for the garage report.');
        if ($use !== $vid) Sim::$notes[] = "garage repair planned for vehicle $vid on " . date('Y-m-d', $R) . " used vehicle $use (planned truck was busy)";
        $r = act($U('mp'), 'Maintenance', 'report', ['vehicle_id' => $use, 'problem' => $problem, 'reported_on' => date('Y-m-d', Sim::$cur), 'remarks' => 'Reported by the driver at the garage.'],
            ['photos' => ['png', 'Inspection Photo', 'truck ' . $use]]);
        $J->id = (int)$r['job_id'];
    });
    Sim::at($R + 1800, 'garage assign', function () use ($J, $U, $freeMechanic, $clockIn, $HIST_POOL, $g) {
        $mid = $freeMechanic($HIST_POOL); if (!$mid) throw new RuntimeException('No free mechanic.');
        $clockIn($mid); $J->mechs[] = $mid;
        act($U('mp'), 'Maintenance', 'assign_mechanic', ['job_id' => $J->id, 'mechanic_id' => $mid]);
        if ($g % 3 === 0 && ($h = $freeMechanic($HIST_POOL, [$mid]))) {   // assisting mechanic on every third repair
            $clockIn($h); $J->mechs[] = $h;
            act($U('mp'), 'Maintenance', 'helpers_add', ['job_id' => $J->id, 'mechanic_ids' => [$h]]);
        }
    });
    Sim::at($R + 3 * 3600, 'garage inspect', fn() => act($U('mp'), 'Maintenance', 'inspect', ['job_id' => $J->id, 'findings' => $rep[0], 'diagnosis' => $rep[1]]));
    foreach ($rep[3] as $k => [$ctype, $desc, $amt, $prov]) Sim::at($R + 4 * 3600 + $k * 900 + ($ctype === 'Outside Service' ? 86400 * max(0, $days - 1) : 0), 'garage cost', fn() => act($U('mp'), 'Maintenance', 'add_cost',
        ['job_id' => $J->id, 'cost_type' => $ctype, 'description' => $desc, 'amount' => money2($amt), 'provider' => $prov, 'incurred_on' => date('Y-m-d', Sim::$cur)]));
    $C = strtotime(date('Y-m-d 15:00', $R + $days * 86400));
    Sim::at($C, 'garage complete', fn() => act($U('mp'), 'Maintenance', 'complete', ['job_id' => $J->id, 'repair_performed' => $rep[2], 'completed_on' => date('Y-m-d', Sim::$cur)], ['evidence' => ['png', 'Repair Evidence', 'job ' . $J->id]]));
    if ($failFirst) {
        Sim::at($C + 3600, 'garage verify failed', fn() => act($U('mp'), 'Maintenance', 'verify', ['job_id' => $J->id, 'result' => 'Failed', 'verification_notes' => 'Ramp still drips oil after 10 cycles; reseal again.']));
        Sim::at($C + 86400 - 3600, 'garage complete again', fn() => act($U('mp'), 'Maintenance', 'complete', ['job_id' => $J->id, 'repair_performed' => 'Replaced the damaged cylinder rod wiper and resealed; no leak after 20 cycles.', 'completed_on' => date('Y-m-d', Sim::$cur)], ['evidence' => ['png', 'Repair Evidence', 'job ' . $J->id]]));
        $C += 86400;
    }
    Sim::at($C + 3600, 'garage verify', fn() => act($U('mp'), 'Maintenance', 'verify', ['job_id' => $J->id, 'result' => 'Passed', 'verification_notes' => 'Road test done; returned to service.']));
    Sim::at($C + 5400, 'mechanic time out', fn() => $clockOutIfFree($J->mechs));
}

// ------------------------------------------------------------------ 3. finance: payables and recorded expenses
$PAY = [
    ['2026-10-16 10:00', 'Fuel', 'Northbound Fuel Depot', 'Diesel supply statement, Oct 1-15', 46800, '2026-10-31', [['2026-10-30 14:00', 46800]]],
    ['2026-10-20 10:00', 'Utilities', 'Valenzuela garage electricity', 'Garage electricity, September to October billing', 12450, '2026-11-05', []],
    ['2026-11-02 10:00', 'Fuel', 'Northbound Fuel Depot', 'Diesel supply statement, Oct 16-31', 51200, '2026-11-17', [['2026-11-10 14:00', 20000]]],
    ['2026-11-05 10:00', 'RFID / Toll', 'Expressway RFID account', 'RFID toll reload for the fleet, November', 18500, '2026-11-19', []],
    ['2026-11-06 10:00', 'Office', 'Dispatch office lease', 'Dispatch office lease, November', 35000, '2026-11-30', []],
];
foreach ($PAY as [$at, $cat, $payee, $desc, $amt, $due, $pays]) {
    $A = (object)['id' => 0];
    Sim::at($at, 'payable add', function () use ($A, $U, $cat, $payee, $desc, $amt, $due, $p) {
        act($U('fin'), 'Finance', 'payable_add', ['payee' => $payee, 'category' => $cat, 'description' => $desc, 'amount' => money2($amt), 'due_on' => $due]);
        $A->id = (int)one($p, 'SELECT MAX(id) AS id FROM payables')['id'];
    });
    foreach ($pays as [$pat, $pamt]) Sim::at($pat, 'payable pay', fn() => act($U('fin'), 'Finance', 'payable_pay', ['payable_id' => $A->id, 'amount' => money2($pamt), 'payment_method' => 'Cash', 'released_on' => date('Y-m-d', Sim::$cur)]));
}
foreach ([['2026-08-14 11:00', 'Office', 2350, 'Office supplies for the dispatch office', 'Valenzuela Office Supply'], ['2026-09-11 11:00', 'Utilities', 1820, 'Garage water bill, August', 'Valenzuela water district'],
          ['2026-10-09 11:00', 'Office', 1980, 'Printer ink and bond paper for trip documents', 'Valenzuela Office Supply']] as [$at, $cat, $amt, $desc, $payee])
    Sim::at($at, 'expense add', fn() => act($U('fin'), 'Finance', 'expense_add', ['category' => $cat, 'amount' => money2($amt), 'incurred_on' => date('Y-m-d', Sim::$cur), 'description' => $desc, 'payee' => $payee]));

// ------------------------------------------------------------------ 4. current state (Nov 9 .. now)
$cur = fn(int $hoursAgo, int $min = 0) => $NOWR - $hoursAgo * 3600 - $min * 60;
$tomorrow = strtotime(date('Y-m-d', $NOWR + 86400));
// Container: 7 trucks held -> one more trip waits for resources
$trip(['type' => 'Container', 'client' => 7, 'route' => $ROUTES['Container'][0], 'D' => $cur(2), 'created' => $cur(26), 'fund_at' => $cur(20), 'path' => 'A', 'stop' => 'dispatched']);
$trip(['type' => 'Container', 'client' => 4, 'route' => $ROUTES['Container'][5], 'D' => $cur(6), 'created' => $cur(30), 'fund_at' => $cur(24), 'path' => 'A', 'variant' => 'minor', 'mech' => 11, 'stop' => 'in_transit',
    'incident' => ['Flat Tire', 'SLEX SOUTHBOUND, CALAMBA, LAGUNA', 'Rear right outer tire punctured', 'Rear right outer tire punctured; crew stopped at the shoulder and called the garage.', 'Sharp debris'],
    'repair' => ['Puncture on the tread; tire repairable.', 'Puncture.', 'Patched the tire on site and reinflated; checked all tire pressures.', [['Labor', 'On-site tire repair', 900, null]]]]);
$trip(['type' => 'Container', 'client' => 11, 'route' => $ROUTES['Container'][2], 'D' => $cur(3), 'created' => $cur(28), 'fund_at' => $cur(22), 'path' => 'A', 'variant' => 'minor', 'stop' => 'incident_reported',
    'incident' => ['Breakdown', 'MCARTHUR HIGHWAY, VALENZUELA CITY', 'Engine will not restart after a stop', 'Engine cranks but does not start after a delivery stop; crew waiting at the site.', 'Unknown; for assessment']]);
$trip(['type' => 'Container', 'client' => 1, 'route' => $ROUTES['Container'][6], 'D' => $NOWR + 15 * 60, 'created' => $cur(25), 'fund_at' => $cur(18), 'path' => 'A', 'stop' => 'ready', 'final_sched' => time() + 100 * 60]);
$trip(['type' => 'Container', 'client' => 13, 'route' => $ROUTES['Container'][4], 'D' => $tomorrow + 8 * 3600, 'created' => $cur(9), 'fund_at' => $cur(4), 'path' => 'A', 'stop' => 'fund_pending', 'sched_hours' => 28]);
$trip(['type' => 'Container', 'client' => 3, 'route' => $ROUTES['Container'][1], 'D' => $tomorrow + 10 * 3600, 'created' => $cur(8), 'fund_at' => $cur(5), 'path' => 'B', 'stop' => 'fund_approved', 'sched_hours' => 30]);
$trip(['type' => 'Container', 'client' => 10, 'route' => $ROUTES['Container'][3], 'D' => $tomorrow + 34 * 3600, 'created' => $cur(2), 'path' => 'A', 'stop' => 'assigned_nofund', 'sched_hours' => 54]);
$trip(['type' => 'Container', 'client' => 6, 'route' => $ROUTES['Container'][0], 'D' => $tomorrow + 13 * 3600, 'created' => $cur(1), 'path' => 'A', 'stop' => 'awaiting', 'sched_hours' => 33]);
// Wing Van
$trip(['type' => 'Wing Van', 'client' => 13, 'route' => $ROUTES['Wing Van'][2], 'D' => $cur(16), 'created' => $cur(40), 'fund_at' => $cur(34), 'path' => 'A', 'variant' => 'major', 'mech' => 3, 'stop' => 'incident_assessed',
    'incident' => ['Breakdown', 'SLEX NORTHBOUND, BINAN, LAGUNA', 'Brakes overheating and pulling to one side', 'Brakes overheated and the truck pulled to the right; driver stopped for safety.', 'Brake fault'],
    'repair' => ['Front right brake pads worn to the backing plate; air dryer not drying.', 'Brake pad wear with an air system fault.', '', []]]);
$trip(['type' => 'Wing Van', 'client' => 11, 'route' => $ROUTES['Wing Van'][3], 'D' => $cur(1), 'created' => $cur(25), 'fund_at' => $cur(19), 'path' => 'A', 'stop' => 'dispatched']);
$trip(['type' => 'Wing Van', 'client' => 1, 'route' => $ROUTES['Wing Van'][0], 'D' => $tomorrow + 9 * 3600, 'created' => $cur(7), 'fund_at' => $cur(3), 'path' => 'A', 'stop' => 'fund_pending', 'sched_hours' => 29]);
$trip(['type' => 'Wing Van', 'client' => 6, 'route' => $ROUTES['Wing Van'][5], 'D' => $tomorrow + 3 * 86400, 'created' => $cur(5), 'path' => 'B', 'stop' => 'pending_review', 'sched_hours' => 96]);
$trip(['type' => 'Wing Van', 'client' => 10, 'route' => $ROUTES['Wing Van'][1], 'D' => $tomorrow + 4 * 86400, 'created' => $cur(3), 'path' => 'B', 'stop' => 'pending_review', 'sched_hours' => 120]);
// Car Carrier
$trip(['type' => 'Car Carrier', 'client' => 2, 'route' => $ROUTES['Car Carrier'][0], 'D' => $cur(3), 'created' => $cur(27), 'fund_at' => $cur(21), 'path' => 'A', 'stop' => 'dispatched']);
$trip(['type' => 'Car Carrier', 'client' => 8, 'route' => $ROUTES['Car Carrier'][3], 'D' => $cur(7), 'created' => $cur(31), 'fund_at' => $cur(25), 'path' => 'A', 'stop' => 'dispatched']);
$trip(['type' => 'Car Carrier', 'client' => 5, 'route' => $ROUTES['Car Carrier'][2], 'D' => $cur(7, 30), 'created' => $cur(32), 'fund_at' => $cur(26), 'path' => 'A', 'variant' => 'minor', 'mech' => 13, 'stop' => 'incident_assessed',
    'incident' => ['Breakdown', 'STAR TOLLWAY, STO. TOMAS, BATANGAS', 'Car deck ramp will not lock', 'Upper deck ramp lock pin jammed; units secured; crew waiting for the mechanic.', 'Ramp lock fault']]);
$trip(['type' => 'Car Carrier', 'client' => 9, 'route' => $ROUTES['Car Carrier'][4], 'D' => $NOWR + 15 * 60, 'created' => $cur(24), 'fund_at' => $cur(17), 'path' => 'A', 'stop' => 'ready', 'final_sched' => time() + 95 * 60]);
$trip(['type' => 'Car Carrier', 'client' => 2, 'route' => $ROUTES['Car Carrier'][1], 'D' => $tomorrow + 6 * 3600, 'created' => $cur(6), 'fund_at' => $cur(3), 'path' => 'A', 'stop' => 'fund_approved', 'sched_hours' => 26]);
$trip(['type' => 'Car Carrier', 'client' => 8, 'route' => $ROUTES['Car Carrier'][5], 'D' => $tomorrow + 2 * 86400, 'created' => $cur(4), 'path' => 'B', 'stop' => 'assigned_nofund', 'sched_hours' => 72]);
$trip(['type' => 'Car Carrier', 'client' => 9, 'route' => $ROUTES['Car Carrier'][0], 'D' => $tomorrow + 5 * 86400, 'created' => $cur(2), 'path' => 'B', 'stop' => 'pending_review', 'sched_hours' => 140]);
$trip(['type' => 'Car Carrier', 'client' => 5, 'route' => $ROUTES['Car Carrier'][4], 'D' => $cur(10), 'created' => $cur(34), 'fund_at' => $cur(28), 'path' => 'A', 'stop' => 'returning']);
// recently completed, billing not finalized: one with a pending charge request for the Admin
$trip(['type' => 'Container', 'client' => 12, 'route' => $ROUTES['Container'][2], 'D' => $H(date('Y-m-d 07:00', $NOWR - 2 * 86400)), 'path' => 'A', 'no_liquidation' => true, 'charge' => ['Waiting time at the port (5 hours)', 1250, 'Truck waited 5 hours for container release; gate pass on file.'], 'charge_pending' => true]);
$trip(['type' => 'Wing Van', 'client' => 3, 'route' => $ROUTES['Wing Van'][4], 'D' => $H(date('Y-m-d 06:30', $NOWR - 2 * 86400)), 'path' => 'A', 'charge' => ['Additional drop-off point', 1800, 'Consignee asked for a second drop-off point in Caloocan.'], 'charge_pending' => true]);

// current garage repairs (Motorpool) and parts requests
$freeTruck = function (string $type, int $prefer) use ($p): int {
    $ok = fn($id) => one($p, "SELECT v.id FROM vehicles v WHERE v.id=? AND v.active=1 AND v.status='Available' AND NOT EXISTS (SELECT 1 FROM trip_assignments a WHERE a.resource_type='vehicle' AND a.resource_id=v.id)", [$id]);
    if ($ok($prefer)) return $prefer;
    $alt = one($p, "SELECT v.id FROM vehicles v WHERE v.active=1 AND v.truck_type=? AND v.status='Available' AND NOT EXISTS (SELECT 1 FROM trip_assignments a WHERE a.resource_type='vehicle' AND a.resource_id=v.id) ORDER BY v.id DESC LIMIT 1", [$type]);
    if (!$alt) throw new RuntimeException("No free $type truck for a repair.");
    Sim::$notes[] = "repair planned for vehicle $prefer on " . date('Y-m-d H:i', Sim::$cur) . ' used vehicle ' . $alt['id'] . ' (planned truck was busy)';
    return (int)$alt['id'];
};
$curJob = function (int $vid, int $at, string $problem, ?int $mid, ?array $inspect) use ($U, $freeTruck): stdClass {
    $J = (object)['id' => 0, 'vid' => 0];
    Sim::at($at, 'current garage report', function () use ($J, $U, $vid, $problem, $mid, $freeTruck) {
        $vid = $freeTruck('Car Carrier', $vid); $J->vid = $vid;
        $r = act($U('mp'), 'Maintenance', 'report', ['vehicle_id' => $vid, 'problem' => $problem, 'reported_on' => date('Y-m-d', Sim::$cur), 'mechanic_id' => $mid ?? ''], ['photos' => ['png', 'Inspection Photo', 'truck ' . $vid]]);
        $J->id = (int)$r['job_id'];
    });
    if ($inspect) Sim::at($at + 2 * 3600, 'current garage inspect', fn() => act($U('mp'), 'Maintenance', 'inspect', ['job_id' => $J->id, 'findings' => $inspect[0], 'diagnosis' => $inspect[1]]));
    return $J;
};
$R1 = $curJob(14, $cur(1, 10), 'Car deck winch makes grinding noise when lifting.', null, null);
$R2 = $curJob(12, $cur(20), 'Engine oil leak at the oil pan gasket.', 1, null);
$R3 = $curJob(15, $cur(22), 'Coolant hoses brittle and seeping at the clamps.', 4, ['Two lower coolant hoses cracked; clamps corroded.', 'Cooling hose and clamp wear.']);
// awaiting verification for more than 2 days (mechanic 7 with his own attendance session)
$R4 = (object)['id' => 0];
Sim::at($H('2026-11-09 08:00'), 'R4 time in', fn() => $clockIn(7));
Sim::at($H('2026-11-09 08:15'), 'R4 report', function () use ($R4, $U, $freeTruck) { $r = act($U('mp'), 'Maintenance', 'report', ['vehicle_id' => $freeTruck('Car Carrier', 6), 'problem' => 'Hydraulic ramp lowers slowly under load.', 'reported_on' => '2026-11-09', 'mechanic_id' => 7], ['photos' => ['png', 'Inspection Photo', 'truck 6']]); $R4->id = (int)$r['job_id']; });
Sim::at($H('2026-11-09 11:00'), 'R4 inspect', fn() => act($U('mp'), 'Maintenance', 'inspect', ['job_id' => $R4->id, 'findings' => 'Ramp control valve sticking; hydraulic oil dark.', 'diagnosis' => 'Control valve contamination.']));
Sim::at($H('2026-11-09 13:00'), 'R4 cost', fn() => act($U('mp'), 'Maintenance', 'add_cost', ['job_id' => $R4->id, 'cost_type' => 'Outside Service', 'description' => 'Hydraulic control valve cleaning and oil change', 'amount' => '7800.00', 'provider' => 'Malabon Machine Works', 'incurred_on' => '2026-11-09']));
Sim::at($H('2026-11-10 15:00'), 'R4 complete', fn() => act($U('mp'), 'Maintenance', 'complete', ['job_id' => $R4->id, 'repair_performed' => 'Cleaned the control valve, flushed and replaced the hydraulic oil; ramp tested.', 'completed_on' => '2026-11-10'], ['evidence' => ['png', 'Repair Evidence', 'job R4']]));
Sim::at($H('2026-11-10 17:00'), 'R4 time out', fn() => $clockOutIfFree([7]));

// ------------------------------------------------------------------ 5. purchasing within the November budget (Nov 12 .. now)
$wJob = new class($p) { public int $id = 0; public function __construct(private PDO $p) {} public function get(): int {
    return (int)one($this->p, "SELECT j.id FROM maintenance_jobs j JOIN trips t ON t.id=j.trip_id WHERE j.source='Incident' AND t.truck_type='Wing Van' AND j.status IN ('Under Inspection','Under Repair') ORDER BY j.id DESC LIMIT 1")['id']; } };
$wVid = fn() => (int)one($p, "SELECT j.vehicle_id FROM maintenance_jobs j JOIN trips t ON t.id=j.trip_id WHERE j.source='Incident' AND t.truck_type='Wing Van' AND j.status IN ('Under Inspection','Under Repair') ORDER BY j.id DESC LIMIT 1")['vehicle_id'];
$lineId = fn(int $req, int $part) => (int)one($p, 'SELECT id FROM part_request_lines WHERE part_request_id=? AND part_id=?', [$req, $part])['id'];
$poId = fn() => (int)one($p, 'SELECT MAX(id) AS id FROM purchase_orders')['id'];
$poLines = fn(int $po) => many($p, 'SELECT id, quantity FROM purchase_order_lines WHERE purchase_order_id=? ORDER BY id', [$po]);
$SUP = [['SILANGAN DIESEL & BRAKE SUPPLY', 'Valenzuela City'], ['TRI-STAR AUTO ELECTRICAL CENTER', 'Caloocan City'], ['BAGONG PAG-ASA TRUCK PARTS TRADING', 'Malabon City']];
$po = function (int $at, array $sup, array $lines, string $purpose, ?stdClass $req = null) use ($U, $poId): stdClass {
    $O = (object)['id' => 0];
    Sim::at($at, 'PO create', function () use ($O, $U, $sup, $lines, $purpose, $req, $poId) {
        act($U('pur'), 'Purchasing', 'po_create', ['supplier_name' => $sup[0], 'supplier_address' => $sup[1], 'purpose' => $purpose, 'lines' => $lines, 'part_request_id' => $req ? (string)$req->id : '']);
        $O->id = $poId();
    });
    return $O;
};
// W1 (major incident, Under Repair): brake pads released, air dryer cartridge not in stock -> Partially Released
Sim::at($cur(12), 'W1 inspect', function () use ($U, $wJob) { act($U('mp'), 'Maintenance', 'inspect', ['job_id' => $wJob->get(), 'findings' => 'Front right brake pads worn to the backing plate; air dryer cartridge saturated.', 'diagnosis' => 'Brake pad wear with an air system fault.']); });
$QW = (object)['id' => 0];
Sim::at($cur(11), 'W1 parts request', function () use ($QW, $U, $wJob, $wVid, $p) {
    act($U('mp'), 'Purchasing', 'request_create', ['vehicle_id' => $wVid(), 'maintenance_job_id' => $wJob->get(), 'purpose' => 'Brake and air system repair after the SLEX incident', 'lines' => [['part_id' => 4, 'quantity' => 2], ['part_id' => 6, 'quantity' => 1]]]);
    $QW->id = (int)one($p, 'SELECT MAX(id) AS id FROM part_requests')['id'];
});
Sim::at($cur(4), 'W1 parts release', fn() => act($U('pur'), 'Purchasing', 'request_process', ['request_id' => $QW->id, 'release' => [['line_id' => $lineId($QW->id, 4), 'qty' => 2]], 'notes' => 'Air dryer cartridge out of stock; ordered.']));
// R3 (Under Repair): hose clamps out of stock -> Not Available -> PO waiting for Finance
$Q3 = (object)['id' => 0];
Sim::at($cur(19), 'R3 parts request', function () use ($Q3, $U, $R3, $p) { act($U('mp'), 'Purchasing', 'request_create', ['vehicle_id' => $R3->vid, 'maintenance_job_id' => $R3->id, 'purpose' => 'Coolant hose replacement', 'lines' => [['part_id' => 2, 'quantity' => 6]]]); $Q3->id = (int)one($p, 'SELECT MAX(id) AS id FROM part_requests')['id']; });
Sim::at($cur(5), 'R3 not available', fn() => act($U('pur'), 'Purchasing', 'request_process', ['request_id' => $Q3->id, 'not_available' => '1', 'notes' => 'No hose clamps in stock; purchase order prepared.']));
$POA = $po($cur(4, 30), $SUP[0], [['part_id' => 2, 'quantity' => 20, 'unit_price' => '85.00'], ['part_id' => 1, 'quantity' => 2, 'unit_price' => '1450.00']], 'Hose clamps and spare radiator hoses for the cooling system repairs', $Q3);
// R2 (Under Inspection): request not yet processed
Sim::at($cur(2), 'R2 parts request', fn() => act($U('mp'), 'Purchasing', 'request_create', ['vehicle_id' => $R2->vid, 'maintenance_job_id' => $R2->id, 'purpose' => 'Oil pan gasket job: oil and filter', 'lines' => [['part_id' => 8, 'quantity' => 1], ['part_id' => 9, 'quantity' => 2]]]));
// purchase orders across their statuses (Nov 12 - 13)
$t12 = fn(string $hm) => $H('2026-11-12 ' . $hm);
$POB = $po($t12('09:00'), $SUP[0], [['part_id' => 6, 'quantity' => 2, 'unit_price' => '4850.00']], 'Air dryer cartridges for the air system repairs');
Sim::at($t12('11:00'), 'PO B approve', fn() => act($U('fin'), 'Purchasing', 'po_decide', ['po_id' => $POB->id, 'decision' => 'approve']));
$POC = $po($t12('09:30'), $SUP[2], [['part_id' => 11, 'quantity' => 12, 'unit_price' => '385.00'], ['part_id' => 5, 'quantity' => 4, 'unit_price' => '2150.00']], 'Hub seals and rear brake shoes, reorder for the fleet');
$POD = $po($t12('09:45'), $SUP[2], [['part_id' => 7, 'quantity' => 10, 'unit_price' => '640.00']], 'Fuel filters, monthly stock');
Sim::at($t12('11:10'), 'PO D approve', fn() => act($U('fin'), 'Purchasing', 'po_decide', ['po_id' => $POD->id, 'decision' => 'approve']));
Sim::at($t12('15:30'), 'PO D partial receive', fn() => act($U('pur'), 'Purchasing', 'po_receive', ['po_id' => $POD->id, 'receive' => [['line_id' => (int)$poLines($POD->id)[0]['id'], 'qty' => 6]]]));
Sim::at($t12('17:00'), 'PO D partial pay', fn() => act($U('fin'), 'Finance', 'po_pay', ['po_id' => $POD->id, 'amount' => '3840.00', 'supplier_invoice_no' => 'SI-PPT-0' . mt_rand(4100, 4999), 'payment_method' => 'Cash', 'released_on' => '2026-11-12'], ['or_doc' => ['pdf', 'Official Receipt / Payment Confirmation', 'PO D']]));
$POE = $po($t12('10:00'), $SUP[0], [['part_id' => 3, 'quantity' => 6, 'unit_price' => '780.00']], 'Long-life coolant for the cooling system jobs');
Sim::at($t12('11:20'), 'PO E approve', fn() => act($U('fin'), 'Purchasing', 'po_decide', ['po_id' => $POE->id, 'decision' => 'approve']));
Sim::at($t12('16:00'), 'PO E receive', fn() => act($U('pur'), 'Purchasing', 'po_receive', ['po_id' => $POE->id, 'receive' => [['line_id' => (int)$poLines($POE->id)[0]['id'], 'qty' => 6]]]));
$POF = $po($t12('10:15'), $SUP[1], [['part_id' => 12, 'quantity' => 6, 'unit_price' => '320.00']], 'Wiper blades for the car carriers');
Sim::at($t12('11:30'), 'PO F approve', fn() => act($U('fin'), 'Purchasing', 'po_decide', ['po_id' => $POF->id, 'decision' => 'approve']));
Sim::at($t12('14:00'), 'PO F receive', fn() => act($U('pur'), 'Purchasing', 'po_receive', ['po_id' => $POF->id, 'receive' => [['line_id' => (int)$poLines($POF->id)[0]['id'], 'qty' => 6]]]));
Sim::at($t12('16:30'), 'PO F pay', fn() => act($U('fin'), 'Finance', 'po_pay', ['po_id' => $POF->id, 'amount' => '1920.00', 'supplier_invoice_no' => 'SI-TAE-' . mt_rand(7100, 7999), 'payment_method' => 'Cash', 'released_on' => '2026-11-12'], ['or_doc' => ['pdf', 'Official Receipt / Payment Confirmation', 'PO F']]));
$POG = $po($t12('10:30'), $SUP[1], [['part_id' => 9, 'quantity' => 4, 'unit_price' => '1950.00']], 'Engine oil 15W-40');
Sim::at($t12('11:40'), 'PO G approve', fn() => act($U('fin'), 'Purchasing', 'po_decide', ['po_id' => $POG->id, 'decision' => 'approve']));
Sim::at($t12('18:00'), 'PO G close', fn() => act($U('pur'), 'Purchasing', 'po_close', ['po_id' => $POG->id, 'reason' => 'Supplier cannot deliver this month; order closed and a new supplier will be quoted.']));
$POH = $po($t12('10:45'), $SUP[1], [['part_id' => 10, 'quantity' => 1, 'unit_price' => '9800.00']], 'Spare 24 V alternator assembly');
Sim::at($t12('11:50'), 'PO H reject', fn() => act($U('fin'), 'Purchasing', 'po_decide', ['po_id' => $POH->id, 'decision' => 'reject', 'notes' => 'Quotation is for a 12 V unit; request the correct 24 V quotation.']));
// stock count waiting for the Admin (after the wiper blades were received)
Sim::at($t12('18:30'), 'stock count', fn() => act($U('pur'), 'Purchasing', 'count_submit', ['part_id' => 12, 'counted_qty' => 14, 'reason' => 'Two wiper blades found damaged in storage during the shelf count.']));
