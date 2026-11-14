<?php
declare(strict_types=1);
core('DispatcherScope');
core('CrewAttendance');

final class DispatcherAttendance {
    public static function ready(PDO $p): bool {
        return (bool)one($p,"SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='dispatcher_attendance_sessions'");
    }
    public static function current(PDO $p, int $id, bool $lock = false): ?array {
        return one($p,'SELECT * FROM dispatcher_attendance_sessions WHERE personnel_id=? AND time_out IS NULL' . ($lock?' FOR UPDATE':''),[$id]);
    }
    public static function isCurrent(array $session, array $shift): bool {
        return empty($session['time_out']); // Open sessions do not expire at shift boundaries or midnight.
    }
    public static function absent(PDO $p,int $id,?array $session=null,bool $lock=false): bool {
        $since=$session['time_in']??date('Y-m-d').' 00:00:00';
        // Admin daily attendance (attendance_daily) is the Absent authority; a back-dated mark for an earlier date does not apply.
        $mark=one($p,'SELECT status FROM attendance_daily WHERE personnel_id=? AND marked_at>=? AND duty_date>=DATE(?) ORDER BY marked_at DESC,id DESC LIMIT 1'.($lock?' LOCK IN SHARE MODE':''),[$id,$since,$since]);
        return ($mark['status']??null)==='Absent';
    }
    public static function blocker(PDO $p, array $u, int $id, ?int $tripId = null, bool $lock = false): ?string {
        $pe=DispatcherScope::person($p,$u,$id,$lock);
        if(!(int)$pe['active'])return 'Personnel record is inactive.';
        if(!self::ready($p))return 'Dispatcher attendance migration has not been installed.';
        $session=self::current($p,$id,$lock);
        if(!$session||!self::isCurrent($session,[])||$session['dispatcher_role']!==$u['role'])return $pe['full_name'].' must have an open Time In session in this operational group.';
        if(self::absent($p,$id,$session,$lock))return $pe['full_name'].' is marked Absent; the authorized attendance record must be corrected.';
        core('FleetState');$reason=FleetState::personBlocker($p,$id,$tripId);
        return $reason&&str_contains($reason,' is already on ')?$pe['full_name'].' is held by another active trip.':$reason;
    }
    public static function read(array $u, array $q=[]): array {
        DispatcherScope::types($u);$p=db();[$scope,$args]=DispatcherScope::personnelSql($u);$ready=self::ready($p);
        $rows=many($p,"SELECT pe.id,pe.full_name,pe.person_type,pe.duty_shift,pe.availability,EXISTS(SELECT 1 FROM trip_assignments a WHERE a.resource_type='person' AND a.resource_id=pe.id) AS assigned FROM personnel pe WHERE pe.active=1 $scope ORDER BY pe.person_type,pe.full_name",$args);
        $shift=CrewAttendance::shiftFor();core('FleetState');
        foreach($rows as &$r){$s=$ready?one($p,'SELECT id,time_in,time_out,duty_date,duty_shift FROM dispatcher_attendance_sessions WHERE personnel_id=? AND dispatcher_role=? ORDER BY id DESC LIMIT 1',[$r['id'],$u['role']]):null;
            $foreignOpen=$ready&&(bool)one($p,'SELECT 1 FROM dispatcher_attendance_sessions WHERE personnel_id=? AND dispatcher_role<>? AND time_out IS NULL',[$r['id'],$u['role']]);
            $r['assigned']=(bool)$r['assigned']||(bool)FleetState::personTrip($p,(int)$r['id']);$r['session']=$s;$r['attendance_status']=$s?(!$s['time_out']?'Timed In':'Timed Out'):'Not Timed In';
            $r['action']=$s&&!$s['time_out']?'Time Out':'Time In';
            $r['can_record']=$ready&&($r['action']==='Time Out'?!$r['assigned']:!self::absent($p,(int)$r['id']));
            $r['action_reason']=$r['can_record']?'':($r['action']==='Time Out'?'Personnel are held by an active trip or returning vehicle.':'Admin attendance conflict must be corrected before Time In.');
            if($foreignOpen){$r['can_record']=false;$r['attendance_status']='Attendance review required';$r['action_reason']='An open attendance session requires authorized coordination before recording attendance in this group.';}
        }unset($r);
        return ['rows'=>$rows,'shift'=>$shift,'schema_ready'=>$ready];
    }
    public static function history(array $u,array $d): array {
        DispatcherScope::types($u);$id=idField($d,'personnel_id');$p=db();$pe=DispatcherScope::person($p,$u,$id);
        must(self::ready($p),'Install the Dispatcher attendance migration first.',409);
        return ['person'=>$pe,'sessions'=>many($p,'SELECT s.id,s.duty_date,s.duty_shift,s.time_in,s.time_out,iu.full_name AS timed_in_by_name,ou.full_name AS timed_out_by_name FROM dispatcher_attendance_sessions s JOIN users iu ON iu.id=s.timed_in_by LEFT JOIN users ou ON ou.id=s.timed_out_by WHERE s.personnel_id=? AND s.dispatcher_role=? ORDER BY s.id DESC LIMIT 500',[$id,$u['role']])];
    }
    public static function record(array $u,string $action,array $d): array {
        DispatcherScope::types($u);must(in_array($action,['time_in','time_out'],true),'Unknown attendance operation.',404);
        $id=idField($d,'personnel_id');$key=strtolower(field($d,'request_key',36));
        must((bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',$key),'Invalid attendance request key.');
        return transaction(function(PDO $p)use($u,$action,$id,$key,$d){
            $pe=DispatcherScope::person($p,$u,$id,true);must((int)$pe['active']===1,'Personnel record is inactive.',409);
            must(self::ready($p),'Install the Dispatcher attendance migration first.',409);
            $column=$action==='time_in'?'request_key':'time_out_key';$old=one($p,"SELECT id,personnel_id,dispatcher_role FROM dispatcher_attendance_sessions WHERE $column=?",[$key]);
            if($old){must((int)$old['personnel_id']===$id&&$old['dispatcher_role']===$u['role'],'Request key belongs to another record.',409);return ['message'=>'Attendance operation was already recorded.','session_id'=>$old['id'],'duplicate'=>true];}
            $s=self::current($p,$id,true);$shift=CrewAttendance::shiftFor();
            if($action==='time_in'){
                must(!$s,'There is already an open attendance session. Refresh the roster.',409);
                must(!self::absent($p,$id,null,true),'Personnel is marked Absent for this shift; an authorized correction is required.',409);
                run($p,'INSERT INTO dispatcher_attendance_sessions(personnel_id,dispatcher_role,duty_date,duty_shift,timed_in_by,request_key) VALUES(?,?,?,?,?,?)',[$id,$u['role'],$shift['date'],$shift['shift'],$u['id'],$key]);$sessionId=(int)$p->lastInsertId();
            }else{
                must($s&&(int)$s['id']===idField($d,'session_id'),'This attendance session has changed. Refresh the roster.',409);
                must($s['dispatcher_role']===$u['role'],'This session belongs to another operational group.',403);
                core('FleetState');
                must(!FleetState::personTrip($p,$id,null,true),'Time Out is blocked while personnel are held by an active trip or returning vehicle.',409);
                run($p,'UPDATE dispatcher_attendance_sessions SET time_out=NOW(),timed_out_by=?,time_out_key=? WHERE id=? AND time_out IS NULL',[$u['id'],$key,$s['id']]);$sessionId=(int)$s['id'];
            }
            audit($p,$u['id'],'Attendance','personnel '.$id,$action==='time_in'?'dispatcher time in':'dispatcher time out','Session #'.$sessionId.'; '.$shift['date'].' '.$shift['shift']);
            return ['message'=>$action==='time_in'?'Time In recorded.':'Time Out recorded. A new Time In can create another session.','session_id'=>$sessionId];
        });
    }
}
