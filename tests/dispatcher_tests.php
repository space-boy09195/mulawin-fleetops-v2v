<?php
/** Dedicated disposable database only; run through run_dispatcher_tests.php. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once dirname(__DIR__) . '/backend/Actions.php';
require_once __DIR__ . '/_launcher.php';
$fresh = envValue('DB_NAME'); test_guard([$fresh]);
$passed = 0; $failed = 0;
function ds_check(bool $condition, string $label): void { global $passed,$failed; $condition ? $passed++ : $failed++; echo ($condition ? 'PASS ' : 'FAIL ') . $label . "\n"; }
function ds_denied(callable $call, int $status, string $label): void { try { $call(); ds_check(false,$label); } catch (BusinessRuleException $e) { ds_check($e->httpStatus === $status,$label); } }
test_recreate($fresh); $p=db();
test_import($p,test_sql('database/schema.sql')); test_import($p,test_sql('database/extension.sql'));
Actions::load('Trips'); Actions::load('Fleet'); Actions::load('Funds');core('Access');
$shift=CrewAttendance::shiftFor();$users=[];
foreach (['operations_head','car_carrier_dispatcher','container_wingvan_dispatcher','finance'] as $role) {
    $id=insert($p,'users',['username'=>'dispatcher_test_'.$role,'email'=>$role.'@example.test','password_hash'=>'fixture','full_name'=>'Fixture '.$role,'role'=>$role,'shift'=>roleIsDispatcher($role)?$shift['shift']:null]);
    $users[$role]=['id'=>$id,'role'=>$role,'full_name'=>'Fixture '.$role,'shift'=>$shift['shift']];
}
$ops=$users['operations_head']; $cc=$users['car_carrier_dispatcher']; $cw=$users['container_wingvan_dispatcher'];
$client=insert($p,'clients',['company_name'=>'ISOLATED DISPATCHER CLIENT','status'=>'Active','created_by'=>$ops['id']]);
foreach (['Car Carrier','Container','Wing Van'] as $type) insert($p,'client_rates',['client_id'=>$client,'truck_type'=>$type,'rate_per_km'=>'50','minimum_charge'=>'0','updated_by'=>$ops['id']]);
$base=['client_id'=>$client,'pickup_point'=>'FIXTURE ORIGIN','delivery_point'=>'FIXTURE DESTINATION','scheduled_at'=>date('Y-m-d\TH:i',time()+10800),'distance_km'=>'25'];
$approved=[];foreach(TRUCK_TYPES as $type){$rid=insert($p,'client_routes',['route_ref'=>'DS-ROUTE-'.count($approved),'client_id'=>$client,'truck_type'=>$type,'origin_label'=>'FIXTURE ORIGIN','destination_label'=>'FIXTURE DESTINATION','origin_key'=>'fixture origin','destination_key'=>'fixture destination','distance_km'=>'25','status'=>'Approved','requested_by'=>$ops['id']]);insert($p,'client_route_rates',['route_id'=>$rid,'version_no'=>1,'rate_type'=>'Flat','rate_amount'=>'1250','status'=>'Approved','entered_by'=>$ops['id'],'effective_from'=>date('Y-m-d H:i:s')]);$approved[$type]=$rid;}
$create=fn(string $type)=>Trips::action($ops,'create',$base+['truck_type'=>$type,'client_route_id'=>$approved[$type],'dispatcher_assignment'=>dispatcherRoleFor($type).':'.$shift['shift'],'responsible_dispatcher_id'=>$users[dispatcherRoleFor($type)]['id']])['trip_id']; // kenneth: explicit responsible account
$ccId=$create('Car Carrier');$cwId=$create('Container');$wvId=$create('Wing Van');
$activeIds=fn(array $u)=>array_map('intval',array_column(Trips::read($u,'trips',[])['active'],'id'));
ds_check($activeIds($cc)===[$ccId],'Car Carrier assigned trips exclude the other team');
ds_check(count($activeIds($cw))===2&&!in_array($ccId,$activeIds($cw),true),'Container/Wing Van assigned trips remain team scoped');
foreach([$cc,$cw] as $who){ds_denied(fn()=>Trips::action($who,'create',$base+['truck_type'=>'Container']),403,'Dispatcher cannot originate trips');ds_denied(fn()=>Trips::read($who,'trips',['view'=>'requests']),403,'Retired request projection is inaccessible');ds_denied(fn()=>Trips::read($who,'trips',['view'=>'request-result','request_key'=>'00000000-0000-4000-a000-000000000001']),403,'Retired request-result projection is inaccessible');}
ds_denied(fn()=>Trips::action($cc,'details',['trip_id'=>$cwId]),404,'Cross-team trip details remain inaccessible');

// Fixture lifecycle states for read-projection coverage, isolated from the real development records.
run($p,"UPDATE trips SET status='Pre-Assigned',approved_by=?,approved_at=NOW() WHERE id=?",[$ops['id'],$cwId]);
run($p,"UPDATE trips SET status='Completed',approved_by=?,approved_at=NOW(),completed_at=NOW() WHERE id=?",[$ops['id'],$wvId]);
ds_check(in_array($wvId,array_map('intval',array_column(Trips::read($cw,'trips',[])['history'],'id')),true),'Completed trips remain in authorized history');
$ccTruck=insert($p,'vehicles',['plate_number'=>'DS-CC-1','truck_type'=>'Car Carrier']);
$cwTruck=insert($p,'vehicles',['plate_number'=>'DS-CW-1','truck_type'=>'Container','status'=>'On Trip']);
insert($p,'vehicles',['plate_number'=>'DS-WV-1','truck_type'=>'Wing Van']);
insert($p,'trip_assignments',['resource_type'=>'vehicle','resource_id'=>$cwTruck,'trip_id'=>$wvId,'role'=>'truck']);
run($p,'UPDATE trips SET vehicle_id=? WHERE id=?',[$cwTruck,$wvId]);
$overview=Trips::dispatcherOverview($cw);
ds_check(count(array_filter($overview['active'],fn($t)=>(int)$t['id']===$wvId&&(bool)$t['awaiting_arrival']))===1,'Completed trip remains active while its vehicle is held for arrival');
ds_check(!in_array($ccId,array_map('intval',array_column($overview['active'],'id')),true),'Dashboard overview excludes other-team trips');

core('CrewAttendance'); $shift=CrewAttendance::shiftFor();
$driver=insert($p,'personnel',['full_name'=>'FIXTURE, DRIVER','person_type'=>'Driver','duty_shift'=>$shift['shift'],'license_number'=>'FX-DRV-1','license_expiry'=>'2030-01-01']); // kenneth: assignment needs license number + expiry
$helper=insert($p,'personnel',['full_name'=>'FIXTURE, HELPER','person_type'=>'Helper','duty_shift'=>$shift['shift']]);
$ccDriver=insert($p,'personnel',['full_name'=>'FIXTURE, CARRIER DRIVER','person_type'=>'Driver','duty_shift'=>$shift['shift'],'license_number'=>'FX-DRV-2','license_expiry'=>'2030-01-01']);
$unmapped=insert($p,'personnel',['full_name'=>'FIXTURE, UNMAPPED','person_type'=>'Driver','duty_shift'=>$shift['shift']]);
run($p,'UPDATE vehicles SET assigned_driver_id=?,assigned_helper_id=? WHERE id=?',[$driver,$helper,$cwTruck]);
run($p,'UPDATE vehicles SET assigned_driver_id=? WHERE id=?',[$ccDriver,$ccTruck]);
insert($p,'attendance_marks',['duty_date'=>$shift['date'],'duty_shift'=>$shift['shift'],'personnel_id'=>$driver,'status'=>'Present','marked_by'=>$ops['id']]);
$ccAvailability=Fleet::read($cc,'trucks',['view'=>'availability']); $cwAvailability=Fleet::read($cw,'trucks',['view'=>'availability']);
ds_check(count($ccAvailability['trucks'])===1&&$ccAvailability['trucks'][0]['truck_type']==='Car Carrier','Car Carrier availability is filtered on the server');
ds_check(count($cwAvailability['trucks'])===2&&!in_array('Car Carrier',array_column($cwAvailability['trucks'],'truck_type'),true),'Container/Wing Van availability is filtered on the server');
ds_check(array_column($ccAvailability['personnel'],'person_type')===['Driver'],'Car Carrier personnel view includes drivers, not helpers');
ds_check(count($cwAvailability['personnel'])===2&&$cwAvailability['personnel'][0]['attendance_status']===null,'Only regularly linked scoped personnel are readable; attendance comes from Time In sessions (a legacy Present mark is not attendance)');
ds_check(!in_array($unmapped,array_map('intval',array_column($cwAvailability['personnel'],'id')),true)&&!in_array($unmapped,array_map('intval',array_column($ccAvailability['personnel'],'id')),true),'Unmapped personnel do not become a cross-team shared pool');
insert($p,'trip_assignments',['resource_type'=>'person','resource_id'=>$driver,'trip_id'=>$ccId,'role'=>'driver']);
$scopeRow=array_values(array_filter(Fleet::read($cw,'trucks',[])['personnel'],fn($r)=>(int)$r['id']===$driver))[0];
ds_check($scopeRow['trip_ref']===null,'A conflicting foreign-group hold does not disclose its trip reference');
ds_check(!str_contains(json_encode(Fleet::read($cw,'trucks',[]),JSON_THROW_ON_ERROR),one($p,'SELECT trip_ref FROM trips WHERE id=?',[$ccId])['trip_ref']),'Resource status messages do not disclose a foreign-group trip reference');
run($p,"DELETE FROM trip_assignments WHERE resource_type='person' AND resource_id=?",[$driver]);

insert($p,'fund_categories',['name'=>'ISOLATED FIXED PRESET','amount'=>'321.09','active'=>1,'sort_order'=>99]);
$fund=Funds::action($cw,'submit',['trip_id'=>$cwId,'categories'=>['ISOLATED FIXED PRESET'],'amount'=>'999999','preset_amounts'=>['ISOLATED FIXED PRESET'=>'1.00'],'extra'=>[['name'=>'ISOLATED CUSTOM','amount'=>'12.34']]]);
$saved=Funds::action($cw,'details',['fund_id'=>$fund['fund_id']])['fund'];
ds_check($saved['amount']==='333.43'&&$saved['lines'][0]['amount']==='321.09','Preset amounts come from fund_categories despite client-supplied totals');
ds_denied(fn()=>Funds::action($cw,'submit',['trip_id'=>$cwId,'categories'=>['ISOLATED FIXED PRESET']]),409,'Repeated fund submission is refused');
ds_denied(fn()=>Funds::action($cc,'details',['fund_id'=>$fund['fund_id']]),403,'Other Dispatcher team cannot read this fund request');
ds_denied(fn()=>Funds::action($cw,'decide',['fund_id'=>$fund['fund_id'],'decision'=>'approve']),403,'Dispatcher cannot approve funds');
ds_denied(fn()=>Funds::action($cw,'release',['fund_id'=>$fund['fund_id']]),403,'Dispatcher cannot release funds');
insert($p,'documents',['category'=>'Diesel PO','entity_type'=>'trip','entity_id'=>$cwId,'original_filename'=>'fixture-diesel.pdf','storage_name'=>'fixture-metadata-only','mime_type'=>'application/pdf','byte_size'=>1,'uploaded_by'=>$cw['id']]);
$cwFuel=Funds::read($cw,'funds',[]); $ccFuel=Funds::read($cc,'funds',[]);
ds_check(count($cwFuel['dieselDocuments'])===1&&count($ccFuel['dieselDocuments'])===0,'Existing Diesel PO document metadata remains team scoped');
ds_check((int)array_values(array_filter($cwFuel['fuelTrips'],fn($t)=>(int)$t['id']===$cwId))[0]['diesel_po_docs']===1,'Existing document counts do not invent request statuses');

// Real session/assignment scenarios are executed only by this guarded disposable database launcher.
Actions::load('Attendance');$key=static fn(int $n):string=>'00000000-0000-4000-a000-'.str_pad((string)$n,12,'0',STR_PAD_LEFT);
ds_denied(fn()=>Attendance::action($cw,'time_in',['personnel_id'=>$ccDriver,'request_key'=>$key(11)]),403,'Cross-group Time In is rejected');
$first=Attendance::action($cw,'time_in',['personnel_id'=>$driver,'request_key'=>$key(12)]);
$retry=Attendance::action($cw,'time_in',['personnel_id'=>$driver,'request_key'=>$key(12)]);
ds_check(!empty($retry['duplicate'])&&(int)one($p,'SELECT COUNT(*) AS n FROM dispatcher_attendance_sessions WHERE personnel_id=?',[$driver])['n']===1,'Duplicate Time In key does not create a second session');
Attendance::action($cw,'time_out',['personnel_id'=>$driver,'session_id'=>$first['session_id'],'request_key'=>$key(13)]);
$row=array_values(array_filter(DispatcherAttendance::read($cw)['rows'],fn($r)=>(int)$r['id']===$driver))[0];ds_check($row['action']==='Time In','Closed attendance session returns to Time In');
$second=Attendance::action($cw,'time_in',['personnel_id'=>$driver,'request_key'=>$key(14)]);
ds_check((int)one($p,'SELECT COUNT(*) AS n FROM dispatcher_attendance_sessions WHERE personnel_id=?',[$driver])['n']===2&&one($p,'SELECT time_out FROM dispatcher_attendance_sessions WHERE id=?',[$first['session_id']])['time_out']!==null,'Repeated Time In retains the first closed session');
Attendance::action($cw,'time_out',['personnel_id'=>$driver,'session_id'=>$first['session_id'],'request_key'=>$key(13)]);
ds_check((int)DispatcherAttendance::current($p,$driver)['id']===(int)$second['session_id'],'Replayed old Time Out does not close a newer session');
Attendance::action($cw,'time_in',['personnel_id'=>$helper,'request_key'=>$key(15)]);
ds_check(one($p,'SELECT status FROM attendance_marks WHERE personnel_id=?',[$driver])['status']==='Present','Dispatcher sessions do not overwrite Admin attendance marks');
FleetState::release($p,$wvId);FleetState::reconcile($p,$cwTruck,$ops['id'],'Isolated assignment fixture');
$assignId=$create('Container');run($p,"UPDATE trips SET status='Awaiting Resources',approved_by=?,approved_at=NOW() WHERE id=?",[$ops['id'],$assignId]);
$choices=Trips::action($cw,'dispatcher_resources',['trip_id'=>$assignId]);
ds_check(array_column($choices['drivers'],'id')===[$driver]&&$choices['drivers'][0]['vehicle_id']===$cwTruck,'Resource options pair the timed-in scoped driver with their permanent truck');
ds_denied(fn()=>Trips::action($cw,'assign_resources',['trip_id'=>$assignId,'driver_id'=>$driver,'vehicle_id'=>$ccTruck,'helper_id'=>$helper]),409,'Forged truck selection cannot change the permanent pair');
Trips::action($cw,'assign_resources',['trip_id'=>$assignId,'driver_id'=>$driver,'vehicle_id'=>$cwTruck,'helper_id'=>$helper]);
ds_check(one($p,'SELECT status FROM trips WHERE id=?',[$assignId])['status']==='Pre-Assigned','Dispatcher assignment uses the existing Pre-Assigned transition');
ds_denied(fn()=>Attendance::action($cw,'time_out',['personnel_id'=>$driver,'session_id'=>$second['session_id'],'request_key'=>$key(16)]),409,'Time Out is blocked while the driver is held by a trip');
ds_check(one($p,'SELECT trip_id FROM trip_assignments WHERE resource_type=? AND resource_id=?',['vehicle',$cwTruck])!==null,'Blocked Time Out never frees the truck');
ds_denied(fn()=>Trips::action($cc,'dispatcher_resources',['trip_id'=>$assignId]),403,'Cross-team resource options are rejected');
ds_denied(fn()=>Fleet::action($cw,'truck_details',['id'=>$ccTruck]),403,'Cross-group direct truck details are rejected');
ds_denied(fn()=>Fleet::action($cw,'crew_details',['id'=>$ccDriver]),403,'Cross-group direct personnel details are rejected');
$ccSecond=insert($p,'personnel',['full_name'=>'FIXTURE, CARRIER SECOND','person_type'=>'Driver','duty_shift'=>$shift['shift'],'license_number'=>'FX-DRV-3','license_expiry'=>'2030-01-01']);
insert($p,'vehicles',['plate_number'=>'DS-CC-2','truck_type'=>'Car Carrier','assigned_driver_id'=>$ccSecond]);
Attendance::action($cc,'time_in',['personnel_id'=>$ccDriver,'request_key'=>$key(21)]);Attendance::action($cc,'time_in',['personnel_id'=>$ccSecond,'request_key'=>$key(22)]);
$ccAssign=$create('Car Carrier');run($p,"UPDATE trips SET status='Awaiting Resources',approved_by=?,approved_at=NOW() WHERE id=?",[$ops['id'],$ccAssign]);
ds_denied(fn()=>Trips::action($cc,'assign_resources',['trip_id'=>$ccAssign,'driver_id'=>$ccDriver,'vehicle_id'=>$ccTruck,'driver2_id'=>$ccDriver]),409,'Car Carrier cannot use the same driver in both crew slots');
ds_denied(fn()=>Trips::action($cc,'assign_resources',['trip_id'=>$ccAssign,'driver_id'=>$ccDriver,'vehicle_id'=>$ccTruck,'driver2_id'=>$driver]),409,'Second-driver slot cannot bypass operational scope');
Trips::action($cc,'assign_resources',['trip_id'=>$ccAssign,'driver_id'=>$ccDriver,'vehicle_id'=>$ccTruck,'driver2_id'=>$ccSecond]);
ds_check((int)one($p,'SELECT driver2_id FROM trips WHERE id=?',[$ccAssign])['driver2_id']===$ccSecond,'Two distinct timed-in Car Carrier drivers follow the verified crew model');
run($p,'UPDATE trips SET created_by=? WHERE id=?',[$cw['id'],$ccId]);
ds_denied(fn()=>Trips::action($cw,'details',['trip_id'=>$ccId]),404,'Creator ownership cannot bypass trip operational scope');
// A later regular-link transfer does not disclose the previous group's session timestamps.
run($p,"UPDATE vehicles SET truck_type='Container' WHERE assigned_driver_id=?",[$ccSecond]);
$transferred=array_values(array_filter(DispatcherAttendance::read($cw)['rows'],fn($r)=>(int)$r['id']===$ccSecond))[0];
ds_check($transferred['session']===null&&!$transferred['can_record'],'Transferred personnel do not expose a foreign open session or allow an overlapping session');
ds_check(DispatcherAttendance::history($cw,['personnel_id'=>$ccSecond])['sessions']===[],'Attendance history remains group-scoped after a regular-link transfer');
// The new Operations monitoring projection retains server-side pagination and search.
for($i=0;$i<25;$i++){$pageId=$create('Container');run($p,'UPDATE trips SET pickup_point=? WHERE id=?',['PAGING FIXTURE '.$i,$pageId]);}
$paging=Trips::read($ops,'dispatcher-monitoring',['search'=>'PAGING FIXTURE','trip_page'=>1,'page_size'=>48]); // kenneth: the page parameter is trip_page
ds_check($paging['pageSize']===12&&$paging['total']===25&&count($paging['rows'])===12&&$paging['pages']===3,'Monitoring enforces twelve records despite supplied size parameters');
$last=Trips::read($ops,'dispatcher-monitoring',['search'=>'PAGING FIXTURE','trip_page'=>3]);ds_check(count($last['rows'])===1&&$last['page']===3,'Monitoring final page retains all matching records');
$search=Trips::read($ops,'dispatcher-monitoring',['search'=>'PAGING FIXTURE 24']);ds_check($search['total']===1,'Monitoring server search finds a record beyond the first page');
test_drop($fresh);
echo "RESULT: $passed passed, $failed failed\n";
exit($failed?1:0);
