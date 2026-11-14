<?php
/** Run only through run_turnover_tests.php on the verified owned disposable server. */
declare(strict_types=1);
require_once dirname(__DIR__).'/backend/Actions.php';
require_once __DIR__.'/_launcher.php';
$fresh=envValue('DB_NAME');$upgrade=envValue('TEST_DB_UPGRADE');test_guard([$fresh,$upgrade]);
$pass=0;$fail=0;
function turn_ok(bool $ok,string $label):void {global $pass,$fail;$ok?$pass++:$fail++;echo ($ok?'PASS ':'FAIL ').$label.PHP_EOL;}
function turn_denied(callable $fn,int $status,string $label):void {try{$fn();turn_ok(false,$label);}catch(BusinessRuleException $e){turn_ok($e->httpStatus===$status,$label);}}
function turn_key():string {$h=bin2hex(random_bytes(16));return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);}
function turn_sql_rejected(PDO $p,callable $fn,array $codes,string $label):void {
 $p->beginTransaction();
 try{$fn();turn_ok(false,$label);}catch(PDOException $e){turn_ok(in_array((int)($e->errorInfo[1]??0),$codes,true),$label);}
 finally{if($p->inTransaction())$p->rollBack();}
}
test_recreate($fresh);test_recreate($upgrade);$p=db();$up=test_conn($upgrade);
// Match the session SQL mode observed in the read-only local preflight, on test connections only.
foreach([$p,$up] as $connection)$connection->exec("SET SESSION sql_mode='NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION'");
$sql=test_sql('database/extension.sql');$old=explode('-- V4.3: reuse the authoritative vehicle/person relationships;',$sql)[0];
test_import($p,test_sql('database/schema.sql'));test_import($p,$sql);
test_import($up,test_sql('database/schema.sql'));test_import($up,$old);
$sentinel=insert($up,'users',['username'=>'upgrade_sentinel','email'=>'upgrade@example.test','full_name'=>'Upgrade sentinel','password_hash'=>'fixture','role'=>'operations_head']);
turn_ok((int)one($up,"SELECT COUNT(*) AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND ((TABLE_NAME='vehicles' AND COLUMN_NAME='active_fixed_driver_id') OR (TABLE_NAME='trips' AND COLUMN_NAME='helper_substitution_reason'))")['n']===0,'Synthetic upgrade starts before V4.3 prerequisites');
turn_ok(!empty(test_isolation([$fresh])['create_only']),'Turnover tests use create-only database mode');
try{test_recreate($upgrade);turn_ok(false,'Existing databases cannot be replaced');}catch(RuntimeException $e){turn_ok(str_contains($e->getMessage(),'create-only'),'Existing databases cannot be replaced');}
try{test_drop($upgrade);turn_ok(false,'SQL database drops are disabled');}catch(RuntimeException $e){turn_ok(str_contains($e->getMessage(),'disabled'),'SQL database drops are disabled');}
$upDriver=insert($up,'personnel',['full_name'=>'Upgrade Driver','person_type'=>'Driver','license_number'=>'UP-LICENSE','license_expiry'=>'2099-01-01']);
$upVehicle=insert($up,'vehicles',['plate_number'=>'UP-ONE','truck_type'=>'Container','assigned_driver_id'=>$upDriver]);
$upDuplicate=insert($up,'vehicles',['plate_number'=>'UP-TWO','truck_type'=>'Container','assigned_driver_id'=>$upDriver]);
turn_ok((int)one($up,'SELECT COUNT(*) AS n FROM (SELECT assigned_driver_id FROM vehicles WHERE active=1 AND assigned_driver_id IS NOT NULL GROUP BY assigned_driver_id HAVING COUNT(*)>1) conflicts')['n']===1,'Duplicate active fixed-Driver prerequisite conflict is detected before DDL');
$v43=test_sql('database/migrations/2026-11-11_11_operations_v43.sql');
// Duplicate-containing ALTER may return 1834 when existing vehicle FKs prevent duplicate-row removal.
try{test_import($up,$v43);turn_ok(false,'V4.3 refuses conflicting active fixed-Driver assignments');}catch(PDOException $e){turn_ok(in_array((int)($e->errorInfo[1]??0),[1062,1834],true),'V4.3 refuses conflicting active fixed-Driver assignments (MariaDB code '.(int)($e->errorInfo[1]??0).')');}
turn_ok((int)one($up,'SELECT COUNT(*) AS n FROM vehicles')['n']===2,'Failed V4.3 DDL preserves both synthetic vehicle records');
turn_ok((int)one($up,"SELECT COUNT(*) AS n FROM schema_migrations WHERE id='2026-11-11_11_operations_v43'")['n']===0,'Failed V4.3 DDL does not record a successful migration');
// Resolve only this deliberately conflicting test fixture; no operational record is read or changed.
run($up,'UPDATE vehicles SET active=0 WHERE id=?',[$upDuplicate]);
test_import($up,$v43);
turn_ok((int)one($up,"SELECT COUNT(*) AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND ((TABLE_NAME='vehicles' AND COLUMN_NAME='active_fixed_driver_id') OR (TABLE_NAME='trips' AND COLUMN_NAME='helper_substitution_reason'))")['n']===2,'V4.3 installs the generated Driver key and Helper substitution column');
turn_ok((int)one($up,"SELECT COUNT(*) AS n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='vehicles' AND INDEX_NAME='uq_vehicle_active_fixed_driver' AND NON_UNIQUE=0")['n']===1,'V4.3 installs the active fixed-Driver unique index');
turn_sql_rejected($up,fn()=>run($up,'UPDATE vehicles SET active=1 WHERE id=?',[$upDuplicate]),[1062],'V4.3 unique index rejects a second active Truck for the same fixed Driver');
$migration=test_sql('database/migrations/2026-11-12_12_dispatcher_turnover.sql');test_import($up,$migration);test_import($up,$v43);test_import($up,$migration);
turn_ok((int)one($up,"SELECT COUNT(*) AS n FROM schema_migrations WHERE id IN ('2026-11-11_11_operations_v43','2026-11-12_12_dispatcher_turnover')")['n']===2,'Sequential migrations 11 and 12 record both version markers');
turn_ok((int)one($up,'SELECT COUNT(*) AS n FROM users WHERE id=?',[$sentinel])['n']===1,'Upgrade preserves existing records and migration is repeatable');
turn_ok((int)one($up,'SELECT COUNT(*) AS n FROM vehicles')['n']===2&&one($up,'SELECT license_number FROM personnel WHERE id=?',[$upDriver])['license_number']==='UP-LICENSE','Sequential upgrade preserves synthetic master records and license details');
test_import($up,test_sql('database/migrations/2026-11-13_13_fixed_route_fuel.sql'));test_import($up,test_sql('database/migrations/2026-11-14_14_incident_clearance.sql')); // later migration, once (not repeatable)
turn_ok(test_fingerprint($p,$fresh)===test_fingerprint($up,$upgrade),'Fresh and upgrade schemas match, including constraints, indexes and foreign keys');
Actions::load('Trips');Actions::load('Fleet');Actions::load('Attendance');Actions::load('Mechanics');core('DispatcherTurnover');core('Settings');
$users=[];
foreach(['ops'=>['operations_head',null],'day'=>['container_wingvan_dispatcher','Day'],'night'=>['container_wingvan_dispatcher','Night'],'other'=>['car_carrier_dispatcher','Night'],'admin'=>['admin',null],'third'=>['container_wingvan_dispatcher','Day'],'motor'=>['motorpool',null]] as $key=>[$role,$shift]){
 $id=insert($p,'users',['username'=>'turn_'.$key,'email'=>$key.'@example.test','full_name'=>'Fixture '.$key,'password_hash'=>'fixture','role'=>$role,'shift'=>$shift]);$users[$key]=['id'=>$id,'role'=>$role,'shift'=>$shift];
}
[$ops,$day,$night,$other,$admin,$third,$motor]=array_values($users);
// Exercise database enforcement directly on both the fresh and upgraded definitions.
foreach(['fresh'=>[$p,$day['id'],$night['id']],'upgrade'=>[$up,$sentinel,insert($up,'users',['username'=>'upgrade_recipient','email'=>'recipient@example.test','full_name'=>'Upgrade recipient','password_hash'=>'fixture','role'=>'container_wingvan_dispatcher','shift'=>'Night'])]] as $schema=>[$connection,$outgoing,$incoming]){
 $base=['outgoing_id'=>$outgoing,'incoming_id'=>$incoming,'dispatcher_role'=>'container_wingvan_dispatcher','status'=>'Accepted','endorsed_at'=>'2026-11-12 10:00:00','accepted_at'=>'2026-11-12 11:00:00','accepted_by'=>$incoming];
 foreach(['missing actor'=>['accepted_by'=>null],'missing timestamp'=>['accepted_at'=>null],'wrong actor'=>['accepted_by'=>$outgoing],'timestamp before endorsement'=>['accepted_at'=>'2026-11-12 09:59:59'],'zero timestamp'=>['accepted_at'=>'0000-00-00 00:00:00'],'zero endorsement and acceptance'=>['endorsed_at'=>'0000-00-00 00:00:00','accepted_at'=>'0000-00-00 00:00:00'],'Pending with acceptance fields'=>['status'=>'Pending']] as $label=>$invalid){
  turn_sql_rejected($connection,fn()=>insert($connection,'dispatcher_turnovers',array_replace($base,$invalid)+['request_key'=>turn_key()]),[3819,4025,1292],$schema.' CHECK rejects '.$label);
 }
 turn_sql_rejected($connection,fn()=>insert($connection,'dispatcher_turnovers',array_replace($base,['incoming_id'=>2147483647,'accepted_by'=>2147483647])+['request_key'=>turn_key()]),[1452],$schema.' foreign keys reject a nonexistent accepting account');
 foreach(['valid Accepted'=>$base,'valid Pending'=>array_replace($base,['status'=>'Pending','accepted_at'=>null,'accepted_by'=>null])] as $label=>$valid){
  $connection->beginTransaction();try{$validId=insert($connection,'dispatcher_turnovers',$valid+['request_key'=>turn_key()]);turn_ok($validId>0,$schema.' accepts '.$label);}finally{$connection->rollBack();}
 }
}
$client=insert($p,'clients',['company_name'=>'Turnover fixture','status'=>'Active','created_by'=>$ops['id']]);
$driver=insert($p,'personnel',['full_name'=>'Fixture Driver','person_type'=>'Driver','license_number'=>'TURN-DRV','license_expiry'=>'2099-01-01']);
$helper=insert($p,'personnel',['full_name'=>'Fixture Helper','person_type'=>'Helper']);
$vid=insert($p,'vehicles',['plate_number'=>'TURN-01','truck_type'=>'Container','assigned_driver_id'=>$driver,'assigned_helper_id'=>$helper]);
$trip=insert($p,'trips',['trip_ref'=>'TURN-TRIP','intake_source'=>'Operations Head','created_by'=>$ops['id'],'client_id'=>$client,'truck_type'=>'Container','dispatcher_role'=>$day['role'],'dispatcher_shift'=>'Day','dispatcher_id'=>$day['id'],'pickup_point'=>'Fixture origin','delivery_point'=>'Fixture destination','scheduled_at'=>date('Y-m-d H:i:s',time()-7200),'status'=>'In Transit','vehicle_id'=>$vid,'driver_id'=>$driver,'helper_id'=>$helper,'dispatched_at'=>date('Y-m-d H:i:s',time()-3600)]);
transaction(function(PDO $p)use($trip,$vid,$driver,$helper){FleetState::hold($p,$trip,$vid,['driver'=>$driver,'helper'=>$helper]);FleetState::reconcile($p,$vid,null,'Fixture dispatch');});
$original=one($p,'SELECT dispatcher_role,dispatcher_shift,dispatcher_id FROM trips WHERE id=?',[$trip]);
turn_denied(fn()=>Trips::action($day,'turnover_assign_initial',['trip_id'=>$trip,'dispatcher_id'=>$day['id']]),403,'Dispatcher cannot assign initial responsibility');
turn_denied(fn()=>Trips::action($ops,'turnover_assign_initial',['trip_id'=>$trip,'dispatcher_id'=>$other['id']]),403,'Operations Head cannot assign an account from the wrong truck group');
Trips::action($ops,'turnover_assign_initial',['trip_id'=>$trip,'dispatcher_id'=>$day['id']]);
turn_ok(count(Trips::read($day,'trips',[])['active'])===1&&count(Trips::read($night,'trips',[])['active'])===0,'Server read projection follows the explicit responsible account');
turn_denied(fn()=>Trips::action($ops,'turnover_assign_initial',['trip_id'=>$trip,'dispatcher_id'=>$night['id']]),409,'Initial account assignment cannot silently overwrite ownership');
$progress=['trip_id'=>$trip,'update_type'=>'Progress','note'=>'Fixture progress'];
turn_denied(fn()=>Trips::action($night,'progress',$progress),403,'Incoming account has no premature mutation access');
$preview=Trips::action($day,'turnover_preview',[]);turn_ok(count($preview['rows'])===1,'Outgoing reviews authoritative work and held resources');
$endorse=['incoming_id'=>$night['id'],'trip_ids'=>array_column($preview['rows'],'id'),'fingerprint'=>$preview['fingerprint'],'request_key'=>turn_key(),'remarks'=>'Fixture turnover'];
$id=Trips::action($day,'turnover_endorse',$endorse)['turnover_id'];
turn_ok((int)one($p,'SELECT current_dispatcher_id FROM trip_responsibilities WHERE trip_id=?',[$trip])['current_dispatcher_id']===$day['id'],'Pending turnover preserves outgoing responsibility');
turn_ok((int)Trips::action($day,'turnover_endorse',$endorse)['turnover_id']===(int)$id,'Retry endorsement uses the original record');
turn_denied(fn()=>Trips::action($third,'turnover_details',['turnover_id'=>$id]),403,'Unaddressed same-group account cannot review turnover');
turn_denied(fn()=>Trips::action($other,'turnover_accept',['turnover_id'=>$id]),403,'Other truck group cannot accept turnover');
turn_denied(fn()=>Trips::action($third,'turnover_accept',['turnover_id'=>$id]),403,'Another same-group account cannot accept turnover');
turn_ok(count(Trips::action($night,'turnover_details',['turnover_id'=>$id])['rows'])===1,'Named incoming account reviews referenced work');
turn_denied(fn()=>Trips::action($night,'progress',$progress),403,'Pending summary review still does not grant processing');
turn_denied(fn()=>Trips::action($night,'turnover_accept',['turnover_id'=>$id,'trip_ids'=>[$trip]]),409,'Partial-acceptance payloads are rejected without silently accepting all work');
run($p,'UPDATE users SET active=0 WHERE id=?',[$night['id']]);turn_denied(fn()=>Trips::action($night,'turnover_accept',['turnover_id'=>$id]),403,'An inactive named recipient cannot accept turnover');run($p,'UPDATE users SET active=1 WHERE id=?',[$night['id']]);
$competing=$endorse;$competing['request_key']=turn_key();turn_denied(fn()=>Trips::action($day,'turnover_endorse',$competing),409,'Conflicting pending turnover is rejected');
Trips::action($day,'progress',$progress);turn_ok(true,'Outgoing can continue authorized work before acceptance');
$workers=[];for($i=0;$i<2;$i++){
 $proc=proc_open([PHP_BINARY,__DIR__.'/turnover_concurrency_worker.php',json_encode(['user_id'=>$night['id'],'turnover_id'=>$id])],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
 fclose($pipes[0]);$workers[]=[$proc,$pipes];
}
$concurrent=[];foreach($workers as [$proc,$pipes]){$json=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($proc);$concurrent[]=json_decode($json,true);turn_ok($exit===0&&$err==='','Concurrent recipient acceptance completes safely');}
turn_ok(count(array_filter($concurrent,fn($r)=>!empty($r['duplicate'])))===1,'Exactly one concurrent acceptance transfers responsibility; the other is an idempotent retry');
turn_ok(!empty(Trips::action($night,'turnover_accept',['turnover_id'=>$id])['duplicate']),'Repeated acceptance is idempotent');
turn_denied(fn()=>Trips::action($day,'progress',$progress),403,'Outgoing mutations are denied after acceptance');
Trips::action($night,'progress',$progress);turn_ok(true,'Incoming Dispatcher records actual progress after acceptance');
turn_ok(count(Trips::read($night,'trips',[])['active'])===1&&count(Trips::read($day,'trips',[])['active'])===0,'Transferred trips move between account projections without changing original Day/Night metadata');
turn_ok(one($p,'SELECT dispatcher_role,dispatcher_shift,dispatcher_id FROM trips WHERE id=?',[$trip])===$original,'Original planning group/shift and dispatch actor are preserved');
turn_ok((int)one($p,'SELECT actor_id FROM trip_updates ORDER BY id DESC LIMIT 1')['actor_id']===$night['id'],'Progress records the actual incoming actor');
$reverse=Trips::action($night,'turnover_preview',[]);$rid=Trips::action($night,'turnover_endorse',['incoming_id'=>$day['id'],'trip_ids'=>array_column($reverse['rows'],'id'),'fingerprint'=>$reverse['fingerprint'],'request_key'=>turn_key()])['turnover_id'];Trips::action($day,'turnover_accept',['turnover_id'=>$rid]);
turn_ok((int)one($p,'SELECT version FROM trip_responsibilities WHERE trip_id=?',[$trip])['version']===3,'Reverse turnover extends the responsibility chain without duplicate trips');
foreach([$driver,$helper] as $pid){$s=Attendance::action($day,'time_in',['personnel_id'=>$pid,'request_key'=>turn_key()]);run($p,'UPDATE dispatcher_attendance_sessions SET time_in=?,duty_date=?,duty_shift=? WHERE id=?',[date('Y-m-d H:i:s',time()-172800),'2026-01-01','Night',$s['session_id']]);}
turn_ok(DispatcherAttendance::blocker($p,$day,$driver,$trip)===null&&DispatcherAttendance::blocker($p,$night,$helper,$trip)===null,'Shift-free Driver/Helper sessions remain valid across midnight, dates and Dispatcher accounts');
insert($p,'attendance_daily',['duty_date'=>date('Y-m-d'),'personnel_id'=>$driver,'status'=>'Absent','marked_by'=>$admin['id']]); // integrated: Admin daily attendance is the Absent authority
turn_ok(str_contains((string)DispatcherAttendance::blocker($p,$day,$driver,$trip),'Absent'),'Admin absence conflict blocks assignment despite an open Time In');
run($p,"UPDATE attendance_daily SET status='Present' WHERE personnel_id=?",[$driver]);
turn_ok(DispatcherAttendance::blocker($p,$day,$driver,$trip)===null,'Authorized attendance correction restores otherwise valid eligibility');
run($p,"UPDATE personnel SET license_expiry='2000-01-01' WHERE id=?",[$driver]);turn_ok(str_contains((string)DispatcherAttendance::blocker($p,$day,$driver,$trip),'license'),'Open attendance never bypasses expired Driver licensing');run($p,"UPDATE personnel SET license_expiry='2099-01-01' WHERE id=?",[$driver]);
run($p,'UPDATE personnel SET active=0 WHERE id=?',[$helper]);turn_ok(DispatcherAttendance::blocker($p,$day,$helper,$trip)!==null,'Open attendance never bypasses inactive personnel');run($p,'UPDATE personnel SET active=1 WHERE id=?',[$helper]);
foreach([Trips::DOC_WAYBILL,Trips::DOC_AR,Trips::DOC_DR] as $category)insert($p,'documents',['category'=>$category,'entity_type'=>'trip','entity_id'=>$trip,'original_filename'=>'fixture.txt','storage_name'=>bin2hex(random_bytes(8)),'mime_type'=>'text/plain','byte_size'=>1,'uploaded_by'=>$day['id']]);
insert($p,'billings',['trip_id'=>$trip,'client_id'=>$client,'distance_km'=>25,'rate_per_km'=>50,'base_amount'=>1250,'total_amount'=>1250]);
$nightWork=Trips::action($day,'turnover_preview',[]);$nightId=Trips::action($day,'turnover_endorse',['incoming_id'=>$night['id'],'trip_ids'=>array_column($nightWork['rows'],'id'),'fingerprint'=>$nightWork['fingerprint'],'request_key'=>turn_key()])['turnover_id'];Trips::action($night,'turnover_accept',['turnover_id'=>$nightId]);
Trips::action($night,'complete',['trip_id'=>$trip,'completed_at'=>date('Y-m-d\TH:i'),'waybill_number'=>'TURN-WB']);
turn_ok((int)one($p,"SELECT actor_id FROM trip_events WHERE trip_id=? AND event_type='trip completed' ORDER BY id DESC LIMIT 1",[$trip])['actor_id']===$night['id'],'Night Dispatcher completes the Day-dispatched trip with the actual completion actor');
turn_ok(one($p,'SELECT status FROM billings WHERE trip_id=?',[$trip])['status']==='For Billing','Completion retains the existing billing transition while holding returning personnel');
turn_ok((int)one($p,"SELECT COUNT(*) AS n FROM trip_assignments WHERE trip_id=? AND resource_type='person'",[$trip])['n']===2,'Delivery completion retains all returning personnel holds');
turn_ok(one($p,'SELECT status FROM vehicles WHERE id=?',[$vid])['status']==='On Trip','Truck remains On Trip pending arrival');
run($p,"DELETE FROM trip_assignments WHERE trip_id=? AND resource_type='person'",[$trip]);
turn_ok(FleetState::personBlocker($p,$driver)!==null&&FleetState::personBlocker($p,$helper)!==null,'Legacy returning trips remain blocked even if their personnel holds were previously released');
foreach(['driver'=>$driver,'helper'=>$helper] as $role=>$pid)run($p,"INSERT INTO trip_assignments(resource_type,resource_id,trip_id,role) VALUES('person',?,?,?)",[$pid,$trip,$role]);
turn_denied(fn()=>Trips::action($night,'complete',['trip_id'=>$trip,'completed_at'=>date('Y-m-d\TH:i'),'waybill_number'=>'TURN-WB']),409,'Duplicate completion is rejected');
$sid=DispatcherAttendance::current($p,$driver)['id'];turn_denied(fn()=>Attendance::action($night,'time_out',['personnel_id'=>$driver,'session_id'=>$sid,'request_key'=>turn_key()]),409,'Returning personnel cannot Time Out before duty release');
$return=Trips::action($night,'turnover_preview',[]);$aid=Trips::action($night,'turnover_endorse',['incoming_id'=>$day['id'],'trip_ids'=>array_column($return['rows'],'id'),'fingerprint'=>$return['fingerprint'],'request_key'=>turn_key()])['turnover_id'];Trips::action($day,'turnover_accept',['turnover_id'=>$aid]);
$returnAgain=Trips::action($day,'turnover_preview',[]);$back=Trips::action($day,'turnover_endorse',['incoming_id'=>$night['id'],'trip_ids'=>array_column($returnAgain['rows'],'id'),'fingerprint'=>$returnAgain['fingerprint'],'request_key'=>turn_key()])['turnover_id'];Trips::action($night,'turnover_accept',['turnover_id'=>$back]);
turn_ok(count(Trips::action($night,'turnover_details',['turnover_id'=>$aid])['rows'])===1,'Completed delivery with pending arrival is included in turnover');
require_once dirname(__DIR__).'/backend/Modules/Operations/Inspections/Checklist.php';$items=[];foreach(Checklist::sections() as $section)foreach($section['items'] as $i=>$label)$items[$section['key'].'_'.str_pad((string)($i+1),2,'0',STR_PAD_LEFT)]=['condition'=>'OK','remarks'=>''];
insert($p,'checklist_starts',['trip_id'=>$trip,'attempt_number'=>1,'kind'=>'Arrival','started_by'=>$night['id'],'started_at'=>date('Y-m-d H:i:s',time()-7200)]);
$arrival=['trip_id'=>$trip,'items'=>$items,'fuel_level'=>'Full'];Trips::action($night,'arrival',$arrival);
turn_ok((int)one($p,'SELECT COUNT(*) AS n FROM trip_assignments WHERE trip_id=?',[$trip])['n']===0,'Incoming arrival All OK releases vehicle and personnel holds');
turn_ok(one($p,'SELECT status FROM vehicles WHERE id=?',[$vid])['status']==='Available','Arrival reconciliation releases the truck when no other restriction exists');
turn_denied(fn()=>Trips::action($night,'arrival',$arrival),409,'Duplicate arrival cannot create another inspection or double-release');
turn_ok((int)one($p,"SELECT COUNT(*) AS n FROM inspections WHERE trip_id=? AND kind='Arrival'",[$trip])['n']===1,'Arrival inspection uniqueness is preserved');
Attendance::action($night,'time_out',['personnel_id'=>$driver,'session_id'=>$sid,'request_key'=>turn_key()]);
$session=one($p,'SELECT * FROM dispatcher_attendance_sessions WHERE id=?',[$sid]);turn_ok((int)$session['timed_in_by']===$day['id']&&(int)$session['timed_out_by']===$night['id'],'Incoming Time Out preserves original Time In and both actors');
turn_ok(str_contains((string)DispatcherAttendance::blocker($p,$night,$driver),'Time In'),'Timed Out personnel cannot be assigned');
$mechanic=Mechanics::action($motor,'save',['full_name'=>'Fixture Mechanic'])['id']; // integrated: the Motorpool Custodian owns the mechanic masterlist
$roster=Attendance::action($admin,'list',['kind'=>'Mechanic','duty_date'=>date('Y-m-d'),'duty_shift'=>'Day']);turn_ok(in_array($mechanic,array_map('intval',array_column($roster['rows'],'id')),true),'Mechanic attendance includes active staff without a permanent shift');
Attendance::action($admin,'save',['kind'=>'Mechanic','duty_date'=>date('Y-m-d'),'duty_shift'=>'Day','items'=>array_map(fn($r)=>['id'=>$r['id'],'status'=>'Present'],$roster['rows'])]);
turn_ok(one($p,'SELECT status FROM attendance_daily WHERE mechanic_id=?',[$mechanic])['status']==='Present','Admin mechanic attendance remains functional and auditable');
// A second, isolated completed return exercises the existing NOT OK processing with a real fixture upload.
$badTrip=insert($p,'trips',['trip_ref'=>'TURN-NOT-OK','intake_source'=>'Operations Head','created_by'=>$ops['id'],'client_id'=>$client,'truck_type'=>'Container','dispatcher_role'=>$day['role'],'dispatcher_shift'=>'Day','pickup_point'=>'Fixture origin','delivery_point'=>'Fixture destination','scheduled_at'=>date('Y-m-d H:i:s'),'status'=>'Completed','vehicle_id'=>$vid,'driver_id'=>$driver,'helper_id'=>$helper]);
Trips::action($ops,'turnover_assign_initial',['trip_id'=>$badTrip,'dispatcher_id'=>$night['id']]);
transaction(fn(PDO $p)=>FleetState::hold($p,$badTrip,$vid,['driver'=>$driver,'helper'=>$helper]));
insert($p,'checklist_starts',['trip_id'=>$badTrip,'attempt_number'=>1,'kind'=>'Arrival','started_by'=>$night['id'],'started_at'=>date('Y-m-d H:i:s',time()-7200)]);
$badItems=$items;$firstKey=array_key_first($badItems);$badItems[$firstKey]=['condition'=>'NOT OK','remarks'=>'Fixture defect'];
$photo=dirname(__DIR__).'/storage/fixture-arrival.png';file_put_contents($photo,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jB0kAAAAASUVORK5CYII='));
$_FILES=['photos'=>['name'=>'fixture.png','tmp_name'=>$photo,'size'=>filesize($photo),'error'=>UPLOAD_ERR_OK,'type'=>'image/png']];
Trips::action($night,'arrival',['trip_id'=>$badTrip,'items'=>$badItems,'fuel_level'=>'Full','damage_notes'=>'Fixture damage']);$_FILES=[];
turn_ok(one($p,'SELECT status FROM vehicles WHERE id=?',[$vid])['status']==='Under Maintenance','Arrival NOT OK keeps the truck restricted for corrective maintenance');
turn_ok((int)one($p,'SELECT COUNT(*) AS n FROM maintenance_jobs WHERE vehicle_id=? AND open_key=1',[$vid])['n']===1,'Arrival NOT OK creates exactly one open maintenance record');
turn_ok(FleetState::truckBlocker($p,$vid)!==null,'A truck under maintenance remains ineligible');
turn_denied(fn()=>Trips::action($night,'arrival',['trip_id'=>$badTrip,'items'=>$badItems,'fuel_level'=>'Full','damage_notes'=>'Fixture damage']),409,'Repeated NOT OK arrival cannot create duplicate repairs');
Actions::load('Maintenance');run($p,"UPDATE maintenance_jobs SET mechanic_id=?,status='Under Inspection' WHERE vehicle_id=? AND open_key=1",[$mechanic,$vid]);
Maintenance::refreshMechanic($p,$mechanic);
turn_ok(one($p,'SELECT availability FROM mechanics WHERE id=?',[$mechanic])['availability']==='Assigned','Present mechanic remains Assigned while a repair is active');
$freeMechanic=new ReflectionMethod(Maintenance::class,'freeMechanic');turn_denied(fn()=>$freeMechanic->invoke(null,$p,$mechanic),409,'Mechanic attendance never bypasses active repair assignment');
turn_ok(count(Trips::action($ops,'turnover_list',[])['rows'])===5,'Operations Head monitors the complete turnover chain without processing trips');
foreach(['finance','billing','motorpool','audit','admin','purchasing'] as $role)turn_denied(fn()=>Trips::action(['id'=>$admin['id'],'role'=>$role],'turnover_list',[]),403,'Unauthorized '.$role.' cannot access turnover data');
$readinessTrip=insert($p,'trips',['trip_ref'=>'TURN-READINESS','intake_source'=>'Operations Head','created_by'=>$ops['id'],'client_id'=>$client,'truck_type'=>'Container','dispatcher_role'=>$day['role'],'dispatcher_shift'=>'Day','pickup_point'=>'Fixture origin','delivery_point'=>'Fixture destination','scheduled_at'=>date('Y-m-d H:i:s'),'status'=>'Pre-Assigned','driver_id'=>$driver,'helper_id'=>$helper]);
$readiness=CrewAttendance::readiness($p);
turn_ok(in_array($readinessTrip,array_column($readiness['trips'],'trip_id'),true)&&str_contains(json_encode($readiness['trips']),'Not Timed In'),'Readiness reports real open-session issues rather than retired shift mismatches');
turn_ok((int)one($p,'SELECT COUNT(*) AS n FROM trips')['n']===3,'Only the three deliberately created trip fixtures exist; turnovers never duplicate trips');

// New assignments must enforce license details even when the personnel are Timed In.
$licenseDriver=insert($p,'personnel',['full_name'=>'License Assignment Driver','person_type'=>'Driver','license_number'=>'PHASE-A-LICENSE','license_expiry'=>'2099-01-01']);
$licenseHelper=insert($p,'personnel',['full_name'=>'License Assignment Helper','person_type'=>'Helper']);
$licenseTruck=insert($p,'vehicles',['plate_number'=>'LICENSE-TRUCK','truck_type'=>'Container','assigned_driver_id'=>$licenseDriver,'assigned_helper_id'=>$licenseHelper]);
$licenseTrip=insert($p,'trips',['trip_ref'=>'LICENSE-ASSIGNMENT','intake_source'=>'Operations Head','created_by'=>$ops['id'],'client_id'=>$client,'truck_type'=>'Container','dispatcher_role'=>$day['role'],'dispatcher_shift'=>'Day','pickup_point'=>'Fixture origin','delivery_point'=>'Fixture destination','scheduled_at'=>date('Y-m-d H:i:s'),'status'=>'Awaiting Resources']);
Trips::action($ops,'turnover_assign_initial',['trip_id'=>$licenseTrip,'dispatcher_id'=>$day['id']]);
foreach([$licenseDriver,$licenseHelper] as $pid)Attendance::action($day,'time_in',['personnel_id'=>$pid,'request_key'=>turn_key()]);
$driverSession=DispatcherAttendance::current($p,$licenseDriver);
$assignment=['trip_id'=>$licenseTrip,'vehicle_id'=>$licenseTruck,'helper_id'=>$licenseHelper];
foreach(['NULL number'=>[null,'2099-01-01'],'blank number'=>['  ','2099-01-01'],'NULL expiry'=>['PHASE-A-LICENSE',null],'expired'=>['PHASE-A-LICENSE',date('Y-m-d',strtotime('-1 day'))]] as $label=>[$number,$expiry]){
 run($p,'UPDATE personnel SET license_number=?,license_expiry=? WHERE id=?',[$number,$expiry,$licenseDriver]);
 $options=Trips::action($day,'dispatcher_resources',['trip_id'=>$licenseTrip]);
 turn_ok(!in_array($licenseDriver,array_column($options['drivers'],'id'),true)&&!in_array($licenseDriver,array_column($options['second_drivers'],'id'),true),'Timed In Driver with '.$label.' is absent from eligible assignment options');
 turn_denied(fn()=>Trips::action($day,'assign_resources',$assignment),409,'Direct assignment rejects Driver with '.$label);
 turn_ok(one($p,'SELECT status,vehicle_id FROM trips WHERE id=?',[$licenseTrip])===['status'=>'Awaiting Resources','vehicle_id'=>null],'Rejected '.$label.' assignment leaves the trip unchanged');
}
turn_ok(DispatcherAttendance::current($p,$licenseDriver)===$driverSession,'License validation preserves the open attendance session');
run($p,'UPDATE personnel SET license_number=?,license_expiry=? WHERE id=?',['PHASE-A-LICENSE',date('Y-m-d'),$licenseDriver]);
$options=Trips::action($day,'dispatcher_resources',['trip_id'=>$licenseTrip]);
turn_ok(in_array($licenseHelper,array_column($options['helpers'],'id'),true),'Helper with no Driver license fields remains eligible');
Trips::action($day,'assign_resources',$assignment);
turn_ok(one($p,'SELECT status FROM trips WHERE id=?',[$licenseTrip])['status']==='Pre-Assigned','A Driver whose license expires today can be assigned through the normal endpoint');

// The same guard protects the second distinct Driver required by Car Carrier.
$ccDriver1=insert($p,'personnel',['full_name'=>'License CC Driver 1','person_type'=>'Driver','license_number'=>'PHASE-A-CC1','license_expiry'=>'2099-01-01']);
$ccDriver2=insert($p,'personnel',['full_name'=>'License CC Driver 2','person_type'=>'Driver','license_expiry'=>'2099-01-01']);
$ccTruck=insert($p,'vehicles',['plate_number'=>'LICENSE-CC1','truck_type'=>'Car Carrier','assigned_driver_id'=>$ccDriver1]);
insert($p,'vehicles',['plate_number'=>'LICENSE-CC2','truck_type'=>'Car Carrier','assigned_driver_id'=>$ccDriver2]);
$ccTrip=insert($p,'trips',['trip_ref'=>'LICENSE-CC-ASSIGNMENT','intake_source'=>'Operations Head','created_by'=>$ops['id'],'client_id'=>$client,'truck_type'=>'Car Carrier','dispatcher_role'=>$other['role'],'dispatcher_shift'=>'Night','pickup_point'=>'Fixture origin','delivery_point'=>'Fixture destination','scheduled_at'=>date('Y-m-d H:i:s'),'status'=>'Awaiting Resources']);
Trips::action($ops,'turnover_assign_initial',['trip_id'=>$ccTrip,'dispatcher_id'=>$other['id']]);
foreach([$ccDriver1,$ccDriver2] as $pid)Attendance::action($other,'time_in',['personnel_id'=>$pid,'request_key'=>turn_key()]);
$ccAssignment=['trip_id'=>$ccTrip,'vehicle_id'=>$ccTruck,'driver2_id'=>$ccDriver2];
turn_denied(fn()=>Trips::action($other,'assign_resources',$ccAssignment),409,'Car Carrier assignment rejects an unlicensed second Driver');
run($p,'UPDATE personnel SET license_number=? WHERE id=?',['PHASE-A-CC2',$ccDriver2]);
Trips::action($other,'assign_resources',$ccAssignment);
turn_ok(one($p,'SELECT status FROM trips WHERE id=?',[$ccTrip])['status']==='Pre-Assigned','Car Carrier assignment accepts two distinct licensed Timed In Drivers');
require __DIR__.'/planning_account_tests.php';
require __DIR__.'/account_guard_tests.php';
require __DIR__.'/selected_turnover_tests.php';
echo "RESULT: $pass passed, $fail failed\n";exit($fail?1:0);
