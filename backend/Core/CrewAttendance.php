<?php
/*
 * Crew attendance (integrated luis-maintenance-ui + kenneth):
 *  - Admin marks drivers/helpers/mechanics Present/Absent once per calendar date in attendance_daily (DAILY_ATTENDANCE_V1:
 *    dayFor/dayText/planningDay; no permanent Day/Night shifts).
 *  - Dispatch readiness uses Dispatcher Time In/Out sessions (DispatcherAttendance); an Admin "Absent" mark in
 *    attendance_daily still blocks Time In and dispatch.
 *  - shiftFor/shiftText/planningShift only label legacy Day/Night reporting buckets and session rows.
 */
declare(strict_types=1);
core('Settings');

final class CrewAttendance {
    public const SHIFTS = ['Day', 'Night'];
    public const CREW_TYPES = ['Driver', 'Helper'];

    /** ['date' => 'Y-m-d', 'shift' => 'Day'|'Night'] for a Unix time (default: now). */
    public static function shiftFor(?int $ts = null): array {
        $ts ??= time();
        [$day, $night] = self::startHours();
        $h = (int)date('G', $ts);
        // Normal setup: Day start < Night start (default 6 and 18). If an Admin sets them the other way
        // round, the Day shift is the part from the Day start that wraps past midnight.
        $isDay = $day < $night ? ($h >= $day && $h < $night) : ($h >= $day || $h < $night);
        if ($isDay) {
            $date = $day < $night || $h >= $day ? date('Y-m-d', $ts) : date('Y-m-d', strtotime('-1 day', $ts));
            return ['date' => $date, 'shift' => 'Day'];
        }
        // Night: before the Night start hour (early morning) it belongs to the previous date's Night shift.
        $date = $h >= $night ? date('Y-m-d', $ts) : date('Y-m-d', strtotime('-1 day', $ts));
        return ['date' => $date, 'shift' => 'Night'];
    }

    /**
     * Day and Night start hours (Admin settings). Saving identical hours is refused (Admin settings); if an old or
     * manually edited value is identical anyway, the defaults 6 / 18 are used so a Night shift always exists.
     */
    public static function startHours(): array {
        $day = (int)Settings::value('attendance_day_start_hour');
        $night = (int)Settings::value('attendance_night_start_hour');
        if ($day === $night) {
            error_log('CrewAttendance: Day and Night shift start hours are identical (' . $day . '); using 6 and 18.');
            return [6, 18];
        }
        return [$day, $night];
    }

    /**
     * Shifts the Admin bell reminds about at a moment: every shift that has STARTED on the current calendar date
     * (Philippine time) plus the shift in progress (which may have started the previous date and crossed midnight).
     * Works for both orderings of the start hours (Day before Night, or Day after Night), with the same duty
     * dates as shiftFor(). Returns [['Day'|'Night', 'Y-m-d'], ...] without duplicates, earliest start first.
     */
    public static function dueShifts(?int $ts = null): array {
        $ts ??= time();
        [$day, $night] = self::startHours();
        $today = date('Y-m-d', $ts); $h = (int)date('G', $ts);
        $started = [];
        foreach (['Day' => $day, 'Night' => $night] as $shift => $start) if ($h >= $start) $started[$start] = [$shift, $today];
        ksort($started);
        $due = array_values($started);
        $cur = self::shiftFor($ts);
        if (!in_array([$cur['shift'], $cur['date']], $due, true)) array_unshift($due, [$cur['shift'], $cur['date']]);
        return $due;
    }

    /** 12-hour display of a start hour (0-23): 0 -> "12:00 AM", 6 -> "6:00 AM", 12 -> "12:00 PM", 18 -> "6:00 PM". */
    public static function hourLabel(int $h): string {
        return (($h % 12) ?: 12) . ':00 ' . ($h < 12 ? 'AM' : 'PM');
    }

    public const MODE_WARN = 1;
    public const MODE_ENFORCE = 2;
    public static function mode(): int {
        return (int)Settings::value('crew_attendance_dispatch_check') === self::MODE_ENFORCE ? self::MODE_ENFORCE : self::MODE_WARN;
    }
    public static function modeName(?int $mode = null): string { return ($mode ?? self::mode()) === self::MODE_ENFORCE ? 'Enforce' : 'Warn'; }

    /** "Night shift of Nov 8, 2026 (from 6:00 PM)". */
    public static function shiftText(array $s): string {
        [$day, $night] = self::startHours();
        return $s['shift'] . ' shift of ' . date('M j, Y', strtotime($s['date'])) . ' (from ' . self::hourLabel($s['shift'] === 'Day' ? $day : $night) . ')';
    }

    /**
     * Attendance of the trip crew for a moment (default now = the actual dispatch time).
     * Returns ['shift' => shiftFor(ts), 'crew' => [per person: id, name, role, duty_shift, status, problem|null]].
     * $lock: read the attendance rows FOR SHARE inside the dispatch transaction, so an Admin change made at the
     * same moment waits for the dispatch (or the dispatch waits for it) — never a half-old decision.
     */
    public static function dispatchFindings(PDO $p, array $t, ?int $ts = null, bool $lock = false): array {
        core('DispatcherAttendance');$crew=[];
        foreach(['driver_id'=>'Driver','driver2_id'=>'Driver 2','helper_id'=>'Helper'] as $key=>$role){
            if(empty($t[$key]))continue;
            $pe=one($p,'SELECT id,full_name FROM personnel WHERE id=?',[(int)$t[$key]]);if(!$pe)continue;
            $session=DispatcherAttendance::ready($p)?DispatcherAttendance::current($p,(int)$pe['id'],$lock):null;
            $absent=DispatcherAttendance::absent($p,(int)$pe['id'],$session,$lock);
            $present=$session&&empty($session['time_out'])&&$session['dispatcher_role']===($t['dispatcher_role']??null);
            $issue=$absent?'Absent':($present?null:'Not Timed In');
            $crew[]=['id'=>(int)$pe['id'],'name'=>$pe['full_name'],'role'=>$role,'duty_shift'=>null,'status'=>$absent?'Absent':($present?'Present':'Not Timed In'),'issue'=>$issue,'problem'=>$issue?$pe['full_name'].' ('.$role.'): '.$issue.'. Correct attendance before new dispatch.':null];
        }
        return ['shift'=>self::shiftFor($ts),'day'=>self::dayFor($ts),'crew'=>$crew];
    }

    /**
     * Read-only attendance issues for upcoming not-dispatched trips. No permanent resource shifts.
     */
    public static function readiness(PDO $p): array {
        $trips=many($p,"SELECT id,trip_ref,scheduled_at,status,dispatcher_role,driver_id,driver2_id,helper_id FROM trips WHERE status IN ('Pre-Assigned','Ready for Dispatch','For Reschedule') AND scheduled_at<=DATE_ADD(NOW(),INTERVAL 48 HOUR) ORDER BY scheduled_at LIMIT 200");
        $out=[];
        foreach($trips as $trip){$findings=self::dispatchFindings($p,$trip);$issues=array_values(array_filter(array_column($findings['crew'],'problem')));
            if($issues)$out[]=['trip_id'=>(int)$trip['id'],'trip_ref'=>$trip['trip_ref'],'scheduled_at'=>$trip['scheduled_at'],'status'=>$trip['status'],'scheduled_shift'=>'Open attendance sessions','issues'=>$issues];
        }
        return ['mode'=>self::modeName(),'unassigned'=>[],'trips'=>$out]; // No permanent shifts to backfill.
    }

    /** DAILY_ATTENDANCE_V1: the attendance duty date (calendar date, Philippine time) of a moment. */
    public static function dayFor(?int $ts = null): array {
        return ['date' => date('Y-m-d', $ts ?? time())];
    }
    /** Hour the Admin bell starts reminding about today's daily attendance. */
    public static function reminderHour(): int {
        return max(0, min(23, (int)Settings::value('attendance_day_start_hour')));
    }
    /** "Nov 8, 2026". */
    public static function dayText(array $s): string {
        return date('M j, Y', strtotime($s['date']));
    }
    /** The date that decides crew for a trip not dispatched yet: its scheduled date, or today once overdue. */
    public static function planningDay(array $t, ?int $now = null): array {
        $now ??= time();
        $ts = strtotime((string)$t['scheduled_at']) ?: $now;
        return self::dayFor($ts < $now ? $now : $ts);
    }

    /**
     * Legacy reporting bucket for planning response fields. It does not decide personnel eligibility
     * or Dispatcher account responsibility.
     */
    public static function planningShift(array $t, ?int $now = null): array {
        $now ??= time();
        $ts = strtotime((string)$t['scheduled_at']) ?: $now;
        return self::shiftFor($ts < $now ? $now : $ts);
    }

    /**
     * Assignment check of one person for a planning shift: ['name', 'type', 'duty_shift', 'attendance', 'absent', 'issue'].
     * Legacy response keys are retained; permanent shift mismatches no longer restrict personnel.
     */
    public static function assignmentCheck(PDO $p, int $personId, array $shift): ?array {
        core('DispatcherAttendance');$pe=one($p,'SELECT full_name,person_type FROM personnel WHERE id=?',[$personId]);if(!$pe)return null;
        $session=DispatcherAttendance::ready($p)?DispatcherAttendance::current($p,$personId):null;
        $absent=DispatcherAttendance::absent($p,$personId,$session);
        return ['name'=>$pe['full_name'],'type'=>$pe['person_type'],'duty_shift'=>null,'attendance'=>$absent?'Absent':($session?'Present':'Not Timed In'),'absent'=>$absent,'issue'=>null];
    }

    /**
     * Required crew (CHECKLIST_42_CREW_COMPLETENESS_V1), checked at Confirm Dispatch / Redispatch in BOTH modes:
     * Car Carrier = two different active drivers (Driver 1 and Driver 2); Container / Wing Van = one active driver and one
     * active helper. Returns the problems (empty = complete).
     */
    public static function crewCompleteness(PDO $p, array $t): array {
        $need = $t['truck_type'] === 'Car Carrier' ? ['driver_id' => ['Driver 1', 'Driver'], 'driver2_id' => ['Driver 2', 'Driver']]
                                                    : ['driver_id' => ['Driver', 'Driver'], 'helper_id' => ['Helper', 'Helper']];
        $why = []; $seen = [];
        foreach ($need as $col => [$role, $type]) {
            $id = (int)($t[$col] ?? 0);
            if (!$id) { $why[] = 'No ' . $role . ' assigned: a ' . $t['truck_type'] . ' trip needs ' . ($t['truck_type'] === 'Car Carrier' ? 'two drivers' : 'a driver and a helper') . '. The Operations Head assigns the crew.'; continue; }
            if (isset($seen[$id])) { $why[] = 'The same person is assigned as ' . $seen[$id] . ' and ' . $role . '. Assign two different drivers.'; continue; }
            $seen[$id] = $role;
            $pe = one($p, 'SELECT full_name, person_type, active FROM personnel WHERE id=?', [$id]);
            if (!$pe || !(int)$pe['active']) $why[] = $role . ' ' . ($pe['full_name'] ?? '') . ' is not an active ' . strtolower($type) . '.';
            elseif ($pe['person_type'] !== $type) $why[] = $pe['full_name'] . ' is a ' . $pe['person_type'] . ', not a ' . $type . ' (' . $role . ').';
        }
        return $why;
    }

    /** Compatibility response: no permanent personnel shifts remain to backfill. */
    public static function unassignedCrew(PDO $p): array { return []; }
}
