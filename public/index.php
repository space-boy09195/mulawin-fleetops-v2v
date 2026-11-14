<?php
/*
 * =====================================================================
 *  public/index.php  —  the only page the browser opens
 * =====================================================================
 *  1. Without ?api=  -> sends the HTML shell (login / app screen).
 *  2. With ?api=...  -> JSON API used by the screen:
 *       whoami, login (username/email + password), logout, me
 *       data&page=xyz                      data for one page
 *       Module.action  (POST JSON)         save something
 *       document_upload / document_download / backup_download
 *  All POST requests need the CSRF token.
 * =====================================================================
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/backend/bootstrap.php';
require_once dirname(__DIR__) . '/backend/Actions.php';
require_once dirname(__DIR__) . '/backend/Data.php';

if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
core('AccountSecurity');
const LOCKED_MSG = 'This account is locked for 15 minutes because of too many wrong passwords. Try again later.';

function checkCsrf(?string $given): void {
    must(hash_equals((string)($_SESSION['csrf'] ?? ''), (string)$given), 'Security token expired. Please reload the page.', 403);
}

if (isset($_GET['api'])) {
    $api = (string)$_GET['api'];
    try {
        // Security S1: login, logout and the password-change workflow remain reachable;
        // a user who must still replace a temporary password cannot call document, backup, map, or other APIs directly.
        if (!in_array($api, ['whoami','login','logout','me','Admin.password_change_force','Admin.password_change_request','Admin.password_change_confirm'], true)
            && !empty($_SESSION['uid'])) {
            $setupUser = currentUser();
            core('SecurityPasswordChange');
            must(!SecurityPasswordChange::required((int)$setupUser['id']), 'Complete your first-login password setup first.', 403);
        }
        switch ($api) {
            case 'whoami':
                jsonOut(['ok' => true, 'signedIn' => (bool)($_SESSION['uid'] ?? false), 'csrf' => $_SESSION['csrf']]);
            case 'login':
                // Username (or email) + password. A correct password signs the user in; there is no second step.
                must($_SERVER['REQUEST_METHOD'] === 'POST', 'POST required.', 405);
                $d = inputData();
                checkCsrf($d['csrf'] ?? '');
                $login = field($d, 'username', 190); $password = field($d, 'password', 255);
                $now = time();
                $fails = array_values(array_filter($_SESSION['login_failures'] ?? [], fn($t) => $now - (int)$t < 900));
                must(count($fails) < 5, 'Too many wrong tries. Please wait 15 minutes.', 429);
                $row = one(db(), 'SELECT * FROM users WHERE (username=? OR email=?) AND active=1 LIMIT 1', [$login, $login]);
                // Account lock (from any computer): 5 wrong passwords = locked for 15 minutes.
                must(!$row || !AccountSecurity::isLocked($row), LOCKED_MSG, 429);
                if (!$row || !password_verify($password, (string)$row['password_hash'])) {
                    $fails[] = $now; $_SESSION['login_failures'] = $fails;
                    if ($row && AccountSecurity::failure($row)) fail(LOCKED_MSG, 429);
                    fail('Wrong username or password.' . (count($fails) >= 3 ? ' (' . (5 - count($fails)) . ' tries left)' : ''), 401);
                }
                must(in_array($row['role'], allowedRoles(), true), 'Account role is not allowed.', 403);
                AccountSecurity::success((int)$row['id']);
                session_regenerate_id(true);
                $_SESSION = ['csrf' => bin2hex(random_bytes(24)), 'uid' => (int)$row['id'], 'last_seen' => time(), 'login_at' => time()];
                run(db(), 'UPDATE users SET last_login_at=NOW() WHERE id=?', [$row['id']]);
                audit(db(), (int)$row['id'], 'Access', 'user ' . $row['id'], 'signed in', 'password');
                jsonOut(['ok' => true]);
            case 'logout':
                must($_SERVER['REQUEST_METHOD'] === 'POST', 'POST required.', 405);
                checkCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
                session_unset(); session_destroy();
                jsonOut(['ok' => true]);
            case 'me':
                jsonOut(Data::me());
            case 'data':
                jsonOut(['ok' => true, 'data' => Actions::pageData((string)($_GET['page'] ?? ''), $_GET)]);
            case 'document_upload':
                must($_SERVER['REQUEST_METHOD'] === 'POST', 'POST required.', 405);
                checkCsrf($_POST['csrf'] ?? '');
                $u = currentUser();
                Actions::load('Documents');
                jsonOut(['ok' => true, 'data' => Documents::upload($u)]);
            case 'document_download':
                $u = currentUser();
                $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
                must($id !== false && $id > 0, 'Invalid document.');
                Actions::load('Documents');
                Documents::download($u, (int)$id, !empty($_GET['inline']));
            case 'signature':
                // S4A: signatures are private; require ownership or an authorized
                // business-record signatory relationship (server-side, fail closed).
                $viewer = currentUser();
                $uid = filter_var($_GET['user'] ?? null, FILTER_VALIDATE_INT);
                must($uid !== false && $uid > 0, 'Invalid user.');
                core('SignatureAccess');
                $recordType = (string)($_GET['record_type'] ?? '');
                $recordId = filter_var($_GET['record_id'] ?? null, FILTER_VALIDATE_INT);
                must(SignatureAccess::canView($viewer, (int)$uid, $recordType, ($recordId !== false && $recordId !== null) ? (int)$recordId : 0), 'You cannot view this signature.', 403);
                $doc = one(db(), "SELECT d.storage_name, d.mime_type FROM users u JOIN documents d ON d.id=u.signature_document_id WHERE u.id=? AND d.entity_type='user'", [(int)$uid]);
                if (!$doc) { http_response_code(404); exit; }
                $file = dirname(__DIR__) . '/storage/private/' . basename((string)$doc['storage_name']);
                if (!is_file($file)) { http_response_code(404); exit; }
                header('Content-Type: ' . (in_array($doc['mime_type'], ['image/png', 'image/jpeg'], true) ? $doc['mime_type'] : 'application/octet-stream'));
                header('Cache-Control: private, no-store'); header('Content-Disposition: inline; filename="signature"');
                header('Content-Length: ' . filesize($file)); readfile($file); exit;
            case 'backup_download':
                $u = currentUser(); requireRole($u, 'admin');
                $name = basename((string)($_GET['file'] ?? ''));
                must((bool)preg_match('/^mulawin_backup_\d{8}_\d{6}\.sql$/', $name), 'Invalid backup file.');
                $file = dirname(__DIR__) . '/storage/backups/' . $name;
                must(is_file($file), 'Backup not found.', 404);
                header('Content-Type: application/sql'); header('Content-Disposition: attachment; filename="' . $name . '"');
                header('Content-Length: ' . filesize($file)); readfile($file); exit;
            default:
                must($_SERVER['REQUEST_METHOD'] === 'POST', 'POST required.', 405);
                checkCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
                jsonOut(['ok' => true, 'data' => Actions::run($api, inputData())]);
        }
    } catch (BusinessRuleException $e) {
        fail($e->getMessage(), $e->httpStatus);
    } catch (ModuleUnavailable $e) {
        fail($e->getMessage(), 503);
    } catch (PDOException $e) {
        error_log('Database error: ' . $e->getMessage());
        $msg = str_contains($e->getMessage(), 'Duplicate entry') ? 'This record already exists (duplicate).' : 'Database problem. Check the setup and the server error log.';
        fail($msg, 500);
    } catch (Throwable $e) {
        error_log('Unexpected error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        fail('Unexpected error. See the server error log.', 500);
    }
}

// ---------------- HTML shell ----------------
// Each module script is separate: if one file fails, the other pages still work.
// Screen files naka-grupo ayon sa department (public/assets/js/modules/<department>/<file>.js).
$jsModules = ['general/dashboard', 'operations/trips', 'operations/trip-planner', 'operations/fleet', 'operations/clients', 'operations/routes', 'maintenance/mechanics', 'maintenance/maintenance', 'maintenance/history',
              'maintenance/purchasing', 'accounting/funds', 'accounting/billing', 'accounting/finance', 'general/documents', 'general/reports',
              'general/notifications', 'administration/admin', 'administration/head-management', 'administration/audit', 'administration/oversight', 'operations/ops-head', 'operations/dispatcher-monitoring', 'operations/trip-approvals', 'operations/dispatcher', 'operations/ops-reports', 'operations/dispatcher-turnover'];
$v = static fn(string $f): string => is_file(__DIR__ . '/' . $f) ? (string)filemtime(__DIR__ . '/' . $f) : '1';
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <link rel="icon" type="image/png" href="assets/img/favicon.png">
  <title>Mulawin FleetOps — R.P. Mulawin Fleet Management System</title>
  <link rel="stylesheet" href="assets/vendor/bootstrap/css/bootstrap.min.css">
  <link rel="stylesheet" href="assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="assets/vendor/leaflet/leaflet.css">
  <link rel="stylesheet" href="assets/css/layout.css?v=<?= $v('assets/css/layout.css') ?>">
  <link rel="stylesheet" href="assets/css/login.css?v=<?= $v('assets/css/login.css') ?>">
  <link rel="stylesheet" href="assets/css/app.css?v=<?= $v('assets/css/app.css') ?>">
  <link rel="stylesheet" href="assets/css/workspace.css?v=<?= $v('assets/css/workspace.css') ?>">
  <link rel="stylesheet" href="assets/css/ops-head.css?v=<?= $v('assets/css/ops-head.css') ?>">
  <link rel="stylesheet" href="assets/css/records.css?v=<?= $v('assets/css/records.css') ?>">
  <link rel="stylesheet" href="assets/css/dispatcher.css?v=<?= $v('assets/css/dispatcher.css') ?>">
</head>
<body>
  <div id="app"><div class="text-center p-5 text-muted">Loading Mulawin FleetOps…</div></div>
  <div class="toast-zone" id="toastZone" aria-live="polite"></div>
  <script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script src="assets/vendor/chartjs/chart.umd.min.js"></script>
  <script src="assets/vendor/leaflet/leaflet.js"></script>
  <script src="assets/js/core.js?v=<?= $v('assets/js/core.js') ?>"></script>
  <script src="assets/js/components/records.js?v=<?= $v('assets/js/components/records.js') ?>"></script>
  <script src="assets/js/components/forms.js?v=<?= $v('assets/js/components/forms.js') ?>"></script>
  <script src="assets/js/map.js?v=<?= $v('assets/js/map.js') ?>"></script>
  <script src="assets/js/attendance.js?v=<?= $v('assets/js/attendance.js') ?>"></script>
<?php foreach ($jsModules as $m): ?>
  <script src="assets/js/modules/<?= $m ?>.js?v=<?= $v('assets/js/modules/' . $m . '.js') ?>"></script>
<?php endforeach; ?>
  <script>App.start();</script>
</body>
</html>
