<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once dirname(__DIR__) . '/backend/Actions.php';
require_once __DIR__ . '/_launcher.php';
$database = envValue('DB_NAME');
test_guard([$database]);
$pass=0; $fail=0;
function verify(bool $condition, string $message): void {
    global $pass,$fail;
    if ($condition) { ++$pass; echo "PASS $message\n"; }
    else { ++$fail; echo "FAIL $message\n"; }
}
function refused(callable $action, int $status, string $message): void {
    try { $action(); verify(false,$message); }
    catch (BusinessRuleException $e) { verify($e->httpStatus === $status,$message); }
}
try {
    test_recreate($database); $p=db();
    verify($p->query('SELECT DATABASE()')->fetchColumn() === $database,'Active DB identity verified');
    test_import($p,test_sql('database/schema.sql')); test_import($p,test_sql('database/extension.sql'));
    foreach (['Purchasing','Finance'] as $module) Actions::load($module);
    $users=[];
    foreach (['purchasing','motorpool','finance'] as $role) {
        $id=insert($p,'users',['username'=>$role,'email'=>$role.'@example.test','password_hash'=>'disabled','full_name'=>$role,'role'=>$role]);
        $users[$role]=['id'=>$id,'role'=>$role,'full_name'=>$role,'email'=>$role.'@example.test'];
    }
    $buyer=$users['purchasing']; $pool=$users['motorpool']; $fin=$users['finance'];
    $truck=insert($p,'vehicles',['plate_number'=>'INV-001','truck_type'=>'Wing Van']);
    // Integrated stock control (luis-maintenance-ui): a new part starts at 0; stock enters only through PO receiving or an Admin-reviewed count.
    $part=['part_number'=>'INV-TIRE','description'=>'Test tire','unit'=>'piece','unit_cost'=>'100','reorder_level'=>2];
    Purchasing::action($buyer,'part_save',$part);
    $pid=(int)one($p,'SELECT id FROM parts WHERE part_number=?',['INV-TIRE'])['id'];
    $stock=fn()=>(int)one($p,'SELECT quantity_on_hand FROM parts WHERE id=?',[$pid])['quantity_on_hand'];
    verify($stock()===0,'New part starts with 0 on hand');
    Finance::action($fin,'budget_set',['amount'=>'1000','period_start'=>date('Y-m-d'),'period_end'=>date('Y-m-d',time()+86400)]);
    Purchasing::action($buyer,'po_create',['supplier_name'=>'Test supplier','supplier_address'=>'Test address','purpose'=>'Opening tire stock','lines'=>[['part_id'=>$pid,'quantity'=>5,'unit_price'=>100]]]);
    $po=(int)one($p,'SELECT MAX(id) AS id FROM purchase_orders')['id'];
    Purchasing::action($fin,'po_decide',['po_id'=>$po,'decision'=>'approve']);
    $pol=(int)one($p,'SELECT id FROM purchase_order_lines WHERE purchase_order_id=?',[$po])['id'];
    Purchasing::action($buyer,'po_receive',['po_id'=>$po,'receive'=>[['line_id'=>$pol,'qty'=>5]],'supplier_invoice_no'=>'SI-INV-1']);
    verify($stock()===5,'Stock received on an approved PO');
    refused(fn()=>Purchasing::action($buyer,'part_save',$part),409,'Duplicate part number rejected');
    refused(fn()=>Purchasing::action($buyer,'adjust',['part_id'=>$pid,'quantity_delta'=>-6,'reason'=>'Test']),403,'Manual stock adjustment rejected (stock count review only)');
    verify($stock()===5,'Rejected adjustment leaves stock unchanged');
    $request=['vehicle_id'=>$truck,'purpose'=>'Replace damaged tire','lines'=>[['part_id'=>$pid,'quantity'=>3]]];
    Purchasing::action($pool,'request_create',$request);
    $rid=(int)one($p,'SELECT id FROM part_requests ORDER BY id DESC LIMIT 1')['id'];
    $line=(int)one($p,'SELECT id FROM part_request_lines WHERE part_request_id=?',[$rid])['id'];
    $release=['request_id'=>$rid,'release'=>[['line_id'=>$line,'qty'=>3]]];
    Purchasing::action($buyer,'request_process',$release);
    verify($stock()===2,'Stock release deducts exact requested quantity');
    verify(one($p,'SELECT status FROM part_requests WHERE id=?',[$rid])['status']==='Released','Request becomes Released');
    refused(fn()=>Purchasing::action($buyer,'request_process',$release),409,'Duplicate release rejected');
    verify($stock()===2,'Duplicate release changes no inventory');
    verify((int)one($p,"SELECT COUNT(*) AS n FROM stock_movements WHERE part_id=? AND movement_type='Stock Out'",[$pid])['n']===1,'Exactly one stock-out movement recorded');
    $bad=$request; $bad['lines'][]=$bad['lines'][0];
    refused(fn()=>Purchasing::action($pool,'request_create',$bad),400,'Duplicate request lines rejected');
    Finance::action($fin,'payable_add',['payee'=>'Test supplier','category'=>'Parts','description'=>'Test payable','amount'=>'250']);
    $aid=(int)one($p,'SELECT id FROM payables ORDER BY id DESC LIMIT 1')['id'];
    $pay=['payable_id'=>$aid,'amount'=>'100','payment_method'=>'Cash','released_on'=>date('Y-m-d')];
    Finance::action($fin,'payable_pay',$pay);
    $a=one($p,'SELECT * FROM payables WHERE id=?',[$aid]);
    verify($a['status']==='Partially Paid' && $a['paid_amount']==='100.00','Partial payment updates balance and status');
    refused(fn()=>Finance::action($fin,'payable_pay',array_replace($pay,['amount'=>'151'])),400,'Overpayment rejected');
    Finance::action($fin,'payable_pay',array_replace($pay,['amount'=>'150']));
    verify(one($p,'SELECT status FROM payables WHERE id=?',[$aid])['status']==='Paid','Final payment settles payable');
    refused(fn()=>Finance::action($fin,'payable_pay',$pay),409,'Payment on settled payable rejected');
    $sum=one($p,"SELECT COUNT(*) AS n,SUM(amount) AS amount FROM disbursements WHERE source_type='payable' AND source_id=?",[$aid]);
    verify((int)$sum['n']===2 && $sum['amount']==='250.00','Rejected payments add no disbursements');
    $expense=one($p,"SELECT SUM(amount) AS amount FROM expenses WHERE source='Payable' AND source_id=?",[$aid]);
    verify($expense['amount']==='250.00','Expenses reconcile to actual payable payments');
    $budget=['amount'=>'1000','period_start'=>date('Y-m-d'),'period_end'=>date('Y-m-d',time()+86400)];
    Finance::action($fin,'budget_set',$budget); Finance::action($fin,'budget_set',$budget);
    verify((int)one($p,"SELECT COUNT(*) AS n FROM budgets WHERE category='Purchasing' AND status='Active'")['n']===1,'Only latest purchasing budget remains Active');
    refused(fn()=>Finance::action($fin,'budget_set',array_replace($budget,['period_end'=>'2020-01-01'])),400,'Reversed budget dates rejected');
} catch (Throwable $e) { verify(false,get_class($e).': '.$e->getMessage()); }
echo "RESULT: $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
