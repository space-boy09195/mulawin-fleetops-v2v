<?php
/*
 * =====================================================================
 *  backend/Core/Settings.php  —  SYSTEM SETTINGS (simple values)
 * =====================================================================
 *  Where does a value come from?
 *    1. A value saved by the Admin in Administration > Settings (table app_settings)
 *    2. Otherwise the default below.
 *  Some defaults are read from config/formulas.php (values the company
 *  already gave: purchase limit 10,000; 1 liter = 4 km; reminder days ...).
 *
 *  Fund request categories are kept in their own table (fund_categories).
 * =====================================================================
 */
declare(strict_types=1);

final class Settings {
    /** Financial settings have no illustrative defaults; versions are append-only. */
    public static function pricingAt(PDO $p,string $key,string $date): ?array {
        $moment=new DateTimeImmutable($date,new DateTimeZone('Asia/Manila'));$moment=$moment->setTimezone(new DateTimeZone('Asia/Manila'));$day=$moment->format('Y-m-d');
        if(!in_array($key,TRUCK_TYPES,true))return one($p,'SELECT * FROM pricing_settings_history WHERE setting_key=? AND effective_date<=? ORDER BY effective_date DESC LIMIT 1',[$key,$day]);
        $now=new DateTimeImmutable('now',new DateTimeZone('Asia/Manila'));
        $at=strlen($date)>10?($moment->format('Y-m-d H:i:s').(strlen($date)===19?'.999999':$moment->format('.u'))):($day===$now->format('Y-m-d')?$now->format('Y-m-d H:i:s.u'):$day.' 23:59:59.999999');
        foreach(many($p,'SELECT * FROM pricing_settings_history WHERE setting_key=? AND effective_date<=? ORDER BY effective_date DESC',[$key,$day]) as $row){
            $value=json_decode($row['value_json'],true,512,JSON_THROW_ON_ERROR);
            $versions=$value['versions']??[['value'=>$value['value'],'effective_at'=>$row['effective_date'].' 00:00:00','reference'=>$row['reference'],'actor_id'=>$row['actor_id']]];
            foreach(array_reverse($versions) as $version)if($version['effective_at']<=$at){$row['effective_at']=$version['effective_at'];$row['reference']=$version['reference'];$row['actor_id']=$version['actor_id'];$row['value_json']=json_encode($version,JSON_THROW_ON_ERROR);return $row;}
        }
        return null;
    }
    public static function pricingRead(array $u): array {
        requireRole($u,'admin');core('TripPricing');must(TripPricing::ready(db()),'Install and verify pricing migration 13.',409);
        $p=db();$history=[];$current=[];$now=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s.u');
        foreach(many($p,'SELECT * FROM pricing_settings_history ORDER BY effective_date DESC,id DESC') as $row){
            $value=json_decode($row['value_json'],true,512,JSON_THROW_ON_ERROR);
            if(in_array($row['setting_key'],TRUCK_TYPES,true)&&isset($value['versions']))foreach(array_reverse($value['versions']) as $version)$history[]=array_replace($row,['effective_at'=>$version['effective_at'],'reference'=>$version['reference'],'actor_id'=>$version['actor_id'],'value_json'=>json_encode(['value'=>$version['value']],JSON_THROW_ON_ERROR)]);
            else $history[]=$row;
        }
        foreach(['diesel','garage',...TRUCK_TYPES] as $key)$current[$key]=self::pricingAt($p,$key,$now);
        return ['history'=>$history,'current'=>$current,'truckTypes'=>TRUCK_TYPES];
    }
    public static function pricingSave(array $u,array $d): array {
        requireRole($u,'admin');core('TripPricing');must(TripPricing::ready(db()),'Install and verify pricing migration 13.',409);
        $key=choiceField($d,'setting_key',['diesel','garage',...TRUCK_TYPES]);$efficiency=in_array($key,TRUCK_TYPES,true);
        $now=new DateTimeImmutable('now',new DateTimeZone('Asia/Manila'));$effectiveAt=$now->format('Y-m-d H:i:s.u');
        $date=$efficiency?$now->format('Y-m-d'):dateField($d,'effective_date');
        if(!$efficiency&&$date<$now->format('Y-m-d'))must(($d['historical_verified']??null)==='1','Historical inputs require explicit verification against the referenced source; do not invent past prices or efficiency.',409);
        $ref=field($d,'reference',300);
        if($key==='garage'){
            must(($d['verified']??null)==='1','Confirm the garage entrance coordinates have been verified.');
            $lat=filter_var($d['lat']??null,FILTER_VALIDATE_FLOAT);$lng=filter_var($d['lng']??null,FILTER_VALIDATE_FLOAT);
            must($lat!==false&&$lng!==false&&$lat>=4&&$lat<=21.5&&$lng>=116&&$lng<=127.5,'Choose verified Philippine garage coordinates.');$value=['lat'=>$lat,'lng'=>$lng];
        }else{
            $raw=(string)($d['value']??'');must((bool)preg_match('/^[0-9]{1,5}(\.[0-9]{1,4})?$/',$raw)&&(float)$raw>0&&(float)$raw<=($key==='diesel'?1000:100),'Enter a positive valid price or efficiency.');$value=['value'=>$raw];
        }
        return transaction(function(PDO $p)use($u,$key,$date,$ref,$value,$efficiency,$effectiveAt){
            if($efficiency){
                $old=one($p,'SELECT * FROM pricing_settings_history WHERE setting_key=? AND effective_date=? FOR UPDATE',[$key,$date]);
                $clock=new DateTimeImmutable('now',new DateTimeZone('Asia/Manila'));must($clock->format('Y-m-d')===$date,'The Manila date changed while saving. Review and confirm again.',409);$effectiveAt=$clock->format('Y-m-d H:i:s.u');
                $versions=[];if($old){$previous=json_decode($old['value_json'],true,512,JSON_THROW_ON_ERROR);$versions=$previous['versions']??[['value'=>$previous['value'],'effective_at'=>$date.' 00:00:00','reference'=>$old['reference'],'actor_id'=>$old['actor_id']]];}
                $versions[]=['value'=>$value['value'],'effective_at'=>$effectiveAt,'reference'=>$ref,'actor_id'=>$u['id']];
                $value+=['effective_at'=>$effectiveAt,'versions'=>$versions];
                if($old){run($p,'UPDATE pricing_settings_history SET value_json=?,reference=?,actor_id=? WHERE id=?',[json_encode($value,JSON_THROW_ON_ERROR),$ref,$u['id'],$old['id']]);audit($p,$u['id'],'Pricing','setting '.$old['id'],'efficiency version added',$key.' effective '.$effectiveAt.'; '.$ref);return ['message'=>'Efficiency updated. Previous versions and dispatch snapshots are preserved.'];}
            }
            try{$id=insert($p,'pricing_settings_history',['setting_key'=>$key,'effective_date'=>$date,'value_json'=>json_encode($value,JSON_THROW_ON_ERROR),'reference'=>$ref,'actor_id'=>$u['id']]);}catch(PDOException $e){if((int)($e->errorInfo[1]??0)===1062)throw new BusinessRuleException('An effective version already exists for this date. It was not overwritten.',409);throw $e;}
            audit($p,$u['id'],'Pricing','setting '.$id,'effective pricing version added',$key.' effective '.($efficiency?$effectiveAt:$date).'; '.$ref);return ['message'=>'Pricing version saved. Previous versions remain unchanged.'];
        });
    }
    /**
     * key => [label, default, unit, help, min, max]
     * Defaults marked "config" are taken from config/formulas.php when present.
     */
    public const DEFS = [
        'purchase_approval_threshold' => ['Purchase approval limit', 10000, 'PHP',
            'Purchase orders up to this amount go to Finance for approval; above it they go to the Admin (PDF #12).', 1, 100000000],
        'document_alert_days'         => ['Remind about expiring truck documents', 30, 'days before',
            'OR/CR registration, insurance and uploaded documents with an expiry date.', 1, 365],
        'license_alert_days'          => ['Remind about expiring driver licenses', 30, 'days before', '', 1, 365],
        'default_payment_terms_days'  => ['Default client payment terms', 30, 'days',
            'Used when the client has no payment terms. Due date = invoice date + terms.', 0, 365],
        'vat_rate'                    => ['VAT rate shown on the service invoice', 12, '%',
            'Billed amounts are VAT-inclusive. The invoice shows VATable sales and VAT. Use 0 if not VAT-registered.', 0, 30],
        'km_per_liter'                => ['Kilometers per liter of diesel', 4, 'km/L',
            'For the estimated diesel cost of a trip (PDF #13: 1 liter = 4 km).', 0.1, 100],
        'fuel_price_per_liter'        => ['Diesel price per liter', 100, 'PHP/L',
            'Used with the value above: estimated diesel = (km / km per liter) x price.', 0, 1000],
        'units_per_trip'              => ['Default units per car carrier trip', 5, 'units',
            'First value of "units" on a car carrier trip; can be changed per trip.', 1, 20],
        // CHECKLIST_40_FINANCE_WINDOW_V1: Admin can configure when Finance may release.
        'fund_release_lead_hours'       => ['Enable trip fund release before dispatch', 2, 'hours before',
            'Finance can release a Ready for Release trip fund this many hours before its scheduled dispatch. 0 = not before the scheduled time.', 0, 24],
        // CHECKLIST_38_40_TRIP_TIME_GATES_V1: when the dispatcher may start execution actions (Trips.php timeGates()).
        'checklist_lead_hours'        => ['Enable pre-dispatch checklist before dispatch', 2, 'hours before',
            'The dispatcher can open and submit the pre-dispatch checklist this many hours before the scheduled dispatch. 0 = not before the scheduled time.', 0, 24],
        'dispatch_early_minutes'      => ['Allow Confirm Dispatch before the scheduled time', 15, 'minutes before',
            'Confirm Dispatch / Redispatch is enabled this many minutes before the scheduled dispatch (it still needs Ready for Dispatch and a Released fund). 0 = only at the scheduled time.', 0, 240],
        // CHECKLIST_42_ATTENDANCE_ENFORCEMENT_V1: 1 = Warn (default), 2 = Enforce (crew must be Present for the dispatch date).
        'crew_attendance_dispatch_check' => ['Crew attendance check at Confirm Dispatch', 1, 'mode',
            'Warn: dispatch is allowed and crew who are not timed in or are marked Absent are noted in the trip history. Enforce: Confirm Dispatch and Redispatch need every assigned driver and helper timed in by the trip\'s Dispatcher group and not marked Absent by the Admin.', 1, 2],
        // DAILY_ATTENDANCE_V1: one daily reminder hour (0-23, Philippine time). Key name kept so the saved value stays valid.
        // It is also the Day label start for Dispatcher Time In sessions (CrewAttendance::shiftFor).
        'attendance_day_start_hour' => ['Daily attendance reminder starts', 6, 'Philippine time', 'Time of day from which the Admin bell reminds about driver, helper and mechanic attendance that is not marked yet for today. Also starts the "Day" label of Dispatcher Time In sessions.', 0, 23],
        // Kenneth (dispatcher sessions): labels a Time In session Day or Night for reports only; it does not restrict who may work.
        'attendance_night_start_hour' => ['Night label of Dispatcher sessions starts', 18, 'Philippine time', 'Time from which a Dispatcher Time In session is labelled "Night" in reports. Labels only: it does not restrict personnel. Should differ from the daily reminder hour.', 0, 23],
        'inspection_min_minutes'      => ['Minimum checklist duration', 15, 'minutes',
            'Pre-dispatch and arrival checklists: the earliest a checklist can be submitted, counted from when the dispatcher opened it (PDF #11 section G). 0 = no minimum.', 0, 120],
    ];
    /** Settings that are a time of day (whole hour 0-23, Philippine time). */
    public const HOUR_OF_DAY = ['attendance_day_start_hour', 'attendance_night_start_hour'];
    private const FROM_CONFIG = ['purchase_approval_threshold', 'document_alert_days', 'license_alert_days',
                                 'default_payment_terms_days', 'km_per_liter', 'fuel_price_per_liter', 'units_per_trip',
                                 'inspection_min_minutes'];

    private static ?array $saved = null;
    private static ?array $config = null;

    public static function clearCache(): void { self::$saved = null; }

    private static function config(): array {
        if (self::$config === null) {
            $file = dirname(__DIR__, 2) . '/config/formulas.php';
            $cfg = is_file($file) ? require $file : [];
            self::$config = is_array($cfg) ? ($cfg['values'] ?? []) : [];
        }
        return self::$config;
    }

    private static function saved(): array {
        if (self::$saved === null) {
            self::$saved = [];
            try {
                foreach (many(db(), 'SELECT setting_key, setting_value FROM app_settings') as $r) self::$saved[$r['setting_key']] = $r['setting_value'];
            } catch (Throwable $e) { error_log('Settings read failed: ' . $e->getMessage()); }
        }
        return self::$saved;
    }

    public static function default(string $key): float {
        must(isset(self::DEFS[$key]), 'Unknown setting ' . $key);
        if (in_array($key, self::FROM_CONFIG, true) && isset(self::config()[$key]['value'])) return (float)self::config()[$key]['value'];
        return (float)self::DEFS[$key][1];
    }

    public static function value(string $key): float {
        $saved = self::saved();
        return array_key_exists($key, $saved) ? (float)$saved[$key] : self::default($key);
    }

    /** All settings for the Administration > Settings page. */
    public static function all(): array {
        $out = [];
        foreach (self::DEFS as $key => [$label, , $unit, $help, $min, $max]) {
            if(in_array($key,['km_per_liter','fuel_price_per_liter'],true))continue; // Financial inputs live in effective history.
            $out[] = ['key' => $key, 'label' => $label, 'unit' => $unit, 'help' => $help, 'min' => $min, 'max' => $max,
                      'value' => self::value($key), 'default' => self::default($key),
                      // CHECKLIST_42_ATTENDANCE_PHASE2_V1: shown and chosen as 12-hour times; stored as hours 0-23.
                      'format' => in_array($key, self::HOUR_OF_DAY, true) ? 'hour' : ($key === 'crew_attendance_dispatch_check' ? 'attendance_mode' : 'number')];
        }
        return $out;
    }

    /** Estimated diesel cost of a trip: (km / km per liter) x diesel price. */
    public static function fuelEstimate(float $km): float {
        $kpl = self::value('km_per_liter');
        return $kpl > 0 ? round($km / $kpl * self::value('fuel_price_per_liter'), 2) : 0.0;
    }
}
