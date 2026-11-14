<?php
/*
 * =====================================================================
 *  tests/map_tests.php  —  Road distance / OpenStreetMap tests (Checklist #5)
 * =====================================================================
 *  Do NOT run this file directly: run tests/run_map_tests.php (temporary copy, own .env,
 *  disposable *_test databases only). No real call is made to openrouteservice: the HTTP
 *  transport is replaced by a fake one, and the real curl code is tested against a local
 *  mock server (tests/_mock_ors.php) on 127.0.0.1.
 *
 *  Groups:
 *    A. Configuration, secrecy of the API key, settings for the screen
 *    B. Route requests sent to ORS (URL, header, body, options, profile)
 *    C. Answers, rounding, sanity warnings, input checks
 *    D. Errors -> clear "type the km" messages (no key in messages or logs)
 *    E. Cache, daily guard, per-user limit
 *    F. Address search and reverse
 *    G. Maps module: roles, unavailable answer
 *    H. Client Routes: server re-verification, override reason, 15% warning, Manual fallback
 *    I. Real curl code against the local mock server (http only allowed for 127.0.0.1 in tests)
 *    J. Migration 04: partially failed import, detection and recovery
 *    K. Screen helpers: polyline decoding (Node.js), map.js / core.js split
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once dirname(__DIR__) . '/backend/Actions.php';
require_once __DIR__ . '/_launcher.php';
core('Access');
core('Routing');

$FRESH = envValue('DB_NAME'); $PARTIAL = envValue('TEST_DB_PARTIAL');
test_guard([$FRESH, $PARTIAL]);

$PASS = 0; $FAIL = 0; $FAILED = [];
function ok(bool $c, string $name, string $detail = ''): void {
    global $PASS, $FAIL, $FAILED;
    if ($c) { $PASS++; echo "  PASS  $name\n"; } else { $FAIL++; $FAILED[] = $name; echo "  FAIL  $name" . ($detail !== '' ? "  -> $detail" : '') . "\n"; }
}
function fails(callable $fn, string $name, ?int $code = null, string $contains = ''): void {
    try { $fn(); ok(false, $name, 'no error was raised'); }
    catch (BusinessRuleException $e) { ok(($code === null || $e->httpStatus === $code) && ($contains === '' || stripos($e->getMessage(), $contains) !== false), $name, "got {$e->httpStatus}: {$e->getMessage()}"); }
    catch (Throwable $e) { ok(false, $name, get_class($e) . ': ' . $e->getMessage()); }
}
/** Expects RoutingUnavailable with the given reason. */
function unavailable(callable $fn, string $reason, string $name): ?RoutingUnavailable {
    try { $fn(); ok(false, $name, 'no error was raised'); return null; }
    catch (RoutingUnavailable $e) { ok($e->reason === $reason, $name, "reason {$e->reason}: {$e->getMessage()}"); return $e; }
    catch (Throwable $e) { ok(false, $name, get_class($e) . ': ' . $e->getMessage()); return null; }
}
function section(string $t): void { echo "\n== $t ==\n"; }

const KEY = 'TEST-ORS-KEY-9f3c2b-SECRET';
$LOG = tempnam(sys_get_temp_dir(), 'maplog');
ini_set('error_log', $LOG);
ini_set('display_errors', 'stderr');

// Fake ORS: records every request, answers like ORS.
$REQ = []; $NEXT = null;
$fake = function (string $method, string $url, array $headers, ?string $body) use (&$REQ, &$NEXT): array {
    $REQ[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
    if ($NEXT !== null) { $r = $NEXT; $NEXT = null; return $r; }
    if (str_contains($url, '/directions/')) {
        $j = json_decode((string)$body, true);
        $a = [$j['coordinates'][0][1], $j['coordinates'][0][0]]; $b = [$j['coordinates'][1][1], $j['coordinates'][1][0]];
        $m = round(Routing::straightKm($a, $b) * 1000 * (empty($j['options']['avoid_features']) ? 1.25 : 1.4), 1);
        return [200, json_encode(['routes' => [['summary' => ['distance' => $m, 'duration' => $m / 12], 'geometry' => '_p~iF~ps|U_ulLnnqC_mqNvxq`@']]])];
    }
    if (str_contains($url, '/pelias/v1/search')) return [200, json_encode(['features' => [
        ['geometry' => ['coordinates' => [121.1497, 14.0863]], 'properties' => ['label' => 'Tanauan City, Batangas, Philippines']],
        ['geometry' => ['coordinates' => [139.69, 35.68]], 'properties' => ['label' => 'Tokyo, Japan']],
        ['geometry' => ['coordinates' => [121.1588, 14.0447]], 'properties' => ['label' => 'Malvar, Batangas, Philippines']]]])];
    if (str_contains($url, '/pelias/v1/reverse')) return [200, json_encode(['features' => [['properties' => ['label' => 'Poblacion, Tanauan City']]]])];
    return [404, '{}'];
};
$cfg = fn(array $over = []) => Routing::testConfig($over + ['ORS_API_KEY' => KEY, 'ORS_BASE_URL' => 'https://api.heigit.org', 'ORS_PROFILE' => 'driving-hgv']);

// ---------------------------------------------------------------------
section('Setup: disposable fresh database');
test_recreate($FRESH); test_recreate($PARTIAL);
$p = db();
ok($p->query('SELECT DATABASE()')->fetchColumn() === $FRESH, 'db() is the disposable fresh test database');
test_import($p, test_sql('database/schema.sql'));
test_import($p, test_sql('database/extension.sql'));
$users = [];
foreach (array_keys(ROLE_LABELS) as $role) {
    $id = insert($p, 'users', ['username' => $role, 'email' => "$role@example.test", 'password_hash' => 'x', 'full_name' => strtoupper($role) . ' USER', 'role' => $role]);
    $users[$role] = ['id' => $id, 'username' => $role, 'email' => "$role@example.test", 'full_name' => strtoupper($role) . ' USER', 'role' => $role, 'shift' => null];
}
$U = fn(string $r) => $users[$r];
Actions::load('Maps'); Actions::load('Routes');
$TAN = [14.0863, 121.1497]; $MAL = [14.0447, 121.1588]; $STR = [14.3122, 121.1114];

// ---------------------------------------------------------------------
section('A. Configuration and secrecy of the API key');
Routing::testConfig(['ORS_API_KEY' => '']);
ok(!Routing::configured(), 'no ORS_API_KEY -> routing is not configured');
unavailable(fn() => Routing::route($p, 1, $TAN, $MAL, 'recommended'), 'not_configured', 'no key -> "not set up, type the km"');
$cfg();
ok(Routing::configured(), 'with ORS_API_KEY -> configured');
Routing::useTransport($fake);
$_SESSION = ['uid' => $users['operations_head']['id'], 'last_seen' => time(), 'login_at' => time() + 5];
require_once dirname(__DIR__) . '/backend/Data.php';
$me = Data::me();
$meJson = json_encode($me);
ok(isset($me['map']) && $me['map']['routing'] === true && $me['map']['tileUrl'] === 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', 'screen settings: OpenStreetMap tiles, routing on');
ok(!str_contains($meJson, KEY) && !str_contains($meJson, 'mapKey') && !str_contains(strtolower($meJson), 'maptiler'), 'screen settings never contain the ORS key or MapTiler settings');
ok(count($me['map']['routeOptions']) === 3 && isset($me['map']['routeOptions']['recommended'], $me['map']['routeOptions']['avoid_tolls'], $me['map']['routeOptions']['general_roads']), 'three route options for the screen');

// ---------------------------------------------------------------------
section('B. Requests sent to openrouteservice');
$REQ = [];
$r = Routing::route($p, $users['operations_head']['id'], $TAN, $MAL, 'recommended');
$q = $REQ[0] ?? [];
ok(($q['method'] ?? '') === 'POST' && ($q['url'] ?? '') === 'https://api.heigit.org/openrouteservice/v2/directions/driving-hgv/json', 'POST to api.heigit.org/openrouteservice/v2/directions/driving-hgv/json', $q['url'] ?? '');
ok(in_array('Authorization: ' . KEY, $q['headers'] ?? [], true) && !str_contains($q['url'] ?? '', KEY), 'key sent only in the Authorization header (never in the URL)');
ok((bool)preg_grep('/^User-Agent: MulawinFleetOps/', $q['headers'] ?? []), 'identifying User-Agent sent');
$b = json_decode((string)($q['body'] ?? ''), true);
ok($b['coordinates'] === [[121.1497, 14.0863], [121.1588, 14.0447]], 'coordinates in ORS order [lng, lat]', json_encode($b['coordinates'] ?? null));
ok(($b['options']['vehicle_type'] ?? null) === 'hgv' && !isset($b['options']['avoid_features']) && $b['units'] === 'm', 'Recommended: truck (hgv), nothing avoided');
foreach (['avoid_tolls' => ['tollways'], 'general_roads' => ['highways', 'tollways']] as $opt => $avoid) {
    $REQ = []; Routing::route($p, $users['operations_head']['id'], $TAN, $MAL, $opt);
    $b = json_decode((string)($REQ[0]['body'] ?? ''), true);
    ok(($b['options']['avoid_features'] ?? null) === $avoid, "$opt -> avoid_features " . json_encode($avoid));
}
$cfg(['ORS_PROFILE' => 'driving-car']);
$REQ = []; Routing::route($p, 1, $TAN, $STR, 'recommended');
ok(str_contains($REQ[0]['url'] ?? '', '/driving-car/json') && !isset(json_decode((string)$REQ[0]['body'], true)['options']['vehicle_type']), 'ORS_PROFILE=driving-car: car profile, no vehicle_type');
$cfg(['ORS_PROFILE' => 'rocket']);
ok(Routing::profile() === 'driving-hgv', 'unknown profile falls back to driving-hgv');
$cfg();

// ---------------------------------------------------------------------
section('C. Answers, rounding, warnings, input checks');
$straight = Routing::straightKm([14.0863, 121.1497], [14.0447, 121.1588]);
ok(abs($r['distance_km'] - round($straight * 1.25, 2)) <= 0.011 && $r['distance_km'] === round($r['distance_km'], 2), 'distance in km with 2 decimals (meters / 1000, not rounded up)', (string)$r['distance_km']);
ok($r['provider'] === 'openrouteservice/driving-hgv' && $r['option'] === 'recommended' && $r['geometry'] === '_p~iF~ps|U_ulLnnqC_mqNvxq`@' && $r['warning'] === '', 'provider, option and route line returned');
$NEXT = [200, json_encode(['routes' => [['summary' => ['distance' => 1000, 'duration' => 60], 'geometry' => 'abc']]])];
ok(str_contains(Routing::route($p, 1, $TAN, [14.20, 121.15], 'recommended')['warning'], 'shorter than the straight-line'), 'road shorter than straight line -> warning');
$NEXT = [200, json_encode(['routes' => [['summary' => ['distance' => 200000, 'duration' => 60], 'geometry' => 'abc']]])];
ok(str_contains(Routing::route($p, 1, $TAN, [14.15, 121.15], 'recommended')['warning'], 'more than 3 times'), 'road > 3x straight line -> warning');
$NEXT = [200, json_encode(['routes' => [['summary' => ['distance' => 9000, 'duration' => 60], 'geometry' => "bad<script>"]]])];
ok(Routing::route($p, 1, $TAN, [14.16, 121.15], 'recommended')['geometry'] === '', 'route line with invalid characters is dropped');
$NEXT = [200, json_encode(['routes' => [['summary' => ['distance' => 3100000, 'duration' => 60]]]])];
fails(fn() => Routing::route($p, 1, $TAN, [14.17, 121.15], 'recommended'), 'road distance above 3,000 km refused');
fails(fn() => Routing::route($p, 1, [35.68, 139.69], $MAL, 'recommended'), 'pin outside the Philippines refused', 400, 'outside');
fails(fn() => Routing::route($p, 1, ['x', 121], $MAL, 'recommended'), 'incomplete pin refused', 400, 'incomplete');
fails(fn() => Routing::route($p, 1, $TAN, $TAN, 'recommended'), 'same spot refused', 400, 'same spot');
fails(fn() => Routing::route($p, 1, $TAN, $MAL, 'fly'), 'unknown route option refused', 400, 'Unknown route option');

// ---------------------------------------------------------------------
section('D. Errors from the map service -> clear messages, manual km');
$errs = [[0, '', 'network'], [401, '{"error":"x"}', 'refused'], [403, '{"error":"Quota exceeded"}', 'refused'], [429, '{"error":"Rate"}', 'rate_limited'],
         [404, '{"error":{"code":2010,"message":"Could not find routable point"}}', 'not_near_road'], [400, '{"error":{"code":2004,"message":"too long"}}', 'too_long'],
         [404, '{"error":{"code":2009,"message":"Route could not be found"}}', 'no_route'], [500, 'oops', 'provider_error'], [200, 'not json', 'invalid_response'], [200, '{"routes":[]}', 'no_route']];
$i = 0;
foreach ($errs as [$st, $body, $reason]) {
    $NEXT = [$st, $body]; $i++;
    $e = unavailable(fn() => Routing::route($p, 1, $TAN, [14.30 + $i / 1000, 121.10], 'recommended'), $reason, "HTTP $st" . ($body !== '' && $body[0] === '{' ? ' ' . substr($body, 0, 40) : '') . " -> $reason");
    if ($e) ok(!str_contains($e->getMessage(), KEY) && stripos($e->getMessage(), 'km') !== false, "  message tells the user to type the km and has no key");
}
ok(!str_contains((string)file_get_contents($LOG), KEY), 'server error log never contains the API key');
ok(str_contains((string)file_get_contents($LOG), 'ORS route failed: HTTP 429'), 'failures are written to the server error log (status only)');
foreach (['http://evil.example', 'https://user:pw@api.heigit.org', 'https://api.heigit.org/?x=1', 'ftp://api.heigit.org', 'javascript:alert(1)'] as $bad) {
    $cfg(['ORS_BASE_URL' => $bad]); $before = Routing::$outboundCalls;
    unavailable(fn() => Routing::route($p, 1, $TAN, [14.40, 121.10], 'recommended'), 'config', "ORS_BASE_URL '$bad' refused");
    ok(Routing::$outboundCalls === $before, '  no call was made');
}
$cfg();

// ---------------------------------------------------------------------
section('E. Cache, daily guard, per-user limit');
$before = Routing::$outboundCalls;
$c1 = Routing::route($p, 1, $TAN, $MAL, 'recommended');
ok(Routing::$outboundCalls === $before && $c1['cached'] === true, 'same points + option again -> answered from the cache, no new call');
Routing::route($p, 1, [14.086302, 121.149701], [14.044699, 121.158801], 'recommended');
ok(Routing::$outboundCalls === $before, 'points within ~1 m (rounded to 5 decimals) share the cache');
Routing::route($p, 1, $TAN, $MAL, 'avoid_tolls');
ok(Routing::$outboundCalls === $before, 'each option has its own cached answer');
$p->exec("UPDATE map_cache SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE service='route'");
Routing::route($p, 1, $TAN, $MAL, 'recommended');
ok(Routing::$outboundCalls === $before + 1, 'expired cache -> new call');
$p->exec('DELETE FROM map_api_usage');
$cfg(['ORS_DAILY_LIMIT_ROUTE' => '3', 'MAP_USER_PER_MINUTE' => '1000']);
for ($k = 1; $k <= 3; $k++) Routing::route($p, 1, $TAN, [14.5 + $k / 100, 121.0], 'recommended');
$before = Routing::$outboundCalls;
unavailable(fn() => Routing::route($p, 1, $TAN, [14.6, 121.0], 'recommended'), 'daily_limit', 'daily guard: 4th new route of the day refused (limit 3)');
ok(Routing::$outboundCalls === $before, '  no call was made after the guard');
ok(isset(Routing::route($p, 1, $TAN, [14.51, 121.0], 'recommended')['distance_km']), 'cached answers still work after the daily guard');
ok((int)one($p, "SELECT calls FROM map_api_usage WHERE usage_date=CURRENT_DATE() AND service='route'")['calls'] === 4, 'daily usage counted per service (map_api_usage)');
$p->exec('DELETE FROM map_api_usage'); $p->exec('DELETE FROM map_user_usage');
$cfg(['MAP_USER_PER_MINUTE' => '2']);
Routing::route($p, $users['operations_head']['id'], $TAN, [14.7, 121.0], 'recommended');
Routing::route($p, $users['operations_head']['id'], $TAN, [14.71, 121.0], 'recommended');
unavailable(fn() => Routing::route($p, $users['operations_head']['id'], $TAN, [14.72, 121.0], 'recommended'), 'user_limit', 'per-user limit: 3rd call in the same minute refused (limit 2)');
ok(isset(Routing::route($p, $users['admin']['id'], $TAN, [14.73, 121.0], 'recommended')['distance_km']), 'another user is not blocked by that limit');
$cfg(); $p->exec('DELETE FROM map_user_usage');

// ---------------------------------------------------------------------
section('F. Address search and reverse');
$REQ = [];
$s = Routing::search($p, 1, '  Tanauan   City ');
ok(str_starts_with($REQ[0]['url'] ?? '', 'https://api.heigit.org/pelias/v1/search?') && str_contains($REQ[0]['url'], 'text=Tanauan%20City') && str_contains($REQ[0]['url'], 'boundary.country=PH')
   && !str_contains($REQ[0]['url'], KEY), 'search: ORS geocoding, Philippines only, key not in the URL', $REQ[0]['url'] ?? '');
ok(count($s) === 2 && $s[0]['name'] === 'Tanauan City, Batangas, Philippines' && !in_array('Tokyo, Japan', array_column($s, 'name'), true), 'results outside the Philippines are dropped');
$before = Routing::$outboundCalls; Routing::search($p, 1, 'tanauan city');
ok(Routing::$outboundCalls === $before, 'same search (any case / spacing) answered from the cache');
fails(fn() => Routing::search($p, 1, 'ab'), 'search shorter than 3 letters refused');
fails(fn() => Routing::search($p, 1, str_repeat('x', 151)), 'search longer than 150 refused');
$REQ = [];
ok(Routing::reverse($p, 1, 14.08631, 121.14971) === 'Poblacion, Tanauan City' && str_contains($REQ[0]['url'] ?? '', '/pelias/v1/reverse?point.lat=14.0863&point.lon=121.1497'), 'reverse: address of a point (rounded to 4 decimals)');
$before = Routing::$outboundCalls; Routing::reverse($p, 1, 14.08634, 121.14968);
ok(Routing::$outboundCalls === $before, 'nearby click shares the reverse cache');

// ---------------------------------------------------------------------
section('G. Maps module (what the screen calls)');
foreach (array_keys(ROLE_LABELS) as $role) {
    $allowed = in_array($role, Maps::ROLES, true);
    if ($allowed) ok(isset(Maps::action($U($role), 'route', ['from_lat' => $TAN[0], 'from_lng' => $TAN[1], 'to_lat' => $MAL[0], 'to_lng' => $MAL[1]])['available']), "$role can use Maps.route");
    else fails(fn() => Maps::action($U($role), 'route', ['from_lat' => $TAN[0], 'from_lng' => $TAN[1], 'to_lat' => $MAL[0], 'to_lng' => $MAL[1]]), "$role cannot use the map service", 403);
}
$m = Maps::action($U('operations_head'), 'route', ['from_lat' => $TAN[0], 'from_lng' => $TAN[1], 'to_lat' => $MAL[0], 'to_lng' => $MAL[1], 'option' => 'avoid_tolls']);
ok($m['available'] === true && $m['option'] === 'avoid_tolls' && !array_key_exists('cached', $m) && !str_contains(json_encode($m), KEY), 'Maps.route: km, option, no key in the answer');
$NEXT = [503, 'down'];
$m = Maps::action($U('operations_head'), 'route', ['from_lat' => $TAN[0], 'from_lng' => $TAN[1], 'to_lat' => 14.81, 'to_lng' => 121.0]);
ok($m['available'] === false && $m['message'] !== '' && $m['straight_km'] > 0, 'service down -> available = false with a message (screen keeps manual km)');
ok(count(Maps::action($U('container_wingvan_dispatcher'), 'geocode', ['q' => 'Malvar'])['results']) >= 1, 'Maps.geocode works for a dispatcher (trip form search)');
$NEXT = [403, '{}'];
fails(fn() => Maps::action($U('operations_head'), 'geocode', ['q' => 'Lipa City']), 'geocode refused by ORS -> clear 503 message', 503, 'Type the km');
$NEXT = [0, ''];
$rv = Maps::action($U('operations_head'), 'reverse', ['lat' => '14.5', 'lng' => '121.2']);
ok($rv['address'] === '' && $rv['message'] !== '', 'reverse unavailable -> empty address with a message (no error)');
fails(fn() => Maps::action($U('operations_head'), 'teleport', []), 'unknown map action refused', 404);

// ---------------------------------------------------------------------
section('H. Client Routes: server re-verification and manual override');
$cid = insert($p, 'clients', ['company_name' => 'MAP CLIENT CORP', 'status' => 'Active', 'created_by' => $users['operations_head']['id']]);
$ops = $U('operations_head');
$base = fn(array $o = []) => $o + ['client_id' => $cid, 'truck_type' => 'Wing Van', 'origin' => 'Tanauan', 'destination' => 'Malvar',
    'origin_lat' => (string)$TAN[0], 'origin_lng' => (string)$TAN[1], 'destination_lat' => (string)$MAL[0], 'destination_lng' => (string)$MAL[1]];
$comp = Routing::route($p, $ops['id'], $TAN, $MAL, 'recommended')['distance_km'];
// audit_event_changes is NOT in this list: since Activity Log #5H-2, route actions record their changes there on purpose.
$checks = fn() => array_map(fn($t) => $p->query("CHECKSUM TABLE $t")->fetch()['Checksum'], ['trips', 'billings', 'client_rates', 'fund_requests', 'disbursements']);
$sum = $checks();

$r1 = Routes::action($ops, 'request', $base(['route_option' => 'recommended', 'distance_km' => number_format($comp, 2, '.', '')]));
$row = one($p, 'SELECT * FROM client_routes WHERE id=?', [$r1['route_id']]);
ok($row['distance_source'] === 'Map' && (float)$row['distance_computed_km'] === $comp && $row['route_option'] === 'recommended' && $row['distance_override_reason'] === null
   && $row['distance_provider'] === 'openrouteservice/driving-hgv' && $row['route_geometry'] !== null && $row['distance_computed_at'] !== null, 'km = computed -> saved as Map with computed km, option, provider, line');
ok(!isset($r1['warning']), 'no warning when the km equals the computed km');
$p->exec("DELETE FROM client_route_events WHERE route_id={$r1['route_id']}"); $p->exec("DELETE FROM client_routes WHERE id={$r1['route_id']}");

fails(fn() => Routes::action($ops, 'request', $base(['route_option' => 'recommended', 'distance_km' => number_format($comp + 1, 2, '.', '')])),
    'km different from computed WITHOUT a reason is refused', 400, 'Give the reason');
$r2 = Routes::action($ops, 'request', $base(['route_option' => 'recommended', 'distance_km' => number_format($comp + 0.5, 2, '.', ''), 'distance_override_reason' => 'Client contract uses 7.30 km']));
$row = one($p, 'SELECT * FROM client_routes WHERE id=?', [$r2['route_id']]);
ok($row['distance_source'] === 'Map (edited)' && (float)$row['distance_km'] === round($comp + 0.5, 2) && (float)$row['distance_computed_km'] === $comp
   && $row['distance_override_reason'] === 'Client contract uses 7.30 km', 'with a reason -> Map (edited); final km, computed km and reason all kept');
ok(!isset($r2['warning']) || !str_contains($r2['warning'], '15%'), 'difference of 15% or less -> no 15% warning', $r2['warning'] ?? '');
$ev = one($p, "SELECT notes FROM client_route_events WHERE route_id=? AND event_type='route requested'", [$r2['route_id']]);
ok(str_contains($ev['notes'], 'Map (edited)') && str_contains($ev['notes'], 'computed') && str_contains($ev['notes'], 'Client contract'), 'route history shows source, computed km and the reason');
$p->exec("DELETE FROM client_route_events WHERE route_id={$r2['route_id']}"); $p->exec("DELETE FROM client_routes WHERE id={$r2['route_id']}");

$r3 = Routes::action($ops, 'request', $base(['route_option' => 'recommended', 'distance_km' => number_format($comp * 1.5, 2, '.', ''), 'distance_override_reason' => 'Detour for truck ban']));
ok(isset($r3['warning']) && str_contains($r3['warning'], '15%'), 'difference above 15% -> warning returned', $r3['warning'] ?? '');
$p->exec("DELETE FROM client_route_events WHERE route_id={$r3['route_id']}"); $p->exec("DELETE FROM client_routes WHERE id={$r3['route_id']}");

fails(fn() => Routes::action($ops, 'request', $base(['route_option' => 'recommended', 'distance_km' => number_format($comp + 0.01, 2, '.', '')])),
    'smallest possible change (0.01 km) already needs a reason', 400, 'Give the reason');
fails(fn() => Routes::action($ops, 'request', $base(['route_option' => 'recommended', 'distance_km' => number_format($comp, 3, '.', '') . '1'])), 'km with more than 2 decimals refused');
$before = Routing::$outboundCalls;
$r5 = Routes::action($ops, 'request', $base(['distance_km' => '9.99', 'distance_source' => 'Map', 'distance_computed_km' => '9.99']));
$row = one($p, 'SELECT * FROM client_routes WHERE id=?', [$r5['route_id']]);
ok($row['distance_source'] === 'Manual' && $row['distance_computed_km'] === null && Routing::$outboundCalls === $before, 'no route option -> Manual, no map call; values sent by the browser are ignored');
$p->exec("DELETE FROM client_route_events WHERE route_id={$r5['route_id']}"); $p->exec("DELETE FROM client_routes WHERE id={$r5['route_id']}");

$r6 = Routes::action($ops, 'request', ['client_id' => $cid, 'truck_type' => 'Wing Van', 'origin' => 'Tanauan', 'destination' => 'Malvar', 'distance_km' => '7', 'route_option' => 'recommended']);
ok(one($p, 'SELECT distance_source FROM client_routes WHERE id=?', [$r6['route_id']])['distance_source'] === 'Manual', 'route option but no pins -> Manual (typed km)');
$p->exec("DELETE FROM client_route_events WHERE route_id={$r6['route_id']}"); $p->exec("DELETE FROM client_routes WHERE id={$r6['route_id']}");

$NEXT = [503, 'down'];
$r7 = Routes::action($ops, 'request', $base(['origin' => 'Tanauan A', 'destination_lat' => '14.0333', 'route_option' => 'general_roads', 'distance_km' => '8.10']));
$row = one($p, 'SELECT * FROM client_routes WHERE id=?', [$r7['route_id']]);
ok($row['distance_source'] === 'Manual' && $row['distance_km'] === '8.10' && $row['route_option'] === null && isset($r7['warning']) && str_contains($r7['warning'], 'Manual'),
   'map service down at save -> typed km saved as Manual with a note (safe fallback)');
fails(fn() => Routes::action($ops, 'request', $base(['origin' => 'Tanauan B', 'route_option' => 'teleport', 'distance_km' => '8'])), 'unknown route option refused on save');
fails(fn() => Routes::action($ops, 'request', $base(['origin' => 'Tanauan C', 'route_option' => 'recommended', 'distance_km' => '99', 'distance_override_reason' => str_repeat('r', 301)])), 'reason longer than 300 refused');
$det = Routes::action($U('admin'), 'details', ['route_id' => $r7['route_id']]);
ok(array_key_exists('distance_computed_km', $det['route']) && array_key_exists('route_geometry', $det['route']) && array_key_exists('distance_override_reason', $det['route']), 'route details include the distance fields');
$page = Routes::read($ops, 'routes', []);
ok($page['routing'] === true && count($page['routeOptions']) === 3 && $page['defaultRouteOption'] === 'recommended' && $page['overrideWarningRatio'] === 0.15, 'page data: routing on, 3 options, default Recommended, 15% warning');
ok($checks() === $sum, 'trips, billings, client_rates, fund requests and disbursements were not changed by route requests');
try { $p->exec("UPDATE client_routes SET distance_source='GPS' WHERE id={$r7['route_id']}"); ok(false, 'database refuses an unknown distance_source'); }
catch (PDOException $e) { ok(($e->errorInfo[1] ?? 0) === 4025, 'database refuses an unknown distance_source (CHECK)'); }
try { $p->exec("UPDATE client_routes SET route_option='fly' WHERE id={$r7['route_id']}"); ok(false, 'database refuses an unknown route option'); }
catch (PDOException $e) { ok(($e->errorInfo[1] ?? 0) === 4025, 'database refuses an unknown route option (CHECK)'); }

// ---------------------------------------------------------------------
section('I. Real curl code against the local mock server');
$port = 18000 + random_int(0, 999);
$mockLog = tempnam(sys_get_temp_dir(), 'orsreq');
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/_mock_ors.php'], [1 => ['file', (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'), 'w'], 2 => ['file', (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'), 'w']], $pp, null, ['MOCK_ORS_LOG' => $mockLog] + getenv());
usleep(700000);
Routing::useTransport(null);
$cfg(['ORS_BASE_URL' => "http://127.0.0.1:$port", 'ORS_API_KEY' => 'mock-ors-key']);
try {
    $c = Routing::route($p, 1, [14.0863, 121.1497], [14.3122, 121.1114], 'general_roads');
    ok($c['distance_km'] > 0 && $c['geometry'] !== '', 'curl: route computed through HTTP (' . $c['distance_km'] . ' km)');
    $lines = array_map(fn($l) => json_decode($l, true), array_filter(explode("\n", (string)file_get_contents($mockLog))));
    $last = end($lines);
    ok($last['auth_ok'] === true && $last['method'] === 'POST' && str_contains($last['body'], '"avoid_features":["highways","tollways"]') && str_starts_with($last['ua'], 'MulawinFleetOps'), 'curl: key header, POST body and User-Agent received by the server');
    ok(Routing::search($p, 1, 'Santa Rosa Laguna')[0]['name'] === 'Santa Rosa, Laguna, Philippines', 'curl: address search through HTTP');
    unavailable(fn() => Routing::route($p, 1, [14.111, 121.1497], [14.3, 121.11], 'recommended'), 'not_near_road', 'curl: ORS error 2010 mapped');
    unavailable(fn() => Routing::route($p, 1, [14.222, 121.1497], [14.3, 121.11], 'recommended'), 'rate_limited', 'curl: HTTP 429 mapped');
    $cfg(['ORS_BASE_URL' => "http://127.0.0.1:$port", 'ORS_API_KEY' => 'wrong-key']);
    unavailable(fn() => Routing::route($p, 1, [14.0863, 121.1497], [14.4, 121.11], 'recommended'), 'refused', 'curl: wrong key -> 403 refused');
    $cfg(['ORS_BASE_URL' => 'http://127.0.0.1:1', 'ORS_API_KEY' => 'mock-ors-key']);
    unavailable(fn() => Routing::route($p, 1, [14.0863, 121.1497], [14.45, 121.11], 'recommended'), 'connection_refused', 'curl: nothing listening -> connection refused');
} finally { proc_terminate($proc); proc_close($proc); @unlink($mockLog); }
$cfg(); Routing::useTransport($fake);

// ---------------------------------------------------------------------
section('J. Migration 04: partially failed import, detection and recovery');
$baseSchema = __DIR__ . '/_base_schema.sql'; $baseExt = __DIR__ . '/_base_extension.sql';
$MIG = '2026-11-06_04_maps_routing';
if (!is_file($baseSchema) || !is_file($baseExt)) {
    echo "  NOTE  git history of $MIG not available (not committed yet, or no git): partial-import check skipped. The full migration chain is checked by tests/run_route_tests.php.\n";
} else {
    $pa = test_conn($PARTIAL);
    test_import($pa, (string)file_get_contents($baseSchema));
    test_import($pa, (string)file_get_contents($baseExt));
    // (the base schema = the commit before this migration was added, so every earlier migration is already in it)
    $parts = array_values(array_filter(array_map('trim', preg_split('/;\s*\n/', (string)preg_replace('/^\s*--.*$/m', '', test_sql("database/migrations/$MIG.sql")))), fn($s) => $s !== ''));
    $pa->exec($parts[0]); $pa->exec($parts[1]);   // tracker + map_cache only
    $detect = "SELECT (SELECT COUNT(*) FROM schema_migrations WHERE id='$MIG') AS tracked,
        (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('map_cache','map_api_usage','map_user_usage')) AS tables_found,
        (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='client_routes' AND column_name='route_option') AS columns_found";
    $st = $pa->query($detect)->fetch();
    ok((int)$st['tracked'] === 1 && (int)$st['tables_found'] === 1 && (int)$st['columns_found'] === 0, 'detection query shows the partial state (1 of 3 tables, columns missing)', json_encode($st));
    // Recovery steps from docs/CLAUDE_05_OSM_ROUTING.md (MariaDB)
    foreach (['DROP TABLE IF EXISTS map_user_usage', 'DROP TABLE IF EXISTS map_api_usage', 'DROP TABLE IF EXISTS map_cache',
              'ALTER TABLE client_routes DROP CONSTRAINT IF EXISTS chk_route_option, DROP CONSTRAINT IF EXISTS chk_route_computed_km, DROP CONSTRAINT IF EXISTS chk_route_distance_source',
              'ALTER TABLE client_routes DROP COLUMN IF EXISTS route_option, DROP COLUMN IF EXISTS distance_computed_km, DROP COLUMN IF EXISTS distance_provider, DROP COLUMN IF EXISTS distance_computed_at, DROP COLUMN IF EXISTS distance_override_reason, DROP COLUMN IF EXISTS route_geometry',
              "DELETE FROM schema_migrations WHERE id='$MIG'"] as $sql) $pa->exec($sql);
    test_import($pa, test_sql("database/migrations/$MIG.sql"));
    foreach (test_migrations_from($MIG) as $m) if (strcmp($m, $MIG) > 0) test_import($pa, test_sql("database/migrations/$m.sql"));
    $st = $pa->query($detect)->fetch();
    ok((int)$st['tracked'] === 1 && (int)$st['tables_found'] === 3 && (int)$st['columns_found'] === 1, 'after recovery: tracked, 3 of 3 tables, columns present', json_encode($st));
    ok(test_fingerprint($pa, $PARTIAL) === test_fingerprint($p, $FRESH), 'recovered database is identical to a fresh install');
}

// ---------------------------------------------------------------------
section('K. Screen helpers');
$mapJs = test_sql('public/assets/js/map.js'); $coreJs = test_sql('public/assets/js/core.js'); $index = test_sql('public/index.php');
ok(!str_contains($coreJs, 'UI.Map = {') && !str_contains($coreJs, 'UI.mapPicker = ') && str_contains($mapJs, 'UI.mapPicker = ') && str_contains($mapJs, 'UI.routeMap = '), 'map code lives only in map.js (moved out of core.js)');
$pos = fn(string $f) => strpos($index, '<script src="assets/js/' . $f);
ok($pos('map.js') !== false && $pos('map.js') > $pos('core.js') && $pos('map.js') < $pos('modules/'), 'map.js is loaded after core.js and before the page scripts');
// Hosts: any case. Header: the exact "Authorization:" header name (a code comment about "authorization" is not a call).
ok(!preg_match('/maptiler|api\.heigit\.org|api\.openrouteservice\.org/i', $mapJs . $coreJs) && !preg_match('/Authorization\s*[:\'"]/', $mapJs . $coreJs)
   && !str_contains($mapJs . $coreJs, 'ORS_API_KEY=') && str_contains($mapJs, "App.api('Maps.route'"), 'no MapTiler, no ORS host, no Authorization header in the browser code (all map calls go through App.api to our server)');
$node = trim((string)@shell_exec('command -v node 2>/dev/null'));
if ($node === '' || !preg_match('/^\s*decode\(str\) \{.*?^\s*\},\s*$/ms', $mapJs, $mm)) {
    echo "  NOTE  Node.js not found (or decode() not found): polyline decoding check skipped\n";
} else {
    $tmpJs = tempnam(sys_get_temp_dir(), 'dec') . '.js';
    file_put_contents($tmpJs, 'const M = { ' . rtrim(trim($mm[0]), ',') . " };\nprocess.stdout.write(JSON.stringify(M.decode('_p~iF~ps|U_ulLnnqC_mqNvxq`@')));\n");
    $dec = json_decode((string)shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($tmpJs)), true); @unlink($tmpJs);
    ok($dec === [[38.5, -120.2], [40.7, -120.95], [43.252, -126.453]], 'UI.Map.decode() decodes the reference polyline correctly', json_encode($dec));
}

echo "\n" . str_repeat('=', 60) . "\nRESULT: $PASS passed, $FAIL failed\n";
if ($FAILED) echo "Failed:\n - " . implode("\n - ", $FAILED) . "\n";
@unlink($LOG);
exit($FAIL ? 1 : 0);
