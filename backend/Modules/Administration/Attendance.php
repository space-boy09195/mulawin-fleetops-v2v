<?php
/* CHECKLIST_42_ATTENDANCE_PHASE1_V1: Admin-only attendance record; not availability. */
declare(strict_types=1);
core('Notify');
core('Settings');
core('CrewAttendance');

final class Attendance {
    private static function dateValue(array $d): string {
        $date = (string)($d['duty_date'] ?? date('Y-m-d'));
        $v = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        must($v && $v->format('Y-m-d') === $date, 'Invalid attendance date.');
        must($date <= date('Y-m-d'), 'Cannot mark future attendance.', 409);
        return $date;
    }
    private static function kind(array $d): string {
        return choiceField($d, 'kind', ['Driver', 'Mechanic']);
    }
    /** DAILY_ATTENDANCE_V1: every ACTIVE person is on the roster of the date (no shift). A duty_shift sent by an old client is ignored. */
    private static function roster(PDO $p, string $kind, string $date): array {
        if ($kind === 'Driver') {
            // CHECKLIST_42_ATTENDANCE_PHASE2_V1: the crew roster ('Driver' kind, kept for compatibility) lists drivers AND helpers.
            return many($p, "SELECT pe.id, pe.full_name, pe.person_type, pe.availability, am.status, am.marked_at
              FROM personnel pe LEFT JOIN attendance_daily am ON am.personnel_id=pe.id AND am.duty_date=?
              WHERE pe.active=1 AND pe.person_type IN ('Driver','Helper') ORDER BY pe.person_type, pe.full_name", [$date]);
        }
        return many($p, "SELECT mc.id, mc.full_name, mc.availability, am.status, am.marked_at
          FROM mechanics mc LEFT JOIN attendance_daily am ON am.mechanic_id=mc.id AND am.duty_date=?
          WHERE mc.active=1 ORDER BY mc.full_name", [$date]);
    }
    public static function action(array $u, string $action, array $d): array {
        if(in_array($action,['dispatcher_list','dispatcher_history','time_in','time_out'],true)){
            core('DispatcherAttendance');DispatcherScope::types($u);
            return match($action){'dispatcher_list'=>DispatcherAttendance::read($u,$d),'dispatcher_history'=>DispatcherAttendance::history($u,$d),default=>DispatcherAttendance::record($u,$action,$d)};
        }
        requireRole($u, 'admin');
        return match ($action) {
            'list' => self::listing($d),
            'readiness' => CrewAttendance::readiness(db()),
            'save' => self::save($u,$d),
            default => throw new BusinessRuleException('Unknown attendance action.',404),
        };
    }
    private static function listing(array $d): array {
        $date=self::dateValue($d); $kind=self::kind($d);
        $rows=self::roster(db(),$kind,$date);
        return ['rows'=>$rows,'date'=>$date,'kind'=>$kind,
          'marked'=>count(array_filter($rows,fn($r)=>$r['status']!==null)),'total'=>count($rows),
          'readiness'=>$kind==='Driver' ? CrewAttendance::readiness(db()) : null];
    }
    private static function save(array $u,array $d): array {
        $date=self::dateValue($d); $kind=self::kind($d);
        $items=$d['items']??null;
        must(is_array($items) && count($items)<=1000,'Invalid attendance checklist.');
        return transaction(function(PDO $p) use ($u,$date,$kind,$items) {
            $rows=self::roster($p,$kind,$date);
            $valid=array_fill_keys(array_map(fn($r)=>(int)$r['id'],$rows),true);
            $column=$kind==='Driver'?'personnel_id':'mechanic_id';
            $seen=[]; $count=0;
            foreach($items as $item) {
                must(is_array($item),'Invalid attendance record.');
                $id=(int)($item['id']??0);
                must($id>0 && isset($valid[$id]) && !isset($seen[$id]),'Unknown, duplicated or inactive staff.',409);
                $seen[$id]=true;
                $value=$item['status']??null;
                must(in_array($value,['Present','Absent',null],true),'Attendance must be Present, Absent or blank.');
                $old=one($p,"SELECT id,status FROM attendance_daily WHERE duty_date=? AND $column=? FOR UPDATE",[$date,$id]);
                if ($value===null) {
                    if($old){run($p,'DELETE FROM attendance_daily WHERE id=?',[$old['id']]); audit($p,$u['id'],'Attendance',strtolower($kind).' '.$id,"$date attendance cleared");}
                } elseif($old) {
                    if ($old['status']!==$value) {run($p,'UPDATE attendance_daily SET status=?,marked_by=?,marked_at=NOW(),legacy_shifts=NULL WHERE id=?',[$value,$u['id'],$old['id']]);
                      auditWithChanges($p,$u['id'],'Attendance',strtolower($kind).' '.$id,'attendance updated',$date,['Status'=>$old['status']],['Status'=>$value]);}
                } else {
                    run($p,"INSERT INTO attendance_daily (duty_date,$column,status,marked_by) VALUES (?,?,?,?)",[$date,$id,$value,$u['id']]);
                    auditWithChanges($p,$u['id'],'Attendance',strtolower($kind).' '.$id,'attendance marked',$date,[],['Status'=>$value]);
                }
                if ($kind==='Driver' && ($old['status']??null)!==$value) self::absentNotice($p,$id,$date,$value);
                if($value!==null)$count++;
            }
            // A full roster must be submitted; otherwise partial saving might hide pending people.
            must(count($items)===count($rows),'The roster changed. Reload Attendance and try again.',409);
            if($count===count($rows)) Notify::resolve($p,'att-'.$date.'-'.$kind);
            return ['message'=>"$kind attendance for $date saved ($count/".count($rows)." marked)."];
        });
    }
    /**
     * CHECKLIST_42_ATTENDANCE_ENFORCEMENT_V1: Operations Head notice when crew assigned to a NOT-yet-dispatched trip is
     * marked Absent for that trip's relevant shift: the trip's scheduled shift, or (trip already past its schedule) the
     * current shift. One notice per person / shift / trip (re-opened if the absence is marked again after a correction);
     * resolved when the mark is changed to Present or cleared. Trips and assignments are never changed here.
     */
    private static function absentNotice(PDO $p, int $personId, string $date, ?string $value): void {
        $prefix = 'att-absent-' . $personId . '-' . $date . '-';
        if ($value !== 'Absent') { Notify::resolve($p, $prefix . '*'); return; }
        $trips = many($p, "SELECT t.id, t.trip_ref, t.scheduled_at, a.role FROM trip_assignments a JOIN trips t ON t.id=a.trip_id
                           WHERE a.resource_type='person' AND a.resource_id=? AND t.status IN ('Pre-Assigned','Ready for Dispatch','For Reschedule')", [$personId]);
        if (!$trips) return;
        $name = one($p, 'SELECT full_name FROM personnel WHERE id=?', [$personId])['full_name'] ?? 'Crew';
        $today = CrewAttendance::dayFor()['date'];
        foreach ($trips as $t) {
            $ts = strtotime((string)$t['scheduled_at']);
            $relevant = (CrewAttendance::dayFor($ts)['date'] === $date) || ($ts < time() && $today === $date);
            if (!$relevant) continue;
            $key = $prefix . $t['id'];
            run($p, "UPDATE notifications SET state='Unread', resolved_at=NULL, resolved_by=NULL WHERE dedupe_key=? AND state='Resolved'", [$key]);
            Notify::roles($p, ['operations_head'], 'Assigned crew absent', $name . ' (' . (['driver' => 'Driver', 'driver2' => 'Driver 2', 'helper' => 'Helper'][$t['role']] ?? 'Crew') . ') is marked Absent for '
                . CrewAttendance::dayText(['date' => $date]) . ' but is assigned to ' . $t['trip_ref'] . ' (scheduled ' . date('M j, g:i A', $ts) . '). Reassign the crew before Confirm Dispatch.', 'trips', $key);
        }
    }

    /** Refresh-safe bell reminder. Requires an Admin session and runs from the configured hour (Philippine time) for today's date. */
    public static function remind(PDO $p): void {
        if ((int)date('G') < CrewAttendance::reminderHour()) return;
        $date = CrewAttendance::dayFor()['date'];
        foreach (['Driver','Mechanic'] as $kind) {
            $rows=self::roster($p,$kind,$date);
            $missing=count(array_filter($rows,fn($r)=>$r['status']===null));
            if($missing>0) Notify::roles($p,['admin'],'Attendance required',"$missing ".($kind==='Driver'?'crew (driver/helper)':$kind)." attendance record(s) not marked for $date.",$kind==='Driver'?'crew':'mechanics','att-'.$date.'-'.$kind);
            else Notify::resolve($p,'att-'.$date.'-'.$kind);
        }
    }
}
