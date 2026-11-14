<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/backend/Actions.php';
require_once __DIR__.'/_launcher.php';
$fresh=envValue('DB_NAME');$upgrade=envValue('TEST_DB_UPGRADE');test_guard([$fresh,$upgrade]);test_recreate($fresh);test_recreate($upgrade);$p=db();$up=test_conn($upgrade);$pass=0;
function checkPricing(bool $ok,string $label):void{global $pass;if(!$ok)throw new RuntimeException('FAIL '.$label);$pass++;echo 'PASS '.$label.PHP_EOL;}
function denyPricing(callable $f,int $status,string $label):void{try{$f();throw new RuntimeException('Allowed: '.$label);}catch(BusinessRuleException $e){checkPricing($e->httpStatus===$status,$label);}}
$extension=test_sql('database/extension.sql');$before=explode('-- Additive pricing history.',$extension)[0];foreach([$p,$up] as $db){test_import($db,test_sql('database/schema.sql'));test_import($db,$db===$p?$extension:$before);}
test_import($up,test_sql('database/migrations/2026-11-13_13_fixed_route_fuel.sql'));test_import($up,test_sql('database/migrations/2026-11-13_13_fixed_route_fuel.sql'));test_import($up,test_sql('database/migrations/2026-11-14_14_incident_clearance.sql')); // later migration, once (not repeatable)
checkPricing(test_fingerprint($p,$fresh)===test_fingerprint($up,$upgrade),'Fresh / upgrade schema parity and repeatable additive migration');
foreach(['Clients','Routes','Trips','Billing','Attendance'] as $module)Actions::load($module);core('TripPricing');core('BillingDraft');
$users=[];foreach(['admin'=>'admin','ops'=>'operations_head','day'=>'container_wingvan_dispatcher','night'=>'container_wingvan_dispatcher','billing'=>'billing','finance'=>'finance'] as $name=>$role){$id=insert($p,'users',['username'=>'pricing_'.$name,'full_name'=>'Pricing '.$name,'email'=>$name.'@example.test','password_hash'=>'test-only','role'=>$role,'shift'=>$name==='night'?'Night':($name==='day'?'Day':null)]);$users[$name]=['id'=>$id,'role'=>$role,'shift'=>$name==='night'?'Night':($name==='day'?'Day':null)];}extract($users);
$png=__DIR__.'/pricing-fixture.png';file_put_contents($png,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));$upload=['name'=>'fixture.png','tmp_name'=>$png,'size'=>filesize($png),'error'=>0];
foreach(array_keys(Clients::REQUIRED_DOCS) as $field)$_FILES[$field]=$upload;
$cid=Clients::action($ops,'create',['company_name'=>'Pricing Accredited','business_address'=>'Fixture address','contact_person'=>'Fixture person','contact_number'=>'09123456789'])['id'];$_FILES=[];
checkPricing(one($p,'SELECT status FROM clients WHERE id=?',[$cid])['status']==='Active'&&(int)$p->query('SELECT COUNT(*) FROM client_rates')->fetchColumn()===0,'Registration with accreditation becomes Active without legacy rates');
denyPricing(fn()=>Clients::action($billing,'rate_save',['id'=>$cid]),409,'Legacy rate writes retired');
Routing::testConfig(['ORS_API_KEY'=>'isolated-test-key']);Routing::useTransport(fn()=>[200,json_encode(['routes'=>[['summary'=>['distance'=>100000,'duration'=>7200],'geometry'=>'_p~iF~ps|U_ulLnnqC_mqNvxq`@']]])]);
$route=Routes::action($ops,'request',['client_id'=>$cid,'truck_type'=>'Container','origin'=>'Fixture pickup','destination'=>'Fixture delivery','distance_km'=>100,'origin_lat'=>14.1,'origin_lng'=>121.1,'destination_lat'=>14.3,'destination_lng'=>121.3,'route_option'=>'recommended'])['route_id'];
denyPricing(fn()=>Routes::action($billing,'rate_enter',['route_id'=>$route,'rate_type'=>'Flat','rate_amount'=>'1000']),403,'Billing cannot edit contractual route rates');
$rate=Routes::action($admin,'rate_enter',['route_id'=>$route,'rate_type'=>'Flat','rate_amount'=>'1000'])['rate_id'];Routes::action($admin,'decide',['rate_id'=>$rate,'decision'=>'approve']);
checkPricing(count(RouteDistance::selectable($p,$cid,'Container',$route))===1,'Head Management enters and self-approves rate through existing engine');
$revision=Routes::action($admin,'rate_enter',['route_id'=>$route,'rate_type'=>'Flat','rate_amount'=>'1200'])['rate_id'];checkPricing((int)RouteDistance::selectable($p,$cid,'Container',$route)[0]['rate_id']===$rate,'Pending revision keeps previous approved rate');Routes::action($admin,'decide',['rate_id'=>$revision,'decision'=>'approve']);
checkPricing(one($p,'SELECT status FROM client_route_rates WHERE id=?',[$rate])['status']==='Superseded','Revision preserves old version');
$today=date('Y-m-d');
$beforeSettings=TripPricing::quote($p,['client_route_id'=>$route,'truck_type'=>'Container','created_by'=>$ops['id']],date('Y-m-d H:i:s'));checkPricing($beforeSettings['fuel']===null&&$beforeSettings['total']===null,'Missing financial settings have no sample-value fallback');
denyPricing(fn()=>Settings::pricingSave($billing,['setting_key'=>'diesel']),403,'Fuel settings require Head Management');
denyPricing(fn()=>Settings::pricingSave($admin,['setting_key'=>'diesel','value'=>55,'effective_date'=>date('Y-m-d',strtotime('-1 day')),'reference'=>'Unverified history']),409,'Historical settings require explicit source verification');
Settings::pricingSave($admin,['setting_key'=>'diesel','value'=>55,'effective_date'=>date('Y-m-d',strtotime('-1 day')),'reference'=>'Isolated verified historical document','historical_verified'=>'1']);
foreach(['diesel'=>'60','Container'=>'4','Wing Van'=>'3.5','Car Carrier'=>'2.5'] as $key=>$value)Settings::pricingSave($admin,['setting_key'=>$key,'value'=>$value,'effective_date'=>$today,'reference'=>'Isolated verified fixture']);
denyPricing(fn()=>Settings::pricingSave($admin,['setting_key'=>'garage','lat'=>14.126449,'lng'=>121.070384,'effective_date'=>$today,'reference'=>'test']),400,'Unverified garage coordinates refused');
Settings::pricingSave($admin,['setting_key'=>'garage','lat'=>14.12,'lng'=>121.07,'verified'=>'1','effective_date'=>$today,'reference'=>'Isolated surveyed fixture']);
denyPricing(fn()=>Settings::pricingSave($admin,['setting_key'=>'diesel','value'=>70,'effective_date'=>$today,'reference'=>'duplicate']),409,'Effective versions cannot overwrite history');
$tomorrow=date('Y-m-d',strtotime('+1 day'));Settings::pricingSave($admin,['setting_key'=>'diesel','value'=>80,'effective_date'=>$tomorrow,'reference'=>'future fixture']);
checkPricing(json_decode(Settings::pricingAt($p,'diesel',$today)['value_json'],true)['value']==='60','No Tuesday update preserves prior effective price; future value not selected');
$created=Trips::action($ops,'create',['client_id'=>$cid,'client_route_id'=>$route,'truck_type'=>'Container','dispatcher_assignment'=>'container_wingvan_dispatcher:Day','responsible_dispatcher_id'=>$day['id'],'pickup_point'=>'Fixture pickup','delivery_point'=>'Fixture delivery','scheduled_at'=>date('Y-m-d\TH:i',time()+300),'distance_km'=>100,'request_key'=>'11111111-1111-4111-8111-111111111111']);$tid=$created['trip_id'];
checkPricing((int)one($p,'SELECT current_dispatcher_id FROM trip_responsibilities WHERE trip_id=?',[$tid])['current_dispatcher_id']===$day['id'],'Approved route planning preserves explicit account assignment with no client_rates');
$driver=insert($p,'personnel',['full_name'=>'Pricing Driver','person_type'=>'Driver','license_number'=>'FIXTURE','license_expiry'=>'2099-01-01']);$helper=insert($p,'personnel',['full_name'=>'Pricing Helper','person_type'=>'Helper']);$vehicle=insert($p,'vehicles',['plate_number'=>'PRICE-TEST','truck_type'=>'Container','assigned_driver_id'=>$driver,'assigned_helper_id'=>$helper]);
foreach([$driver,$helper] as $person)Attendance::action($day,'time_in',['personnel_id'=>$person,'request_key'=>sprintf('%08x-1111-4111-8111-111111111111',$person)]);
Trips::action($day,'assign_resources',['trip_id'=>$tid,'vehicle_id'=>$vehicle,'helper_id'=>$helper]);
// Complete the unchanged prerequisites using isolated records; dispatch itself uses the real action.
run($p,"UPDATE trips SET status='Ready for Dispatch' WHERE id=?",[$tid]);
insert($p,'inspections',['trip_id'=>$tid,'vehicle_id'=>$vehicle,'attempt_number'=>1,'kind'=>'Pre-Dispatch','items_json'=>'[]','actor_id'=>$day['id'],'completed_at'=>date('Y-m-d H:i:s')]);
insert($p,'fund_requests',['fund_ref'=>'PRICE-FUND','trip_id'=>$tid,'attempt_number'=>1,'requested_by'=>$day['id'],'status'=>'Released','amount'=>100]);
Trips::action($day,'dispatch',['trip_id'=>$tid]);$bill=one($p,'SELECT * FROM billings WHERE trip_id=?',[$tid]);$snapshot=TripPricing::saved($p,(int)$bill['id']);
checkPricing($snapshot['fuel']==='3000.00'&&$snapshot['total']==='4200.00'&&$snapshot['diesel_value']['value']==='60','Dispatch freezes fixed1200 + (garage100+delivery100)/4*60 with actual date');
checkPricing(TripPricing::date('2026-11-12T17:00:00Z')==='2026-11-13','Dispatch date converted to Asia/Manila');
transaction(fn($p)=>BillingDraft::createFor($p,one($p,'SELECT * FROM trips WHERE id=?',[$tid])));checkPricing(TripPricing::saved($p,(int)$bill['id'])===$snapshot&&(int)$p->query('SELECT COUNT(*) FROM billings')->fetchColumn()===1,'Duplicate/redispatch cannot refresh or double-charge snapshot');
$review=Trips::action($day,'turnover_preview',['trip_ids'=>[$tid]]);$h=Trips::action($day,'turnover_endorse',['incoming_id'=>$night['id'],'trip_ids'=>[$tid],'fingerprint'=>$review['fingerprint'],'request_key'=>'22222222-2222-4222-8222-222222222222'])['turnover_id'];Trips::action($night,'turnover_accept',['turnover_id'=>$h]);
checkPricing(DispatcherAttendance::current($p,$driver)['time_out']===null,'Turnover preserves open attendance');
foreach([Trips::DOC_WAYBILL,Trips::DOC_AR,Trips::DOC_DR] as $category)insert($p,'documents',['entity_type'=>'trip','entity_id'=>$tid,'category'=>$category,'original_filename'=>'fixture.png','storage_name'=>bin2hex(random_bytes(16)),'mime_type'=>'image/png','byte_size'=>1,'uploaded_by'=>$day['id']]);
Trips::action($night,'complete',['trip_id'=>$tid,'completed_at'=>date('Y-m-d\TH:i'),'waybill_number'=>'TEST-WB']);Billing::action($billing,'verify',['billing_id'=>$bill['id']]);
$charge=Billing::action($billing,'charge_add',['billing_id'=>$bill['id'],'kind'=>'Additional Charge','description'=>'Verified fixture toll','amount'=>'100'])['charge_id'];Billing::action($admin,'charge_decide',['charge_id'=>$charge,'decision'=>'approve']);checkPricing(one($p,'SELECT total_amount FROM billings WHERE id=?',[$bill['id']])['total_amount']==='4300.00','Existing adjustment approval adds once alongside fixed and fuel');
$proposal=TripPricing::proposal($p,one($p,'SELECT * FROM billings WHERE id=?',[$bill['id']]));denyPricing(fn()=>Billing::action($admin,'pricing_recalculate',['billing_id'=>$bill['id'],'fingerprint'=>str_repeat('0',64),'reason'=>'stale']),409,'Stale recalculation preview rejected');
Billing::action($admin,'pricing_recalculate',['billing_id'=>$bill['id'],'fingerprint'=>$proposal['fingerprint'],'reason'=>'Verified isolated review']);
Billing::action($billing,'finalize',['billing_id'=>$bill['id'],'invoice_date'=>$today]);$_FILES=['ack_receipt'=>$upload];Billing::action($billing,'collect',['billing_id'=>$bill['id'],'amount'=>'4300','payment_date'=>$today,'payment_method'=>'Cash','ar_number'=>'TEST-AR','reference'=>'TEST-CASH']);$_FILES=[];
$paid=one($p,'SELECT * FROM billings WHERE id=?',[$bill['id']]);checkPricing($paid['status']==='Paid','Existing completion, document verification, finalization and collection lifecycle works');
$proposal=TripPricing::proposal($p,$paid);denyPricing(fn()=>Billing::action($admin,'pricing_recalculate',['billing_id'=>$bill['id'],'fingerprint'=>$proposal['fingerprint'],'reason'=>'forbidden']),409,'Paid invoice recalculation is preview only');checkPricing(one($p,'SELECT * FROM billings WHERE id=?',[$bill['id']])===$paid,'Paid invoice and amounts preserved');
// Missing-fuel path stays dispatchable, but cannot issue an invoice.
$missingTrip=insert($p,'trips',['intake_source'=>'Operations Head','scheduled_at'=>date('Y-m-d H:i:s'),'trip_ref'=>'PRICE-MISSING','created_by'=>$ops['id'],'client_id'=>$cid,'client_route_id'=>$route,'truck_type'=>'Container','pickup_point'=>'X','delivery_point'=>'Y','distance_km'=>100,'dispatched_at'=>date('Y-m-d H:i:s'),'status'=>'Completed']);
run($p,"UPDATE client_routes SET distance_source='Manual',origin_lat=14.15 WHERE id=?",[$route]);
Routing::testConfig(['ORS_API_KEY'=>'']);$pending=transaction(fn($p)=>BillingDraft::createFor($p,one($p,'SELECT * FROM trips WHERE id=?',[$missingTrip])));run($p,"UPDATE billings SET status='For Billing',docs_verified_at=NOW(),docs_verified_by=? WHERE id=?",[$billing['id'],$pending]);
checkPricing(TripPricing::saved($p,$pending)['fuel']===null,'Unavailable routing never becomes a zero fuel charge');
denyPricing(fn()=>Billing::action($billing,'finalize',['billing_id'=>$pending,'invoice_date'=>$today]),409,'Missing fuel inputs block invoice issuance server-side');
Routing::testConfig(['ORS_API_KEY'=>'isolated-test-key']);$repair=TripPricing::proposal($p,one($p,'SELECT * FROM billings WHERE id=?',[$pending]));checkPricing($repair['can_apply'],'Same-day missing road data can be resolved through verified backend routing');Billing::action($admin,'pricing_recalculate',['billing_id'=>$pending,'fingerprint'=>$repair['fingerprint'],'reason'=>'Provider restored, verified original coordinates']);checkPricing(TripPricing::saved($p,$pending)['fuel']!==null,'Reviewed resolution stores fuel once without a manual override');
$legacyTrip=insert($p,'trips',['intake_source'=>'Operations Head','scheduled_at'=>date('Y-m-d H:i:s'),'trip_ref'=>'PRICE-HISTORICAL','created_by'=>$ops['id'],'client_id'=>$cid,'truck_type'=>'Container','pickup_point'=>'X','delivery_point'=>'Y','status'=>'Completed']);$legacy=insert($p,'billings',['trip_id'=>$legacyTrip,'client_id'=>$cid,'status'=>'Paid','distance_km'=>100,'rate_per_km'=>10,'base_amount'=>1000,'total_amount'=>1000,'amount_paid'=>1000,'invoice_number'=>'OLD-TEST']);
$report=Billing::action($admin,'pricing_report',[]);$row=array_values(array_filter($report['rows'],fn($r)=>(int)$r['billing_id']===$legacy))[0];checkPricing($row['proposed']===null&&!$row['can_apply']&&count($row['missing'])===1,'Historical missing inputs are reported without invented prices or invoice rewrites');
checkPricing((int)$p->query('SELECT COUNT(*) FROM collections')->fetchColumn()===1,'No duplicate payment allocations');
// Focused Fuel Adjustment compatibility checks: no operational database is used.
$beforeSnapshot=TripPricing::saved($p,(int)$bill['id']);$beforeBill=one($p,'SELECT * FROM billings WHERE id=?',[$bill['id']]);
$beforeAt=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s.u');usleep(2000);
$zone=date_default_timezone_get();date_default_timezone_set('UTC');
try{Settings::pricingSave($admin,['setting_key'=>'Container','value'=>'5','reference'=>'First same-day efficiency update','effective_date'=>'2099-01-01']);}finally{date_default_timezone_set($zone);}
$first=Settings::pricingAt($p,'Container',(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s.u'));
checkPricing($first['effective_date']===(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d')&&$first['effective_at']>$beforeAt,'Efficiency ignores supplied dates and uses current Asia/Manila timestamp');
checkPricing(json_decode(Settings::pricingAt($p,'Container',$beforeAt)['value_json'],true)['value']==='4','Earlier dispatch timestamp retains the previous efficiency');
$between=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s.u');usleep(2000);
Settings::pricingSave($admin,['setting_key'=>'Container','value'=>'6','reference'=>'Second same-day efficiency update']);
checkPricing(json_decode(Settings::pricingAt($p,'Container',$between)['value_json'],true)['value']==='5','Timestamp lookup selects the correct intermediate efficiency version');
$read=Settings::pricingRead($admin);checkPricing(json_decode($read['current']['Container']['value_json'],true)['value']==='6','Confirmed efficiency persists as current km/L');
checkPricing(count(array_filter($read['history'],fn($r)=>$r['setting_key']==='Container'))===3,'Same-day updates retain all efficiency history versions');
checkPricing((int)one($p,'SELECT COUNT(*) n FROM pricing_settings_history WHERE setting_key=? AND effective_date=?',['Container',$today])['n']===1,'Repeated efficiency edits remain compatible with unchanged Migration 13 unique key');
checkPricing(TripPricing::saved($p,(int)$bill['id'])===$beforeSnapshot&&one($p,'SELECT * FROM billings WHERE id=?',[$bill['id']])===$beforeBill,'Efficiency edits preserve frozen dispatch pricing and paid invoice amounts');
insert($p,'pricing_settings_history',['setting_key'=>'Wing Van','effective_date'=>'2020-01-01','value_json'=>'{"value":"2"}','reference'=>'Legacy isolated history','actor_id'=>$admin['id']]);
checkPricing(json_decode(Settings::pricingAt($p,'Wing Van','2020-01-01 08:00:00')['value_json'],true)['value']==='2','Existing date-only efficiency history stays readable');
echo 'RESULT: '.$pass.' passed'.PHP_EOL;
