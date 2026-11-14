<?php
/*
 * =====================================================================
 *  backend/Core/FleetState.php  —  ONE set of availability rules
 * =====================================================================
 *  Used by Trips, Maintenance and Fleet so the same rule applies everywhere.
 *
 *  TRUCK status (PDF #11 II.B): Available, Assigned, On Trip, Under Maintenance, Unavailable
 *  CREW availability:            Available, On Trip, Unavailable
 *
 *  How availability is kept correct:
 *   1. trip_assignments table: one row per truck/person held by an active trip.
 *      Its PRIMARY KEY makes the DATABASE refuse a second active trip for the
 *      same truck or person (double-booking safeguard).
 *   2. reconcile() recomputes a truck's status from the facts (open repair,
 *      trip on the road, reserved trip, manual Unavailable) after every change,
 *      so the status shown on screen always matches the records.
 * =====================================================================
 */
declare(strict_types=1);

final class FleetState {
    public const STATUSES = ['Available', 'Assigned', 'On Trip', 'Under Maintenance', 'Unavailable'];
    /** Trip statuses where the truck is out on the road. */
    public const ON_ROAD = ['Dispatched', 'On Hold - Incident Reported', 'In Transit'];
    /** Trip statuses that hold a truck and crew (reserved or on the road). */
    public const HOLDING = ['Pre-Assigned', 'Ready for Dispatch', 'Dispatched', 'On Hold - Incident Reported', 'In Transit', 'For Reschedule'];
    /**
     * A Completed trip that still holds its truck and personnel = the truck is driving back
     * and the arrival checklist is not done yet (PDF #11 section G). The truck is "On Trip"
     * until the dispatcher submits the arrival checklist.
     */
    public const RETURNING = 'Completed';
    /** Trip statuses where the truck is away from the garage (on the road or returning). */
    public const AWAY = ['Dispatched', 'On Hold - Incident Reported', 'In Transit', 'Completed'];

    /** Changes a truck status and writes the status log (downtime analytics). */
    public static function setStatus(PDO $p, int $vehicleId, string $to, ?int $actorId, string $reason = ''): void {
        must(in_array($to, self::STATUSES, true), 'Invalid truck status.');
        $v = one($p, 'SELECT status FROM vehicles WHERE id=? FOR UPDATE', [$vehicleId]);
        must((bool)$v, 'Truck not found.', 404);
        if ($v['status'] === $to) return;
        run($p, 'UPDATE vehicles SET status=? WHERE id=?', [$to, $vehicleId]);
        $p->prepare('INSERT INTO vehicle_status_log (vehicle_id, from_status, to_status, reason, actor_id) VALUES (?,?,?,?,?)')
          ->execute([$vehicleId, $v['status'], $to, cut($reason, 255), $actorId]);
        // Record automatic and manual status transitions once, only when status actually changes.
        // The existing status log remains authoritative for downtime reporting.
        $summary = $reason !== '' ? 'Reason: ' . cut($reason, 200) : 'Truck availability recalculated.';
        if ($p->inTransaction()) {
            auditWithChanges($p, $actorId, 'Fleet', 'vehicle ' . $vehicleId, 'truck status changed', $summary,
                ['Status' => $v['status']], ['Status' => $to]);
        } else {
            // Preserve observability for callers not running in a transaction.
            audit($p, $actorId, 'Fleet', 'vehicle ' . $vehicleId, 'truck status changed',
                $v['status'] . ' -> ' . $to . '. ' . $summary);
        }
    }

    /** The active trip holding a truck (on-the-road trip first). */
    public static function activeTrip(PDO $p, int $vehicleId): ?array {
        return one($p, "SELECT t.id, t.status, t.trip_ref FROM trip_assignments a JOIN trips t ON t.id=a.trip_id
                        WHERE a.resource_type='vehicle' AND a.resource_id=? LIMIT 1", [$vehicleId]);
    }

    /** Unresolved corrective maintenance record of a truck (not cancelled and not yet verified). */
    public static function openJob(PDO $p, int $vehicleId): ?array {
        return one($p, "SELECT id, job_ref, source, severity, status FROM maintenance_jobs WHERE vehicle_id=? AND open_key=1 LIMIT 1", [$vehicleId]);
    }

    /**
     * Recomputes the status a truck should have:
     *   unresolved repair record    -> Under Maintenance, until the repair is VERIFIED
     *                                  (an incident not yet assessed, or a Minor roadside repair, keeps it On Trip)
     *   trip on the road            -> On Trip
     *   completed, truck returning  -> On Trip (until the arrival checklist is submitted)
     *   reserved for a trip         -> Assigned
     *   manually Unavailable        -> stays Unavailable
     *   otherwise                   -> Available
     *  "Under Maintenance" always has an open maintenance job behind it, so the
     *  status can never stay stuck after the repair is confirmed.
     */
    public static function reconcile(PDO $p, int $vehicleId, ?int $actorId, string $reason): string {
        $v = one($p, 'SELECT status FROM vehicles WHERE id=? FOR UPDATE', [$vehicleId]);
        must((bool)$v, 'Truck not found.', 404);
        $trip = self::activeTrip($p, $vehicleId);
        $job = one($p, "SELECT id FROM maintenance_jobs WHERE vehicle_id=? AND open_key=1
                        AND NOT (source='Incident' AND (severity IS NULL OR severity='Minor')
                                 AND NOT EXISTS (SELECT 1 FROM incidents i WHERE i.id=maintenance_jobs.incident_id AND i.clearance='Not Fit to Continue')) LIMIT 1", [$vehicleId]);
        if ($job) $next = 'Under Maintenance';
        elseif ($trip && in_array($trip['status'], self::AWAY, true)) $next = 'On Trip';
        elseif ($trip) $next = 'Assigned';
        elseif ($v['status'] === 'Unavailable') $next = 'Unavailable';
        else $next = 'Available';
        self::setStatus($p, $vehicleId, $next, $actorId, $reason);
        return $next;
    }

    /**
     * Holds the truck and crew for a trip. Old rows of the trip are removed first.
     * A duplicate key here means another active trip already holds the resource.
     */
    public static function hold(PDO $p, int $tripId, int $vehicleId, array $people): void {
        self::release($p, $tripId);
        $ins = $p->prepare('INSERT INTO trip_assignments (resource_type, resource_id, trip_id, role) VALUES (?,?,?,?)');
        try {
            $ins->execute(['vehicle', $vehicleId, $tripId, 'truck']);
            foreach ($people as $role => $pid) if ($pid) $ins->execute(['person', (int)$pid, $tripId, $role]);
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) throw new BusinessRuleException('Double booking blocked: the truck or one of the crew is already on another active trip.', 409);
            throw $e;
        }
        foreach ($people as $pid) if ($pid) run($p, "UPDATE personnel SET availability='On Trip' WHERE id=? AND availability='Available'", [(int)$pid]);
    }

    /** Frees the truck and crew of a trip (completed, cancelled, re-assigned). Returns the freed truck id. */
    public static function release(PDO $p, int $tripId): ?int {
        $rows = many($p, 'SELECT resource_type, resource_id FROM trip_assignments WHERE trip_id=?', [$tripId]);
        run($p, 'DELETE FROM trip_assignments WHERE trip_id=?', [$tripId]);
        $vehicle = null;
        foreach ($rows as $r) {
            if ($r['resource_type'] === 'vehicle') { $vehicle = (int)$r['resource_id']; continue; }
            run($p, "UPDATE personnel SET availability='Available' WHERE id=? AND availability='On Trip'", [(int)$r['resource_id']]);
        }
        return $vehicle;
    }

    /** Explicit personnel release. Returning duties must end through the arrival checklist. */
    public static function releaseCrew(PDO $p, int $tripId): void {
        must(!self::awaitingArrival($p, $tripId), 'Personnel remain held until the arrival checklist is completed.', 409);
        $rows = many($p, "SELECT resource_id FROM trip_assignments WHERE trip_id=? AND resource_type='person'", [$tripId]);
        run($p, "DELETE FROM trip_assignments WHERE trip_id=? AND resource_type='person'", [$tripId]);
        foreach ($rows as $r) run($p, "UPDATE personnel SET availability='Available' WHERE id=? AND availability='On Trip'", [(int)$r['resource_id']]);
    }

    /** True when a Completed trip still holds its truck (arrival checklist pending). */
    public static function awaitingArrival(PDO $p, int $tripId): bool {
        return (bool)one($p, "SELECT 1 FROM trip_assignments a JOIN trips t ON t.id=a.trip_id
                              WHERE a.trip_id=? AND a.resource_type='vehicle' AND t.status=?", [$tripId, self::RETURNING]);
    }

    /** Explicit holds, plus returning duties recorded before personnel holds were retained. */
    public static function personTrip(PDO $p, int $personId, ?int $exceptTrip = null, bool $lock = false): ?array {
        $suffix = $lock ? ' FOR UPDATE' : '';
        $held = one($p, "SELECT t.trip_ref, a.trip_id FROM trip_assignments a JOIN trips t ON t.id=a.trip_id
            WHERE a.resource_type='person' AND a.resource_id=? AND a.trip_id<>?".$suffix, [$personId, $exceptTrip ?? 0]);
        if ($held) return $held;
        // Do not recreate historical holds or infer assignments: use the recorded trip personnel
        // only while the Completed trip still has its authoritative pending-arrival vehicle hold.
        return one($p, "SELECT t.trip_ref, t.id AS trip_id FROM trips t
            WHERE t.status='Completed' AND t.id<>? AND (t.driver_id=? OR t.driver2_id=? OR t.helper_id=?)
            AND EXISTS(SELECT 1 FROM trip_assignments a WHERE a.trip_id=t.id AND a.resource_type='vehicle')
            ORDER BY t.id LIMIT 1".$suffix, [$exceptTrip ?? 0, $personId, $personId, $personId]);
    }

    /** Is the person free for a new trip? Returns null when free, otherwise the reason. */
    public static function personBlocker(PDO $p, int $personId, ?int $exceptTrip = null): ?string {
        $pe = one($p, 'SELECT full_name, active, availability, person_type, license_number, license_expiry FROM personnel WHERE id=?', [$personId]);
        if (!$pe || !(int)$pe['active']) return 'not an active driver/helper';
        if ($pe['availability'] === 'Unavailable') return $pe['full_name'] . ' is marked Unavailable';
        $a = self::personTrip($p, $personId, $exceptTrip);
        if ($a) return $pe['full_name'] . ' is already on ' . $a['trip_ref'];
        if ($pe['person_type'] === 'Driver') {
            if (trim((string)$pe['license_number']) === '' || empty($pe['license_expiry'])) return $pe['full_name'] . ' needs a license number and expiry date before trip assignment';
            if ($pe['license_expiry'] < date('Y-m-d')) return $pe['full_name'] . "'s license is expired";
        }
        return null;
    }

    /** Is the truck free for a new trip? Returns null when free, otherwise the reason. */
    public static function truckBlocker(PDO $p, int $vehicleId, ?int $exceptTrip = null): ?string {
        $v = one($p, 'SELECT plate_number, active, status FROM vehicles WHERE id=?', [$vehicleId]);
        if (!$v || !(int)$v['active']) return 'not an active truck';
        if (in_array($v['status'], ['Under Maintenance', 'Unavailable'], true)) return $v['plate_number'] . ' is ' . $v['status'];
        if ($j = self::openJob($p, $vehicleId)) return $v['plate_number'] . ' has an unresolved repair (' . $j['job_ref'] . ', ' . $j['status'] . ($j['status'] === 'Completed' ? ', waiting for verification' : '') . ')';
        $a = one($p, "SELECT t.trip_ref, a.trip_id FROM trip_assignments a JOIN trips t ON t.id=a.trip_id WHERE a.resource_type='vehicle' AND a.resource_id=?", [$vehicleId]);
        if ($a && (int)$a['trip_id'] !== (int)$exceptTrip) return $v['plate_number'] . ' is already on ' . $a['trip_ref'];
        return null;
    }
}
