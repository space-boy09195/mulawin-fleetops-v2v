<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit('CLI only');
require dirname(__DIR__).'/backend/Actions.php';
require __DIR__.'/_launcher.php';
$name=envValue('DB_NAME'); $manifest=test_isolation([$name]);
if (empty($manifest['create_only'])) throw new RuntimeException('Create-only launcher required.');
test_guard([$name]); test_recreate($name); // Refuses an existing database in create-only mode.
$p=db(); test_import($p,test_sql('database/schema.sql')); test_import($p,test_sql('database/extension.sql'));
Actions::load('Routes'); core('RouteDistance'); core('Access');
$pass=0;$fail=0;
function check_route(bool $ok,string $label): void {global $pass,$fail;$ok?$pass++:$fail++;echo ($ok?'PASS ':'FAIL ').$label.PHP_EOL;}
function refuse_route(callable $call,int $status,string $label): void {
    try {$call();check_route(false,$label);} catch(BusinessRuleException $e){check_route($e->httpStatus===$status,$label.' (HTTP '.$e->httpStatus.')');}
}
$users=[];
foreach(array_keys(ROLE_LABELS) as $role){$id=insert($p,'users',['username'=>'route_'.$role,'email'=>$role.'@example.test','full_name'=>'Fixture '.$role,'password_hash'=>'fixture','role'=>$role]);$users[$role]=['id'=>$id,'role'=>$role];}
$ops=$users['operations_head'];$admin=$users['admin'];
$client=insert($p,'clients',['company_name'=>'CURRENT ROUTE FIXTURE','status'=>'Active','created_by'=>$admin['id']]);
$request=['client_id'=>$client,'truck_type'=>'Container','origin'=>'Fixture Pickup','destination'=>'Fixture Delivery','distance_km'=>'25'];
foreach($users as $role=>$user){
    check_route(Access::canOpen($user,'routes')===in_array($role,['admin','operations_head','billing','finance','audit'],true),'Route page policy for '.$role);
    if($role!=='operations_head')refuse_route(fn()=>Routes::action($user,'request',$request),403,'Route request denied for '.$role);
}
$id=Routes::action($ops,'request',$request)['route_id'];
$row=one($p,'SELECT * FROM client_routes WHERE id=?',[$id]);
check_route($row['status']==='Pending Rate'&&$row['distance_source']==='Manual'&&(float)$row['distance_km']===25.0,'Requested manual km retain Pending Rate before Admin activation');
check_route(RouteDistance::selectable($p,$client,'Container')===[],'Pending route cannot be selected for trip planning');
foreach($users as $role=>$user)if($role!=='admin')refuse_route(fn()=>Routes::action($user,'rate_enter',['route_id'=>$id,'rate_type'=>'Flat','rate_amount'=>'2500']),403,'Rate activation denied for '.$role);
$rate=Routes::action($admin,'rate_enter',['route_id'=>$id,'rate_type'=>'Flat','rate_amount'=>'2500'])['rate_id'];
$first=one($p,'SELECT * FROM client_route_rates WHERE id=?',[$rate]);
check_route($first['status']==='Approved'&&(int)$first['decided_by']===$admin['id']&&$first['effective_from']!==null,'Admin entry immediately activates the approved rate with actor and effective date');
check_route(one($p,'SELECT status FROM client_routes WHERE id=?',[$id])['status']==='Approved','Route activates in the same transaction');
check_route(count(RouteDistance::selectable($p,$client,'Container'))===1,'Activated route becomes selectable for its client and truck type');
check_route(RouteDistance::selectable($p,$client,'Wing Van')===[],'Approved route is not selectable for another truck type');
refuse_route(fn()=>Routes::action($admin,'decide',['rate_id'=>$rate,'decision'=>'approve']),409,'A second approval of an activated rate is refused');
refuse_route(fn()=>Routes::action($admin,'rate_enter',['route_id'=>$id,'rate_type'=>'Flat','rate_amount'=>'2500']),409,'Unchanged rate revision is refused');
$rev=Routes::action($admin,'rate_enter',['route_id'=>$id,'rate_type'=>'Flat','rate_amount'=>'2750'])['rate_id'];
$old=one($p,'SELECT * FROM client_route_rates WHERE id=?',[$rate]);$new=one($p,'SELECT * FROM client_route_rates WHERE id=?',[$rev]);
check_route($old['status']==='Superseded'&&$old['rate_amount']===$first['rate_amount']&&$old['effective_to']!==null,'Revision preserves the prior amount and closes its historical validity');
check_route($new['status']==='Approved'&&(int)$new['version_no']===2,'Revision activates immediately as the next version');
check_route((int)one($p,"SELECT COUNT(*) n FROM client_route_rates WHERE route_id=? AND status='Approved'",[$id])['n']===1,'Exactly one approved rate remains current');
refuse_route(fn()=>Routes::action($ops,'request',$request),409,'Duplicate active route is rejected');
refuse_route(fn()=>Routes::action($ops,'withdraw',['route_id'=>$id,'reason'=>'fixture']),409,'Approved route cannot be withdrawn');
check_route((int)one($p,"SELECT COUNT(*) n FROM client_route_events WHERE route_id=? AND event_type IN ('route rate set and activated','rate revised and activated')",[$id])['n']===2,'Both activation decisions retain route event history');
foreach(['billing','finance','audit'] as $role)check_route((int)Routes::action($users[$role],'details',['route_id'=>$id])['route']['id']===(int)$id,$role.' can read the approved route');
// Legacy pending decisions remain supported, without pretending new Admin entry waits for a second approval.
$legacy=Routes::action($ops,'request',array_merge($request,['origin'=>'Legacy Pickup']))['route_id'];
$pending=insert($p,'client_route_rates',['route_id'=>$legacy,'version_no'=>1,'rate_type'=>'Flat','rate_amount'=>'1000','status'=>'Pending Approval','entered_by'=>$users['billing']['id']]);
run($p,"UPDATE client_routes SET status='Pending Approval' WHERE id=?",[$legacy]);
Routes::action($admin,'decide',['rate_id'=>$pending,'decision'=>'approve']);
check_route(one($p,'SELECT status FROM client_route_rates WHERE id=?',[$pending])['status']==='Approved','Admin can resolve a genuine legacy pending rate');
refuse_route(fn()=>Routes::action($admin,'decide',['rate_id'=>$pending,'decision'=>'approve']),409,'Repeated legacy decision is rejected');
check_route((int)one($p,'SELECT COUNT(*) n FROM trips')['n']===0&&(int)one($p,'SELECT COUNT(*) n FROM billings')['n']===0,'Route verification creates no trip or financial records');
echo "RESULT: $pass passed, $fail failed\n";exit($fail?1:0);
