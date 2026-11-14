<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit('CLI only');
require dirname(__DIR__).'/backend/Actions.php';
require __DIR__.'/_launcher.php';
$fresh=envValue('DB_NAME');$upgrade=envValue('TEST_DB_UPGRADE');test_guard([$fresh,$upgrade]);
$passed=0;$failed=0;
function v43_check(bool $ok,string $message):void{global $passed,$failed;$ok?$passed++:$failed++;echo ($ok?'PASS ':'FAIL ').$message.PHP_EOL;}
function v43_refuse(callable $call,string $message):void{try{$call();v43_check(false,$message);}catch(BusinessRuleException|PDOException $error){v43_check(true,$message);}}
function v43_key():string{$v=bin2hex(random_bytes(16));return substr($v,0,8).'-'.substr($v,8,4).'-'.substr($v,12,4).'-'.substr($v,16,4).'-'.substr($v,20);}
test_recreate($fresh);$p=db();test_import($p,test_sql('database/schema.sql'));test_import($p,test_sql('database/extension.sql'));
Actions::load('Trips');Actions::load('Fleet');Actions::load('Routes');Actions::load('Attendance');core('DispatcherMonitoring');core('Access');
$shift=CrewAttendance::shiftFor();$users=[];
foreach(['operations_head','admin','billing','finance','motorpool','container_wingvan_dispatcher','car_carrier_dispatcher'] as $role){$id=insert($p,'users',['username'=>'v43_'.$role,'email'=>$role.'@example.test','password_hash'=>'fixture','full_name'=>'V43 '.$role,'role'=>$role,'shift'=>roleIsDispatcher($role)?$shift['shift']:null]);$users[$role]=['id'=>$id,'role'=>$role,'full_name'=>'V43 '.$role,'shift'=>$shift['shift']];}
$ops=$users['operations_head'];$admin=$users['admin'];$cw=$users['container_wingvan_dispatcher'];$cc=$users['car_carrier_dispatcher'];
$client=insert($p,'clients',['company_name'=>'V43 ISOLATED CLIENT','status'=>'Active','created_by'=>$ops['id']]);
foreach(TRUCK_TYPES as $type)insert($p,'client_rates',['client_id'=>$client,'truck_type'=>$type,'rate_per_km'=>'50','minimum_charge'=>'0','updated_by'=>$users['billing']['id']]);
$requested=Routes::action($ops,'request',['client_id'=>$client,'truck_type'=>'Container','origin'=>'V43 PICKUP','destination'=>'V43 DELIVERY','distance_km'=>'50']);$route=$requested['route_id'];
v43_check(!(RouteDistance::selectable($p,$client,'Container')),'Pending route is not selectable');
Routes::action($admin,'rate_enter',['route_id'=>$route,'rate_type'=>'Flat','rate_amount'=>'2500']);$rate=one($p,'SELECT id FROM client_route_rates WHERE route_id=?',[$route]);Routes::action($admin,'decide',['rate_id'=>$rate['id'],'decision'=>'approve']);
v43_check(count(RouteDistance::selectable($p,$client,'Container'))===1,'Existing route request/rate/Admin approval workflow remains');
$helper=Fleet::action($ops,'crew_save',['person_type'=>'Helper','full_name'=>'HELPER, REGULAR'])['id'];
$helper2=Fleet::action($ops,'crew_save',['person_type'=>'Helper','full_name'=>'HELPER, REPLACEMENT'])['id'];
$truck=Fleet::action($ops,'truck_save',['plate_number'=>'V43001','truck_type'=>'Container'])['id'];
$truck2=Fleet::action($ops,'truck_save',['plate_number'=>'V43002','truck_type'=>'Container'])['id'];
$driver=Fleet::action($ops,'crew_save',['person_type'=>'Driver','full_name'=>'DRIVER, FIXED','license_number'=>'V43-L1','license_expiry'=>date('Y-m-d',strtotime('+2 years')),'fixed_truck_id'=>$truck,'regular_helper_id'=>$helper])['id'];
$driver2=Fleet::action($ops,'crew_save',['person_type'=>'Driver','full_name'=>'DRIVER, SECOND','license_number'=>'V43-L2','license_expiry'=>date('Y-m-d',strtotime('+2 years')),'fixed_truck_id'=>$truck2,'regular_helper_id'=>$helper2])['id'];
v43_check((int)one($p,'SELECT assigned_driver_id FROM vehicles WHERE id=?',[$truck])['assigned_driver_id']===$driver,'Driver modal save establishes the authoritative existing-Truck pairing');
v43_refuse(fn()=>Fleet::action($ops,'crew_save',['person_type'=>'Driver','full_name'=>'DRIVER, DUPLICATE','license_number'=>'V43-L3','license_expiry'=>date('Y-m-d',strtotime('+2 years')),'fixed_truck_id'=>$truck,'regular_helper_id'=>$helper]),'Second fixed Driver on the same Truck is rejected');
v43_check(!one($p,"SELECT id FROM personnel WHERE full_name='DRIVER, DUPLICATE'"),'Failed assignment rolls back the newly inserted Driver');
v43_refuse(fn()=>Fleet::action($ops,'truck_save',['id'=>$truck2,'plate_number'=>'V43002','truck_type'=>'Container','assigned_driver_id'=>$driver,'assigned_helper_id'=>$helper2]),'Truck-side save cannot give one Driver a second active fixed Truck');
v43_refuse(fn()=>run($p,'UPDATE vehicles SET assigned_driver_id=? WHERE id=?',[$driver,$truck2]),'Database uniqueness independently prevents duplicate fixed Driver assignment');
foreach([$driver,$driver2,$helper,$helper2] as $id){$person=one($p,'SELECT * FROM personnel WHERE id=?',[$id]);Fleet::action($admin,'crew_save',array_merge($person,['id'=>$id,'duty_shift'=>$shift['shift']]));}
$base=['client_id'=>$client,'truck_type'=>'Container','client_route_id'=>$route,'scheduled_at'=>date('Y-m-d\TH:i',time()+3600),'pickup_point'=>'V43 PICKUP','delivery_point'=>'V43 DELIVERY','distance_km'=>'50','dispatcher_assignment'=>'container_wingvan_dispatcher:'.$shift['shift'],'responsible_dispatcher_id'=>$cw['id']]; // kenneth: explicit responsible account
$created=Trips::action($ops,'create',$base+['request_key'=>v43_key()]);$id=$created['trip_id'];$trip=one($p,'SELECT * FROM trips WHERE id=?',[$id]);
v43_check($trip['status']==='Awaiting Resources'&&!$trip['vehicle_id']&&!$trip['driver_id']&&!$trip['helper_id'],'Operations Head creates directly without approval or resource allocation');
v43_check(!(many($p,'SELECT * FROM trip_assignments WHERE trip_id=?',[$id])),'Creation holds no Truck or personnel');
v43_check(Trips::assignWaiting($p,$ops)===''&&!one($p,'SELECT vehicle_id FROM trips WHERE id=?',[$id])['vehicle_id'],'Legacy availability automation cannot allocate a waiting trip');
foreach([$cw,$cc] as $u){v43_refuse(fn()=>Trips::action($u,'create',$base),'Dispatcher cannot create a trip');v43_refuse(fn()=>Trips::action($u,'create_batch',['rows'=>[$base+['request_key'=>v43_key()]]]),'Dispatcher cannot create a batch');v43_refuse(fn()=>Trips::read($u,'dispatcher-monitoring',[]),'Dispatcher cannot read monitoring');v43_refuse(fn()=>Trips::action($u,'monitor_details',['trip_id'=>$id]),'Dispatcher cannot call monitoring details');}
v43_refuse(fn()=>Trips::action($ops,'review',['trip_id'=>$id,'decision'=>'approve']),'Obsolete Trip Approval mutation is retired');
v43_refuse(fn()=>Trips::action($cw,'auto_assign',['trip_id'=>$id]),'Automatic allocation endpoint is retired');
v43_refuse(fn()=>Trips::action($ops,'create',array_merge($base,['client_route_id'=>null])),'Direct API cannot create without an approved route');
v43_refuse(fn()=>Trips::action($ops,'create',array_merge($base,['truck_type'=>'Wing Van','dispatcher_assignment'=>'container_wingvan_dispatcher:'.$shift['shift']])),'Wrong Truck Type cannot use an approved Container route');
v43_check(!(Trips::action($cw,'dispatcher_resources',['trip_id'=>$id])['trucks']),'Untimed-in Drivers make their Trucks ineligible');
foreach([$driver,$driver2,$helper,$helper2] as $personId)Attendance::action($cw,'time_in',['personnel_id'=>$personId,'request_key'=>v43_key()]);
$options=Trips::action($cw,'dispatcher_resources',['trip_id'=>$id]);v43_check(count($options['trucks'])===2,'Timed-in eligible fixed pairs are offered for manual Truck selection');
v43_refuse(fn()=>Trips::action($cw,'assign_resources',['trip_id'=>$id,'vehicle_id'=>$truck,'driver_id'=>$driver2,'helper_id'=>$helper]),'Forged independent Driver substitution is rejected');
v43_refuse(fn()=>Trips::action($cw,'assign_resources',['trip_id'=>$id,'vehicle_id'=>$truck,'helper_id'=>$helper2]),'Replacing a regular Helper requires a reason');
Trips::action($cw,'assign_resources',['trip_id'=>$id,'vehicle_id'=>$truck,'helper_id'=>$helper2,'helper_substitution_reason'=>'Regular Helper unavailable for this trip']);
$trip=one($p,'SELECT * FROM trips WHERE id=?',[$id]);v43_check((int)$trip['driver_id']===$driver&&(int)$trip['helper_id']===$helper2&&$trip['status']==='Pre-Assigned','Server derives the fixed Driver and saves actual trip Helper');
v43_check((int)one($p,'SELECT assigned_helper_id FROM vehicles WHERE id=?',[$truck])['assigned_helper_id']===$helper,'Trip-only replacement does not change the permanent Helper');
v43_check($trip['helper_substitution_reason']==='Regular Helper unavailable for this trip','Replacement reason is persisted on the trip');
$second=Trips::action($ops,'create',$base+['request_key'=>v43_key()])['trip_id'];
v43_refuse(fn()=>Trips::action($cw,'assign_resources',['trip_id'=>$second,'vehicle_id'=>$truck,'helper_id'=>$helper]),'Held Truck/Driver cannot be assigned simultaneously to another trip');
v43_refuse(fn()=>Trips::action($cw,'assign_resources',['trip_id'=>$second,'vehicle_id'=>$truck2,'helper_id'=>$helper2]),'Held replacement Helper cannot be reused by another trip');
v43_refuse(fn()=>Fleet::action($ops,'crew_save',['id'=>$driver,'person_type'=>'Driver','full_name'=>'DRIVER, FIXED','license_number'=>'V43-L1','license_expiry'=>date('Y-m-d',strtotime('+2 years')),'fixed_truck_id'=>$truck2,'regular_helper_id'=>$helper]),'Permanent reassignment cannot override another fixed Driver or active hold');
$monitor=Trips::read($ops,'dispatcher-monitoring',['group'=>$cw['role'],'shift'=>$shift['shift']]);v43_check((int)$monitor['summary']['active']===2&&$monitor['total']===2,'Monitoring uses real group/shift active workload records');
$before=one($p,'SELECT status,helper_id FROM trips WHERE id=?',[$id]);$details=Trips::action($ops,'monitor_details',['trip_id'=>$id]);v43_check($details['read_only']===true&&!isset($details['trip']['can'])&&one($p,'SELECT status,helper_id FROM trips WHERE id=?',[$id])===$before,'Monitoring details are read-only and retain actual resources');
v43_check((int)DispatcherMonitoring::operationalCounts($p)['attention']===1,'Attention KPI counts the remaining unassigned trip');
// Real lifecycle through the unchanged Funds, Maintenance, checklist and document APIs.
Actions::load('Funds');Actions::load('Maintenance');Actions::load('Mechanics');Actions::load('Documents');
require_once dirname(__DIR__).'/backend/Modules/Operations/Inspections/Checklist.php';
foreach(['inspection_min_minutes'=>0,'dispatch_early_minutes'=>120] as $key=>$value)run($p,'INSERT INTO app_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)',[$key,(string)$value]);Settings::clearCache();
$tempFiles=[];register_shutdown_function(static function()use(&$tempFiles){foreach($tempFiles as $file)if(is_file($file))unlink($file);});
$pdf=static function(string $name)use(&$tempFiles):array{$file=tempnam(sys_get_temp_dir(),'v43');$tempFiles[]=$file;file_put_contents($file,"%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");return ['name'=>$name,'tmp_name'=>$file,'error'=>UPLOAD_ERR_OK,'size'=>filesize($file)];};
$items=[];foreach(Checklist::sections() as $section)foreach($section['items'] as $n=>$label)$items[$section['key'].'_'.str_pad((string)($n+1),2,'0',STR_PAD_LEFT)]=['condition'=>'OK','remarks'=>''];
v43_refuse(fn()=>Trips::action($cw,'dispatch',['trip_id'=>$id]),'Manual allocation does not bypass checklist or released fund requirements');
$fund=Funds::action($cw,'submit',['trip_id'=>$id,'extra'=>[['name'=>'V43 trip allowance','amount'=>'123.45']]])['fund_id'];
v43_refuse(fn()=>Funds::action($cw,'decide',['fund_id'=>$fund,'decision'=>'approve']),'Dispatcher still cannot approve Trip Funds');
Funds::action($ops,'decide',['fund_id'=>$fund,'decision'=>'approve']);
v43_refuse(fn()=>Funds::action($ops,'release',['fund_id'=>$fund]),'Operations Head still cannot release Finance funds');
Funds::action($users['finance'],'release',['fund_id'=>$fund]);
v43_check(one($p,'SELECT status FROM fund_requests WHERE id=?',[$fund])['status']==='Released'&&(bool)one($p,'SELECT id FROM disbursements WHERE source_type=? AND source_id=?',['fund_request',$fund]),'Unchanged fund submission, Operations approval and Finance release create the voucher');
Trips::action($cw,'checklist_start',['trip_id'=>$id,'kind'=>'Pre-Dispatch']);Trips::action($cw,'checklist',['trip_id'=>$id,'items'=>$items,'fuel_level'=>'Full','odometer_km'=>100]);
v43_check(one($p,'SELECT status FROM trips WHERE id=?',[$id])['status']==='Ready for Dispatch','All existing checklist items are validated and move the trip to Ready for Dispatch');
Trips::action($cw,'dispatch',['trip_id'=>$id]);v43_check(one($p,'SELECT status FROM trips WHERE id=?',[$id])['status']==='Dispatched'&&(bool)one($p,'SELECT id FROM billings WHERE trip_id=?',[$id]),'Confirm Dispatch creates the existing billing draft');
$_FILES=['diesel_po'=>$pdf('v43-diesel-po.pdf')];Trips::action($cw,'diesel_po',['trip_id'=>$id]);$_FILES=[];
v43_check(Files::count($p,'trip',$id,'Diesel PO')===1,'Existing Diesel PO upload remains linked and privately stored');
$incident=Trips::action($cw,'incident',['trip_id'=>$id,'incident_type'=>'Breakdown','incident_at'=>date('Y-m-d\TH:i'),'location'=>'V43 yard','problem'=>'V43 isolated fixture repair','details'=>'V43 reported finding'])['incident_id'];
$job=(int)one($p,'SELECT id FROM maintenance_jobs WHERE incident_id=?',[$incident])['id'];
v43_check(one($p,'SELECT status FROM trips WHERE id=?',[$id])['status']==='On Hold - Incident Reported'&&$job>0,'Incident reporting holds the trip and opens its existing corrective maintenance record');
v43_refuse(fn()=>Maintenance::action($ops,'assess',['job_id'=>$job]),'Monitoring visibility does not grant Operations Head mechanic processing');
$mechanic=Mechanics::action($users['motorpool'],'save',['full_name'=>'V43 MECHANIC'])['id'];Mechanics::action($users['motorpool'],'time_in',['id'=>$mechanic]); // luis: a mechanic is assigned only after Time In
Maintenance::action($users['motorpool'],'assess',['job_id'=>$job,'severity'=>'Minor','recommendation'=>'V43 on-site inspection','mechanic_id'=>$mechanic]);
Maintenance::action($users['motorpool'],'inspect',['job_id'=>$job,'findings'=>'V43 fixture finding','diagnosis'=>'V43 fixture diagnosis']);
Maintenance::action($users['motorpool'],'add_cost',['job_id'=>$job,'cost_type'=>'Labor','description'=>'V43 repair','amount'=>'50','incurred_on'=>date('Y-m-d')]);
$_FILES=['evidence'=>$pdf('v43-repair.pdf')];Maintenance::action($users['motorpool'],'complete',['job_id'=>$job,'repair_performed'=>'V43 fixture repaired','completed_on'=>date('Y-m-d')]);$_FILES=[];
Maintenance::action($users['motorpool'],'verify',['job_id'=>$job,'result'=>'Passed','verification_notes'=>'V43 safe to continue']);
v43_refuse(fn()=>Trips::action($cw,'resume',['trip_id'=>$id]),'A verified repair alone does not authorize resumption (INCIDENT_CLEARANCE_V1)');
Maintenance::action($users['motorpool'],'clearance',['job_id'=>$job,'decision'=>'Fit to Continue','findings'=>'V43 truck checked and fit to continue']);
$coordination=Trips::action($ops,'monitor_details',['trip_id'=>$id]);v43_check($coordination['incidents'][0]['job_status']==='Completed','Read-only monitoring shows linked repair status from Maintenance');
Trips::action($cw,'resume',['trip_id'=>$id]);v43_check(one($p,'SELECT status FROM trips WHERE id=?',[$id])['status']==='In Transit','Authorized Maintenance clearance enables the unchanged Dispatcher resume workflow');
$completion=['trip_id'=>$id,'completed_at'=>date('Y-m-d\TH:i'),'waybill_number'=>'V43-WB'];
v43_refuse(fn()=>Trips::action($cw,'complete',$completion),'Completion still requires waybill, acknowledgement and delivery receipt files');
$_FILES=['waybill'=>$pdf('v43-waybill.pdf'),'ack_receipt'=>$pdf('v43-ar.pdf'),'delivery_receipt'=>$pdf('v43-dr.pdf'),'supplier_soa'=>$pdf('v43-soa.pdf')];Trips::action($cw,'complete',$completion);$_FILES=[];
v43_check(Files::count($p,'trip',$id,'Supplier SOA')===1&&one($p,'SELECT status FROM trips WHERE id=?',[$id])['status']==='Completed','Real completion preserves required documents and optional Supplier SOA');
// kenneth 16dce05 (Trips::complete): delivery completion is not vehicle return; truck AND crew holds stay until the arrival checklist.
v43_check(FleetState::awaitingArrival($p,$id),'Completion retains the Truck (and crew) holds until its arrival checklist');
v43_check((int)DispatcherMonitoring::operationalCounts($p)['arrival']===1,'Pending arrival KPI uses the real returning vehicle hold');
$history=Trips::read($ops,'dispatcher-monitoring',['group'=>$cw['role'],'shift'=>$shift['shift'],'tab'=>'history']);v43_check($history['total']===1&&$history['rows'][0]['id']==$id,'Monitoring separates saved completed history from active work');
$historical=Trips::action($ops,'monitor_details',['trip_id'=>$id]);v43_check($historical['trip']['helper_name']==='HELPER, REPLACEMENT'&&$historical['trip']['dispatcher_name']===$cw['full_name'],'History retains actual trip Helper and recorded Dispatcher account');
v43_check(count(DispatcherPlanning::groups())===4,'Monitoring groups come from the existing planning mapping');
Trips::action($cw,'checklist_start',['trip_id'=>$id,'kind'=>'Arrival']);Trips::action($cw,'arrival',['trip_id'=>$id,'items'=>$items,'fuel_level'=>'Full','odometer_km'=>200]);
v43_check(!FleetState::awaitingArrival($p,$id)&&one($p,'SELECT status FROM vehicles WHERE id=?',[$truck])['status']==='Available','Real arrival checklist releases the returning Truck');
v43_check(!one($p,"SELECT resource_id FROM trip_assignments WHERE trip_id=? AND resource_type='person'",[$id]),'Real arrival checklist also releases the crew holds');
v43_check(!one($p,'SELECT vehicle_id FROM trips WHERE id=?',[$second])['vehicle_id'],'Completion and arrival do not auto-allocate another waiting trip');
Funds::action($users['finance'],'liquidate',['fund_id'=>$fund,'liquidated_amount'=>'100','liquidation_notes'=>'V43 isolated balance']);
v43_check(one($p,'SELECT returned_amount FROM fund_requests WHERE id=?',[$fund])['returned_amount']==='23.45','Existing Finance liquidation retains actual spent and returned cash');
$financeDocs=Documents::read($users['finance'],'documents',[]);v43_check(in_array('Supplier SOA',array_column($financeDocs['documents'],'category'),true),'Finance document projection retains completion attachments');
// Reassignment protection and eligibility against real records, not UI-only constraints.
insert($p,'attendance_daily',['duty_date'=>date('Y-m-d'),'personnel_id'=>$driver,'status'=>'Absent','marked_by'=>$admin['id']]); // integrated: Admin daily attendance is the Absent authority
v43_refuse(fn()=>Trips::action($cw,'assign_resources',['trip_id'=>$second,'vehicle_id'=>$truck,'helper_id'=>$helper]),'An absent fixed Driver makes its Truck ineligible');
run($p,'DELETE FROM attendance_daily WHERE personnel_id=? AND duty_date=?',[$driver,date('Y-m-d')]);
Fleet::action($ops,'truck_status',['id'=>$truck,'status'=>'Unavailable','reason'=>'V43 fixture restriction']);
v43_refuse(fn()=>Trips::action($cw,'assign_resources',['trip_id'=>$second,'vehicle_id'=>$truck,'helper_id'=>$helper]),'Unavailable Truck cannot be assigned despite a timed-in fixed Driver');
Fleet::action($ops,'truck_status',['id'=>$truck,'status'=>'Available']);
// kenneth 8220af6: responsibility belongs to the planned ACCOUNT; a real other-shift account of the same group is not responsible.
$otherShift=$shift['shift']==='Day'?'Night':'Day';$wrongShiftId=insert($p,'users',['username'=>'v43_other_shift','email'=>'v43_other_shift@example.test','password_hash'=>'fixture','full_name'=>'V43 other shift','role'=>$cw['role'],'shift'=>$otherShift]);
$wrongShift=['id'=>$wrongShiftId,'role'=>$cw['role'],'shift'=>$otherShift,'full_name'=>'V43 other shift'];
v43_refuse(fn()=>Trips::action($wrongShift,'assign_resources',['trip_id'=>$second,'vehicle_id'=>$truck,'helper_id'=>$helper]),'Other shift cannot allocate a group-owned trip through a direct API');
Trips::action($cw,'assign_resources',['trip_id'=>$second,'vehicle_id'=>$truck,'helper_id'=>$helper]);
// Current held crew remain candidates for their own rescheduled trip, but never another trip.
run($p,"UPDATE trips SET status='For Reschedule' WHERE id=?",[$second]);
$own=Trips::action($cw,'dispatcher_resources',['trip_id'=>$second]);v43_check(in_array($truck,array_column($own['trucks'],'id'),true)&&in_array($helper,array_column($own['helpers'],'id'),true),'For Reschedule retains its own eligible held Truck and crew');
Trips::action($cw,'assign_resources',['trip_id'=>$second,'vehicle_id'=>$truck,'helper_id'=>$helper]);Trips::action($cw,'reschedule',['trip_id'=>$second,'scheduled_at'=>date('Y-m-d\TH:i',time()+3600)]);
v43_check((int)one($p,'SELECT attempt_number FROM trips WHERE id=?',[$second])['attempt_number']===2,'Manual selection preserves the existing reschedule attempt workflow');
Trips::action($ops,'cancel',['trip_id'=>$second,'reason'=>'V43 fixture finished']);
// Completed actual resources do not follow later changes to permanent masterlist assignments.
$spare=Fleet::action($ops,'truck_save',['plate_number'=>'V43003','truck_type'=>'Container'])['id'];
Fleet::action($ops,'crew_save',['id'=>$driver,'person_type'=>'Driver','full_name'=>'DRIVER, FIXED','license_number'=>'V43-L1','license_expiry'=>date('Y-m-d',strtotime('+2 years')),'fixed_truck_id'=>$spare,'regular_helper_id'=>$helper]);
v43_check((int)one($p,'SELECT assigned_driver_id FROM vehicles WHERE id=?',[$spare])['assigned_driver_id']===$driver&&!one($p,'SELECT assigned_driver_id FROM vehicles WHERE id=?',[$truck])['assigned_driver_id'],'Validated permanent reassignment releases only the previous fixed Truck link');
v43_check((int)one($p,'SELECT vehicle_id FROM trips WHERE id=?',[$id])['vehicle_id']===$truck&&Trips::action($ops,'monitor_details',['trip_id'=>$id])['trip']['helper_name']==='HELPER, REPLACEMENT','Completed history keeps actual Truck and Helper after permanent reassignment');
// Server pagination, backend validation and legacy NULL-shift compatibility.
$rows=[];for($n=0;$n<14;$n++)$rows[]=$base+['request_key'=>v43_key(),'booking_reference'=>'V43-PAGE-'.$n];
$batch=Trips::action($ops,'create_batch',['rows'=>$rows]);v43_check(count($batch['results'])===14&&!array_filter($batch['results'],fn($r)=>$r['trip_status']!=='Awaiting Resources'),'Real batch creates all reviewed rows without allocating resources');
$batchAgain=Trips::action($ops,'create_batch',['rows'=>$rows]);v43_check(!array_filter($batchAgain['results'],fn($r)=>$r['result']!=='Already created'),'Batch retry preserves original keys and does not duplicate trips');
$query=['page'=>'dispatcher-monitoring','group'=>$cw['role'],'shift'=>$shift['shift'],'trip_page'=>2];
$_SESSION=['uid'=>$ops['id'],'last_seen'=>time(),'login_at'=>time()];
$page=Actions::pageData($query['page'],$query);v43_check($page['page']===2&&$page['total']===14&&count($page['rows'])===2,'Monitoring pagination passes the authenticated page authorization boundary with the correct second-page total');
$historyPage=Actions::pageData('dispatcher-monitoring',array_merge($query,['tab'=>'history','trip_page'=>1]));
$closed=(int)one($p,"SELECT COUNT(*) AS n FROM trips WHERE status IN ('Completed','Cancelled','Rejected') AND dispatcher_role=? AND dispatcher_shift=?",[$cw['role'],$shift['shift']])['n']; // History = Completed, Cancelled, Rejected (the cancelled reschedule fixture counts too)
$done=array_values(array_filter($historyPage['rows'],fn($r)=>(int)$r['id']===(int)$id));
v43_check($historyPage['total']===$closed&&count($done)===1&&$done[0]['dispatcher_role']===$cw['role']&&$done[0]['dispatcher_shift']===$shift['shift'],'Authenticated Trip History preserves the completed trip and recorded group/shift');
$historyEmpty=Actions::pageData('dispatcher-monitoring',array_merge($query,['tab'=>'history','search'=>'NO-MATCH-'.v43_key()]));
v43_check($historyEmpty['rows']===[]&&$historyEmpty['total']===0&&$historyEmpty['page']===1,'Authenticated empty history returns an empty paginated result instead of access denied');
try{Actions::pageData('1',['page'=>'1','tab'=>'history']);v43_check(false,'Numeric page identifiers remain forbidden');}catch(BusinessRuleException $error){v43_check($error->httpStatus===403,'Numeric page identifiers remain forbidden');}
$match=Trips::read($ops,'dispatcher-monitoring',['search'=>$batch['results'][0]['trip_ref'],'status'=>'Awaiting Resources','from'=>date('Y-m-d'),'to'=>date('Y-m-d',time()+86400)]);v43_check($match['total']===1,'Monitoring search, status and schedule filters combine on the server');
// Two PHP workers compete for one intact pair while a parent lock synchronizes the requests.
$workers=[];$p->beginTransaction();one($p,'SELECT id FROM personnel WHERE id=? FOR UPDATE',[$driver]);
try{
    foreach([1,2] as $n){$process=proc_open([PHP_BINARY,__DIR__.'/operations_v43_worker.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);must(is_resource($process),'Could not start test worker.');$workers[]=[$process,$pipes];fwrite($pipes[0],json_encode(['user'=>$cw['id'],'assignment'=>['trip_id'=>$batch['results'][$n]['trip_id'],'vehicle_id'=>$spare,'helper_id'=>$helper]])."\n");fflush($pipes[0]);must(trim((string)fgets($pipes[1]))==='READY','Test worker did not become ready.');}
    foreach($workers as [$process,$pipes]){fwrite($pipes[0],"GO\n");fflush($pipes[0]);}usleep(100000);$p->commit();$outcomes=[];
    foreach($workers as [$process,$pipes]){fclose($pipes[0]);$outcomes[]=json_decode(stream_get_contents($pipes[1]),true);fclose($pipes[1]);fclose($pipes[2]);proc_close($process);}
    v43_check(count(array_filter($outcomes,fn($r)=>!empty($r['ok'])))===1&&count(array_filter($outcomes,fn($r)=>($r['status']??0)===409))===1,'Concurrent assignment workers produce exactly one saved allocation and one conflict');
    v43_check((int)one($p,"SELECT COUNT(*) AS n FROM trip_assignments WHERE resource_type='vehicle' AND resource_id=?",[$spare])['n']===1,'Concurrent requests retain a single authoritative Truck hold');
}finally{if($p->inTransaction())$p->rollBack();}
v43_refuse(fn()=>Trips::read($ops,'dispatcher-monitoring',['group'=>'forged_role']),'Monitoring rejects invented group filters');
v43_refuse(fn()=>Trips::read($ops,'dispatcher-monitoring',['from'=>'2026-11-12','to'=>'2026-11-11']),'Monitoring rejects inverted date filters');
$legacy=$batch['results'][0]['trip_id'];run($p,'UPDATE trips SET dispatcher_shift=NULL WHERE id=?',[$legacy]);
$legacyData=Trips::read($ops,'dispatcher-monitoring',['group'=>$cw['role'],'shift'=>'legacy']);v43_check($legacyData['total']===1&&count(array_filter($legacyData['groups'],fn($g)=>$g['shift']===null))===1,'Only real legacy NULL-shift records add a labelled legacy group');
$request=insert($p,'trips',['trip_ref'=>'V43-HISTORICAL-REQUEST','client_id'=>$client,'truck_type'=>'Container','dispatcher_role'=>$cw['role'],'intake_source'=>'Dispatcher','status'=>'Pending Review','pickup_point'=>'OLD PICKUP','delivery_point'=>'OLD DELIVERY','scheduled_at'=>date('Y-m-d H:i:s'),'created_by'=>$cw['id']]);
v43_refuse(fn()=>Trips::action($ops,'cancel',['trip_id'=>$request,'reason'=>'Must remain read-only']),'Historical Pending Review requests cannot be mutated through cancellation');
$archive=Trips::read($ops,'trips',['tab'=>'history','legacy'=>1]);v43_check(in_array($request,array_map('intval',array_column($archive['history'],'id')),true),'Unprocessed historical requests remain available through read-only history');
Notify::user($p,$ops['id'],'Trip request','Historical task','trips','trip-review-'.$request);$bell=Notify::forUser($p,$ops['id']);
v43_check(!in_array('Historical task',array_column($bell['items'],'message'),true)&&(bool)one($p,'SELECT id FROM notifications WHERE dedupe_key=?',['trip-review-'.$request]),'Retired approval tasks leave the active bell while preserving notification history');
// Car Carrier keeps its existing second Driver slot and no Helper relationship.
$ccRoute=Routes::action($ops,'request',['client_id'=>$client,'truck_type'=>'Car Carrier','origin'=>'V43 CC PICKUP','destination'=>'V43 CC DELIVERY','distance_km'=>'50'])['route_id'];Routes::action($admin,'rate_enter',['route_id'=>$ccRoute,'rate_type'=>'Flat','rate_amount'=>'3000']);$ccRate=one($p,'SELECT id FROM client_route_rates WHERE route_id=?',[$ccRoute]);Routes::action($admin,'decide',['rate_id'=>$ccRate['id'],'decision'=>'approve']);
$ccTrucks=[];$ccDrivers=[];for($n=1;$n<=2;$n++){$ccTrucks[]=Fleet::action($ops,'truck_save',['plate_number'=>'V43CC'.$n,'truck_type'=>'Car Carrier'])['id'];$ccDrivers[]=Fleet::action($ops,'crew_save',['person_type'=>'Driver','full_name'=>'V43 CC, DRIVER '.$n,'license_number'=>'V43-CC-L'.$n,'license_expiry'=>date('Y-m-d',strtotime('+2 years')),'fixed_truck_id'=>$ccTrucks[$n-1]])['id'];$pe=one($p,'SELECT * FROM personnel WHERE id=?',[$ccDrivers[$n-1]]);Fleet::action($admin,'crew_save',array_merge($pe,['id'=>$pe['id'],'duty_shift'=>$shift['shift']]));Attendance::action($cc,'time_in',['personnel_id'=>$pe['id'],'request_key'=>v43_key()]);}
$ccTrip=Trips::action($ops,'create',array_merge($base,['truck_type'=>'Car Carrier','client_route_id'=>$ccRoute,'pickup_point'=>'V43 CC PICKUP','delivery_point'=>'V43 CC DELIVERY','dispatcher_assignment'=>$cc['role'].':'.$shift['shift'],'responsible_dispatcher_id'=>$cc['id'],'request_key'=>v43_key()]))['trip_id'];
v43_refuse(fn()=>Trips::action($cw,'assign_resources',['trip_id'=>$ccTrip,'vehicle_id'=>$truck,'helper_id'=>$helper]),'Cross-group assignment remains unauthorized');
v43_refuse(fn()=>Trips::action($cc,'assign_resources',['trip_id'=>$ccTrip,'vehicle_id'=>$ccTrucks[0],'driver2_id'=>$ccDrivers[0]]),'Car Carrier refuses the same Driver in both slots');
Trips::action($cc,'assign_resources',['trip_id'=>$ccTrip,'vehicle_id'=>$ccTrucks[0],'driver2_id'=>$ccDrivers[1]]);$ccSaved=one($p,'SELECT driver_id,driver2_id,helper_id FROM trips WHERE id=?',[$ccTrip]);v43_check((int)$ccSaved['driver_id']===$ccDrivers[0]&&(int)$ccSaved['driver2_id']===$ccDrivers[1]&&$ccSaved['helper_id']===null,'Car Carrier manual selection retains fixed primary Driver and a distinct eligible co-driver');
// Migration upgrade/conflict preservation is isolated to another owned disposable database.
test_recreate($upgrade);$up=test_conn($upgrade);test_import($up,test_sql('database/schema.sql'));$extension=explode('-- V4.3: reuse the authoritative',test_sql('database/extension.sql'))[0];test_import($up,$extension);
$person=insert($up,'personnel',['full_name'=>'LEGACY, DRIVER','person_type'=>'Driver']);$v1=insert($up,'vehicles',['plate_number'=>'OLD001','truck_type'=>'Container','assigned_driver_id'=>$person]);$v2=insert($up,'vehicles',['plate_number'=>'OLD002','truck_type'=>'Container','assigned_driver_id'=>$person]);
$migration=test_sql('database/migrations/2026-11-11_11_operations_v43.sql');v43_refuse(fn()=>test_import($up,$migration),'Migration refuses conflicting legacy fixed pairings without guessing');v43_check((int)one($up,'SELECT COUNT(*) AS n FROM vehicles WHERE assigned_driver_id=?',[$person])['n']===2,'Failed migration preserves both conflicting records');
run($up,'UPDATE vehicles SET assigned_driver_id=NULL WHERE id=?',[$v2]);test_import($up,$migration);test_import($up,$migration);v43_check((int)one($up,'SELECT assigned_driver_id FROM vehicles WHERE id=?',[$v1])['assigned_driver_id']===$person,'Explicitly resolved upgrade is repeatable and preserves the remaining fixed pair');
echo "RESULT: $passed passed, $failed failed\n";exit($failed?1:0);
