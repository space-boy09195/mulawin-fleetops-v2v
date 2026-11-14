<?php
/*
 * =====================================================================
 *  backend/Modules/Maintenance/Mechanics.php  —  MECHANIC MASTERLIST
 * =====================================================================
 *  Supports Corrective Maintenance only: repairs are assigned to a mechanic
 *  chosen from this list.
 *   - Mechanic ID (MEC-001) is given by the system; the full name is unique.
 *   - Availability follows MECHANIC ATTENDANCE (Time In / Time Out, table mechanic_attendance):
 *     Unavailable = no open Time In; Available = timed in; Assigned = timed in and on an active repair.
 *     It does not depend on any Day/Night shift. The mechanics.availability column mirrors the state for the
 *     existing screens and is updated in the same transaction as every Time In / Time Out.
 *   - A mechanic can hold only one active repair (database rule), so an
 *     Assigned or Unavailable mechanic cannot receive a new repair, and a mechanic with an
 *     active repair cannot Time Out.
 *   - No permanent Day/Night shift is asked for any more. mechanics.duty_shift is kept as it is
 *     (never cleared, history only): no roster or dispatch rule reads it any more.
 *   - Deactivate instead of delete: the repair history is kept.
 *  Manage: Admin, Motorpool Custodian. View: Operations Head, Audit.
 *  Mechanics do not log in (no separate role is needed by the objectives).
 * =====================================================================
 */
declare(strict_types=1);

final class Mechanics {
    // HEAD_MANAGEMENT_V1 (admin permission policy): mechanic masterlist and Time In / Time Out are Motorpool processing.
    // Head Management monitors them read-only (Admin's own daily Present / Absent marks stay in Administration/Attendance).
    private const EDIT = ['motorpool'];

    public static function read(array $u, string $page, array $q): array {
        $p = db();
        if ($page === 'mechanic_attendance') return self::attendancePage($u, $p, $q);
        $data = readSections([
            'mechanics' => fn() => many($p, "SELECT mc.*, m.id AS job_id, m.job_ref, m.status AS job_status, v.plate_number AS job_truck,
                    (SELECT COUNT(*) FROM maintenance_jobs x WHERE x.mechanic_id=mc.id AND x.status='Completed') AS completed_jobs,
                    (SELECT COUNT(*) FROM mechanic_attendance o WHERE o.mechanic_id=mc.id AND o.time_out IS NULL) AS timed_in,
                    (SELECT la.time_in FROM mechanic_attendance la WHERE la.mechanic_id=mc.id ORDER BY la.id DESC LIMIT 1) AS last_time_in,
                    (SELECT lo.time_out FROM mechanic_attendance lo WHERE lo.mechanic_id=mc.id ORDER BY lo.id DESC LIMIT 1) AS last_time_out
                FROM mechanics mc LEFT JOIN maintenance_jobs m ON m.id=" . self::jobOfSql() . " LEFT JOIN vehicles v ON v.id=m.vehicle_id
                ORDER BY mc.active DESC, mc.full_name"),
        ]);
        $data['can'] = ['edit' => hasRole($u, ...self::EDIT)];
        return $data;
    }

    /**
     * MOTORPOOL_WORKSPACE_PHASE1 — Mechanic Attendance page (read only).
     *   board     active mechanics: availability, open Time In, current repair (+ Admin only: the daily Present/Absent mark of today, information only)
     *   sessions  Time In / Time Out sessions that overlap the chosen dates (query from / to, default today, at most 62 days)
     * Writes stay in time_in / time_out below: this page adds no new way to change attendance or availability.
     */
    private const ATTENDANCE_MAX_DAYS = 62;

    private static function attendancePage(array $u, PDO $p, array $q): array {
        $today = date('Y-m-d');
        $day = function (string $key, string $default) use ($q): string {
            $v = (string)($q[$key] ?? '');
            $d = $v !== '' ? DateTimeImmutable::createFromFormat('!Y-m-d', $v) : false;
            return ($d && $d->format('Y-m-d') === $v) ? $v : $default;
        };
        $from = $day('from', $today); $to = $day('to', $from);
        if ($to < $from) [$from, $to] = [$to, $from];
        $maxFrom = (new DateTimeImmutable($to))->modify('-' . (self::ATTENDANCE_MAX_DAYS - 1) . ' days')->format('Y-m-d');
        if ($from < $maxFrom) $from = $maxFrom;
        $end = (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d');
        $data = readSections([
            'board' => fn() => many($p, "SELECT mc.id, mc.mechanic_code, mc.full_name, mc.specialization, mc.availability,
                    o.time_in AS open_time_in, TIMESTAMPDIFF(MINUTE, o.time_in, NOW()) AS minutes_on_duty,
                    (SELECT lo.time_out FROM mechanic_attendance lo WHERE lo.mechanic_id=mc.id AND lo.time_out IS NOT NULL ORDER BY lo.id DESC LIMIT 1) AS last_time_out,
                    m.id AS job_id, m.job_ref, m.status AS job_status, v.plate_number AS job_truck, ad.status AS daily_mark
                FROM mechanics mc
                LEFT JOIN mechanic_attendance o ON o.mechanic_id=mc.id AND o.time_out IS NULL
                LEFT JOIN maintenance_jobs m ON m.id=" . self::jobOfSql() . " LEFT JOIN vehicles v ON v.id=m.vehicle_id
                LEFT JOIN attendance_daily ad ON ad.mechanic_id=mc.id AND ad.duty_date=CURRENT_DATE()
                WHERE mc.active=1 ORDER BY (o.id IS NULL), mc.full_name"),
            'sessions' => fn() => many($p, "SELECT a.id, a.mechanic_id, mc.mechanic_code, mc.full_name, a.time_in, a.time_out,
                    TIMESTAMPDIFF(MINUTE, a.time_in, COALESCE(a.time_out, NOW())) AS minutes, ui.full_name AS time_in_by, uo.full_name AS time_out_by
                FROM mechanic_attendance a JOIN mechanics mc ON mc.id=a.mechanic_id
                LEFT JOIN users ui ON ui.id=a.time_in_by LEFT JOIN users uo ON uo.id=a.time_out_by
                WHERE a.time_in < ? AND (a.time_out IS NULL OR a.time_out >= ?)
                ORDER BY a.time_in DESC, a.id DESC LIMIT 1000", [$end, $from]),
        ]);
        // The daily Present / Absent mark is an Admin-only record (Administration/Attendance.php): other roles do not receive it.
        if (!hasRole($u, 'admin')) foreach ($data['board'] as &$r) unset($r['daily_mark']);
        unset($r);
        $data['range'] = ['from' => $from, 'to' => $to, 'today' => $today, 'max_days' => self::ATTENDANCE_MAX_DAYS];
        $data['can'] = ['edit' => hasRole($u, ...self::EDIT)];
        return $data;
    }

    public static function action(array $u, string $action, array $d): array {
        return match ($action) {
            'save' => self::save($u, $d),
            'availability' => self::availability($u, $d),
            'time_in' => self::timeIn($u, $d),
            'time_out' => self::timeOut($u, $d),
            'set_active' => self::setActive($u, $d),
            'details' => self::details($u, $d),
            default => throw new BusinessRuleException('Unknown action.', 404),
        };
    }

    private static function save(array $u, array $d): array {
        requireRole($u, ...self::EDIT);
        $id = optId($d, 'id');
        $row = ['full_name' => preg_replace('/\s+/', ' ', field($d, 'full_name', 100)),
                'contact_number' => optField($d, 'contact_number', 40),
                'specialization' => optField($d, 'specialization', 150),
                'notes' => optField($d, 'notes', 255)];
        // No Day/Night shift is requested or written here. An existing duty_shift is left untouched (history only: no screen reads it any more).
        if ($row['contact_number'] !== null) must((bool)preg_match('/^[0-9+()\-\s]{7,40}$/', $row['contact_number']), 'Contact number: digits, spaces, + ( ) - only.');
        return transaction(function (PDO $p) use ($u, $id, $row) {
            must(!one($p, 'SELECT id FROM mechanics WHERE full_name=? AND id<>?', [$row['full_name'], $id ?? 0]), 'A mechanic named ' . $row['full_name'] . ' is already in the masterlist.', 409);
            if ($id) {
                $old = one($p, 'SELECT * FROM mechanics WHERE id=? FOR UPDATE', [$id]);
                must((bool)$old, 'Mechanic not found.', 404);
                $cols = implode(',', array_map(fn($c) => "$c=?", array_keys($row)));
                run($p, "UPDATE mechanics SET $cols WHERE id=?", array_merge(array_values($row), [$id]));
                $before = ['Full name' => $old['full_name'], 'Specialization' => $old['specialization']];
                $after = ['Full name' => $row['full_name'], 'Specialization' => $row['specialization']];
                auditWithChanges($p, $u['id'], 'Mechanics', 'mechanic ' . $id, 'mechanic updated', $row['full_name'], $before, $after);
                return ['id' => $id, 'message' => 'Mechanic information updated.'];
            }
            // A new mechanic is Unavailable until the first Time In.
            $id = insert($p, 'mechanics', ['mechanic_code' => 'TMP-' . bin2hex(random_bytes(4)), 'availability' => 'Unavailable'] + $row);
            $code = 'MEC-' . str_pad((string)$id, 3, '0', STR_PAD_LEFT);
            run($p, 'UPDATE mechanics SET mechanic_code=? WHERE id=?', [$code, $id]);
            auditWithChanges($p, $u['id'], 'Mechanics', 'mechanic ' . $id, 'mechanic added', "$code " . $row['full_name'], [],
                ['Mechanic code' => $code, 'Full name' => $row['full_name'], 'Specialization' => $row['specialization']]);
            return ['id' => $id, 'mechanic_code' => $code, 'message' => "Mechanic $code " . $row['full_name'] . ' added. Status: Unavailable until Time In.'];
        });
    }

    /** The old manual switch is closed: availability now follows Mechanic Attendance only (Time In / Time Out). */
    private static function availability(array $u, array $d): array {
        requireRole($u, ...self::EDIT);
        throw new BusinessRuleException('Availability now follows Mechanic Attendance. Use Time In to make a mechanic Available and Time Out to make a mechanic Unavailable.', 409);
    }

    /** Time In: opens an attendance session and makes the mechanic Available (Assigned if a repair is already active). */
    private static function timeIn(array $u, array $d): array {
        requireRole($u, ...self::EDIT);
        $id = idField($d, 'id');
        return transaction(function (PDO $p) use ($u, $id) {
            $m = one($p, 'SELECT * FROM mechanics WHERE id=? FOR UPDATE', [$id]);
            must($m && (int)$m['active'], 'Mechanic not found or inactive.', 404);
            $open = one($p, 'SELECT time_in FROM mechanic_attendance WHERE mechanic_id=? AND time_out IS NULL FOR UPDATE', [$id]);
            if ($open) throw new BusinessRuleException($m['full_name'] . ' is already timed in (since ' . date('M j, Y g:i A', strtotime((string)$open['time_in'])) . ').', 409);
            try { run($p, 'INSERT INTO mechanic_attendance (mechanic_id, time_in, time_in_by) VALUES (?, NOW(), ?)', [$id, $u['id']]); }
            catch (PDOException $e) {   // the database allows only one open session per mechanic
                if (str_contains($e->getMessage(), 'Duplicate')) throw new BusinessRuleException($m['full_name'] . ' is already timed in.', 409);
                throw $e;
            }
            $busy = self::activeJobRef($p, $id) !== null;
            $to = $busy ? 'Assigned' : 'Available';
            run($p, 'UPDATE mechanics SET availability=?, unavailable_reason=NULL WHERE id=?', [$to, $id]);
            $s = one($p, 'SELECT time_in FROM mechanic_attendance WHERE mechanic_id=? AND time_out IS NULL', [$id]);
            auditWithChanges($p, $u['id'], 'Mechanics', 'mechanic ' . $id, 'mechanic time in', $m['full_name'] . ' timed in',
                ['Availability' => $m['availability']], ['Availability' => $to, 'Time in' => $s['time_in']]);
            return ['message' => $m['full_name'] . ' timed in at ' . date('g:i A', strtotime((string)$s['time_in'])) . '. Status: Available.', 'time_in' => $s['time_in'], 'availability' => $to];
        });
    }

    /** Time Out: closes the open session and makes the mechanic Unavailable. Refused while a repair is active. */
    private static function timeOut(array $u, array $d): array {
        requireRole($u, ...self::EDIT);
        $id = idField($d, 'id');
        return transaction(function (PDO $p) use ($u, $id) {
            $m = one($p, 'SELECT * FROM mechanics WHERE id=? FOR UPDATE', [$id]);
            must($m && (int)$m['active'], 'Mechanic not found or inactive.', 404);
            $open = one($p, 'SELECT id, time_in FROM mechanic_attendance WHERE mechanic_id=? AND time_out IS NULL FOR UPDATE', [$id]);
            must((bool)$open, $m['full_name'] . ' has not timed in, so there is nothing to time out.', 409);
            // Existing rule: an active repair is Reported, Under Inspection or Under Repair (maintenance_jobs.active_mechanic).
            $job = (($ref = self::activeJobRef($p, $id)) !== null) ? ['job_ref' => $ref] : null;
            must(!$job, $m['full_name'] . ' is working on ' . ($job['job_ref'] ?? '') . ' and cannot time out. Complete the repair or assign it to another mechanic first.', 409);
            must(run($p, 'UPDATE mechanic_attendance SET time_out=NOW(), time_out_by=? WHERE id=? AND time_out IS NULL', [$u['id'], $open['id']]) === 1, 'Already timed out.', 409);
            run($p, "UPDATE mechanics SET availability='Unavailable', unavailable_reason=NULL WHERE id=?", [$id]);
            $s = one($p, 'SELECT time_in, time_out FROM mechanic_attendance WHERE id=?', [$open['id']]);
            auditWithChanges($p, $u['id'], 'Mechanics', 'mechanic ' . $id, 'mechanic time out', $m['full_name'] . ' timed out',
                ['Availability' => $m['availability'], 'Time in' => $s['time_in']], ['Availability' => 'Unavailable', 'Time out' => $s['time_out']]);
            return ['message' => $m['full_name'] . ' timed out at ' . date('g:i A', strtotime((string)$s['time_out'])) . '. Status: Unavailable.', 'time_in' => $s['time_in'], 'time_out' => $s['time_out'], 'availability' => 'Unavailable'];
        });
    }

    private static function setActive(array $u, array $d): array {
        requireRole($u, ...self::EDIT);
        $id = idField($d, 'id'); $active = !empty($d['active']) ? 1 : 0;
        return transaction(function (PDO $p) use ($u, $id, $active) {
            $m = one($p, 'SELECT * FROM mechanics WHERE id=? FOR UPDATE', [$id]);
            must((bool)$m, 'Mechanic not found.', 404);
            if (!$active) {
                $job = (($ref = self::activeJobRef($p, $id)) !== null) ? ['job_ref' => $ref] : null;
                must(!$job, $m['full_name'] . ' is working on ' . ($job['job_ref'] ?? '') . '. Assign that repair to another mechanic first.', 409);
            }
            // A deactivated mechanic is no longer at work: an open Time In is closed so it cannot come back as "Available" on reactivation.
            if (!$active) run($p, 'UPDATE mechanic_attendance SET time_out=NOW(), time_out_by=? WHERE mechanic_id=? AND time_out IS NULL', [$u['id'], $id]);
            run($p, "UPDATE mechanics SET active=?, availability='Unavailable', unavailable_reason=NULL WHERE id=?", [$active, $id]);
            $updated = one($p, 'SELECT active, availability FROM mechanics WHERE id=?', [$id]);
            auditWithChanges($p, $u['id'], 'Mechanics', 'mechanic ' . $id, $active ? 'mechanic reactivated' : 'mechanic deactivated', $m['full_name'],
                ['Account status' => (int)$m['active'] ? 'Active' : 'Inactive', 'Availability' => $m['availability']],
                ['Account status' => (int)$updated['active'] ? 'Active' : 'Inactive', 'Availability' => $updated['availability']]);
            return ['message' => $m['full_name'] . ($active ? ' is active again.' : ' was deactivated. The repair history is kept.')];
        });
    }

    /**
     * JOB_HELPER_MECHANICS_V1 — the mechanic's active repair: as lead (maintenance_jobs.active_mechanic) or, once migration 12
     * is applied, as assisting mechanic (maintenance_job_helpers.active_mechanic). Used by Time In / Time Out / deactivate.
     */
    private static function activeJobRef(PDO $p, int $id): ?string {
        $j = one($p, 'SELECT job_ref FROM maintenance_jobs WHERE active_mechanic=?', [$id]);
        if (!$j && self::helpersReady()) $j = one($p, 'SELECT m.job_ref FROM maintenance_job_helpers h JOIN maintenance_jobs m ON m.id=h.maintenance_job_id WHERE h.active_mechanic=?', [$id]);
        return $j['job_ref'] ?? null;
    }
    /** SQL expression: id of the active repair of mechanic mc (lead first, then assisting). */
    private static function jobOfSql(): string {
        $lead = '(SELECT x.id FROM maintenance_jobs x WHERE x.active_mechanic=mc.id)';
        return self::helpersReady() ? "COALESCE($lead, (SELECT h.maintenance_job_id FROM maintenance_job_helpers h WHERE h.active_mechanic=mc.id))" : $lead;
    }
    private static function helpersReady(): bool { Actions::load('Maintenance'); return Maintenance::helpersReady(db()); }

    private static function details(array $u, array $d): array {
        must(Access::canOpen($u, 'mechanics'), 'Not allowed.', 403);
        $id = idField($d, 'id'); $p = db();
        $m = one($p, 'SELECT * FROM mechanics WHERE id=?', [$id]);
        must((bool)$m, 'Mechanic not found.', 404);
        return [
            'mechanic' => $m,
            'jobs' => many($p, "SELECT m.id, m.job_ref, m.source, m.problem, m.status, m.reported_on, m.completed_on, v.plate_number
                FROM maintenance_jobs m JOIN vehicles v ON v.id=m.vehicle_id
                WHERE m.mechanic_id=? OR m.id IN (SELECT maintenance_job_id FROM maintenance_events WHERE mechanic_id=?)
                ORDER BY m.id DESC LIMIT 50", [$id, $id]),
            'attendance' => many($p, "SELECT a.id, a.time_in, a.time_out, ui.full_name AS time_in_by, uo.full_name AS time_out_by
                FROM mechanic_attendance a LEFT JOIN users ui ON ui.id=a.time_in_by LEFT JOIN users uo ON uo.id=a.time_out_by
                WHERE a.mechanic_id=? ORDER BY a.id DESC LIMIT 30", [$id]),
        ];
    }
}
