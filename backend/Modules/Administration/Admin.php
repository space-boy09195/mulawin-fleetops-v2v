<?php
/*
 * =====================================================================
 *  backend/Modules/Administration/Admin.php  —  HEAD MANAGEMENT: ADMINISTRATION
 * =====================================================================
 *  Objective 4.1  user accounts and role-based access (activate / deactivate)
 *  Objective 4.2  activity log: creation, modification, approval, processing
 *  PDF #11 V.65   backup of system data
 *  Also: system settings and fund request categories (Admin only),
 *        own password, Admin recovery code, e-signature for PO approvals.
 *  Audit can READ the activity log; every save here needs the Admin role.
 * =====================================================================
 */
declare(strict_types=1);
core('Mailer');
core('Settings');
core('AccountSecurity');
core('Files');
core('Notify');
core('CrewAttendance');

final class Admin {
    public static function read(array $u, string $page, array $q): array {
        $p = db();
        if ($page === 'fuel-adjustment') return Settings::pricingRead($u);
        if ($page === 'notifications') return self::notificationsPage($u, $q);
        requireRole($u, 'admin', 'audit');
        if (str_starts_with($page, 'audit_')) return self::auditPage($u, $p, $page, $q);
        $from = self::d((string)($q['from'] ?? '')) ?? date('Y-m-d', strtotime('-30 days'));
        $to = self::d((string)($q['to'] ?? '')) ?? date('Y-m-d');
        $w = 'WHERE DATE(a.created_at) BETWEEN ? AND ?'; $args = [$from, $to];
        if (!empty($q['area'])) { $w .= ' AND a.area=?'; $args[] = (string)$q['area']; }
        if (!empty($q['actor_id']) && ctype_digit((string)$q['actor_id'])) { $w .= ' AND a.actor_id=?'; $args[] = (int)$q['actor_id']; }
        $data = readSections([
            'events' => fn() => many($p, "SELECT a.*, COALESCE(us.full_name, 'System') AS actor, us.role, us.email AS actor_email, ac.changes_json
                FROM audit_events a LEFT JOIN users us ON us.id=a.actor_id
                LEFT JOIN audit_event_changes ac ON ac.audit_event_id=a.id
                $w ORDER BY a.id DESC LIMIT 3000", $args),
            'areas' => fn() => array_column(many($p, 'SELECT DISTINCT area FROM audit_events ORDER BY area'), 'area'),
            'actors' => fn() => many($p, 'SELECT id, full_name FROM users ORDER BY full_name'),
        ]);
        $data['range'] = ['from' => $from, 'to' => $to];
        if (hasRole($u, 'admin')) {
            $data = mergeSections($data, [
                'users' => fn() => many($p, 'SELECT id, username, email, full_name, role, shift, active, must_change_password, last_login_at, created_at, (signature_document_id IS NOT NULL) AS has_signature FROM users ORDER BY active DESC, role, shift, full_name'),
                'fundCategories' => fn() => many($p, 'SELECT * FROM fund_categories ORDER BY sort_order, name'),
            ]);
            $data['settings'] = Settings::all();
            $dir = dirname(__DIR__, 3) . '/storage/backups';
            $data['backups'] = array_values(array_map(fn($f) => ['name' => basename($f), 'size' => filesize($f), 'date' => date('Y-m-d H:i', filemtime($f))],
                array_reverse(glob($dir . '/mulawin_backup_*.sql') ?: [])));
        }
        $data['roles'] = ROLE_LABELS;
        $data['can'] = ['admin' => hasRole($u, 'admin')];
        return $data;
    }
    private static function d(string $v): ?string { return ($v !== '' && DateTimeImmutable::createFromFormat('!Y-m-d', $v)) ? $v : null; }

    /**
     * AUDIT_WORKSPACE_V1 — read-only audit pages (Admin + Audit; Access already limits each page).
     * Sources: audit_events (actor, area, record_ref, action, details, time) and audit_event_changes
     * (field-level before/after, recorded only by auditWithChanges). Nothing here writes.
     * Action types are derived from the recorded action text with fixed keyword rules (shown in the UI).
     */
    private const AUDIT_TYPES = [
        'Approval' => '(approved|returned to service|verified)', 'Rejection' => '(rejected|failed)', 'Payment' => '(payment|paid|released|disburse|liquidat)',
        'Cancellation' => '(cancel|withdraw|closed|deactivat|removed)', 'Creation' => '(created|added|submitted|reported|recorded|request)',
        'Change' => '(changed|updated|adjusted|assigned|set|reset|saved|processed|received|activated|completed)',
        'Sign-in' => '(signed in|signed out|login|logout)', 'Attendance' => '(time in|time out|attendance)', 'Document' => '(document|upload)',
    ];
    private static function auditType(string $action): string {
        foreach (['Sign-in', 'Attendance', 'Document'] as $first) if (preg_match('/' . self::AUDIT_TYPES[$first] . '/i', $action)) return $first;
        foreach (self::AUDIT_TYPES as $type => $re) if (preg_match('/' . $re . '/i', $action)) return $type;
        return 'Other';
    }
    private static function auditPage(array $u, PDO $p, string $page, array $q): array {
        $maxDays = 366;
        $to = self::d((string)($q['to'] ?? '')) ?? date('Y-m-d');
        $from = self::d((string)($q['from'] ?? '')) ?? date('Y-m-d', strtotime($to . ($page === 'audit_overview' ? ' -6 days' : ' -29 days')));
        if ($from > $to) [$from, $to] = [$to, $from];
        if ((new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days >= $maxDays) $from = date('Y-m-d', strtotime($to . ' -' . ($maxDays - 1) . ' days'));
        $w = 'WHERE a.created_at >= ? AND a.created_at < ? + INTERVAL 1 DAY'; $args = [$from, $to];
        if ($page !== 'audit_overview') {
            if (!empty($q['area'])) { $w .= ' AND a.area=?'; $args[] = (string)$q['area']; }
            if (!empty($q['actor_id']) && ctype_digit((string)$q['actor_id'])) { $w .= ' AND a.actor_id=?'; $args[] = (int)$q['actor_id']; }
            if (!empty($q['record'])) { $w .= ' AND a.record_ref=?'; $args[] = cut((string)$q['record'], 70); }
        }
        $events = fn(int $limit) => array_map(function ($e) { $e['type'] = self::auditType((string)$e['action']); $e['has_changes'] = $e['changes_json'] !== null; return $e; },
            many($p, "SELECT a.id, a.area, a.record_ref, a.action, a.details, a.created_at, a.actor_id, COALESCE(us.full_name, 'System') AS actor, us.role, ac.changes_json
                FROM audit_events a LEFT JOIN users us ON us.id=a.actor_id LEFT JOIN audit_event_changes ac ON ac.audit_event_id=a.id $w ORDER BY a.id DESC LIMIT $limit", $args));
        $base = ['areas' => fn() => array_column(many($p, 'SELECT DISTINCT area FROM audit_events ORDER BY area'), 'area'),
                 'actors' => fn() => many($p, 'SELECT DISTINCT us.id, us.full_name, us.role FROM audit_events a JOIN users us ON us.id=a.actor_id ORDER BY us.full_name')];
        $data = match ($page) {
            'audit_overview' => readSections([
                'events' => fn() => $events(500),
                'totals' => fn() => one($p, "SELECT COUNT(*) AS all_time, MIN(created_at) AS first_at, COALESCE(SUM(created_at >= CURRENT_DATE()),0) AS today,
                        COALESCE(SUM(created_at >= CURRENT_DATE() - INTERVAL 29 DAY),0) AS last30 FROM audit_events"),
                'withChanges' => fn() => (int)one($p, 'SELECT COUNT(*) AS n FROM audit_event_changes')['n'],
            ]),
            'audit_logs' => readSections(['events' => fn() => $events(3000)] + $base),
            'audit_history' => readSections([
                'records' => fn() => many($p, "SELECT a.area, a.record_ref, COUNT(*) AS events, MIN(a.created_at) AS first_at, MAX(a.created_at) AS last_at,
                        SUBSTRING_INDEX(GROUP_CONCAT(a.action ORDER BY a.id DESC SEPARATOR '\n'), '\n', 1) AS last_action,
                        SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(us.full_name,'System') ORDER BY a.id DESC SEPARATOR '\n'), '\n', 1) AS last_actor
                    FROM audit_events a LEFT JOIN users us ON us.id=a.actor_id $w GROUP BY a.area, a.record_ref ORDER BY last_at DESC LIMIT 1000", $args),
                'timeline' => fn() => !empty($q['record']) ? $events(500) : [],
            ] + $base),
            'audit_reports' => readSections([
                'events' => fn() => $events(20000),
                'byMonth' => fn() => many($p, "SELECT DATE_FORMAT(a.created_at,'%Y-%m') AS m, a.area, COUNT(*) AS n FROM audit_events a $w GROUP BY m, a.area ORDER BY m, a.area", $args),
            ] + $base),
            default => throw new BusinessRuleException('Unknown page.', 404),
        };
        // Reports aggregate the event list server-side so only counts are sent (no per-event details).
        if ($page === 'audit_reports' && is_array($data['events'] ?? null)) {
            $agg = ['type' => [], 'user' => [], 'area' => []];
            foreach ($data['events'] as $e) {
                $agg['type'][$e['type']] = ($agg['type'][$e['type']] ?? 0) + 1;
                $k = $e['actor'] . '|' . ($e['role'] ?? '');
                $agg['user'][$k] = ($agg['user'][$k] ?? 0) + 1;
                $agg['area'][$e['area']]['n'] = ($agg['area'][$e['area']]['n'] ?? 0) + 1;
                $agg['area'][$e['area']]['changes'] = ($agg['area'][$e['area']]['changes'] ?? 0) + ($e['has_changes'] ? 1 : 0);
            }
            $data['summary'] = ['total' => count($data['events']), 'truncated' => count($data['events']) >= 20000,
                'byType' => $agg['type'], 'byUser' => array_map(fn($k, $n) => ['actor' => explode('|', $k)[0], 'role' => explode('|', $k)[1], 'n' => $n], array_keys($agg['user']), $agg['user']),
                'byArea' => array_map(fn($k, $v) => ['area' => $k] + $v, array_keys($agg['area']), $agg['area'])];
            unset($data['events']);
        }
        $data['range'] = ['from' => $from, 'to' => $to, 'max_days' => $maxDays];
        $data['types'] = array_merge(array_keys(self::AUDIT_TYPES), ['Other']);
        $data['roles'] = ROLE_LABELS;
        return $data;
    }

    public static function action(array $u, string $action, array $d): array {
        return match ($action) {
            'pricing_save' => Settings::pricingSave($u,$d),
            'user_save' => self::withRoleSlotLock(fn() => self::userSave($u, $d)),
            'user_toggle' => self::withRoleSlotLock(fn() => self::userToggle($u, $d)),
            'user_password' => self::userPassword($u, $d),
            'change_password' => throw new BusinessRuleException('Use the verified password change screen.', 409),
            'recovery_code_create' => throw new BusinessRuleException('Recovery code issuance is disabled; contact authorized IT for emergency recovery.', 404),
            'signature_upload' => self::signatureUpload($u, $d),
            'setting_save' => self::settingSave($u, $d),
            'fund_category_save' => self::fundCategorySave($u, $d),
            'backup_create' => self::backupCreate($u),
            'notifications_read' => self::notificationsRead($u, $d),
            default => throw new BusinessRuleException('Unknown admin action.', 404),
        };
    }

    /** Password policy. $confirm = the "type it again" field; checked here on the server, never stored. */
    public static function strongPassword(string $pw, ?string $confirm = null): void {
        must(strlen($pw) >= PASSWORD_MIN_LENGTH, 'Password must have at least ' . PASSWORD_MIN_LENGTH . ' characters.');
        must(strlen($pw) <= PASSWORD_MAX_BYTES, 'Password is too long (max ' . PASSWORD_MAX_BYTES . ' characters).');
        if ($confirm !== null) must(hash_equals($pw, $confirm), 'The password and the confirmation do not match.');
        must(preg_match('/[A-Za-z]/', $pw) && preg_match('/\d/', $pw), 'Password must have letters and numbers.');
    }

    /** Adjustment: one active account per role/dispatcher shift (role-slot lock).
     * Serialize account actions to prevent concurrent requests claiming the same vacant slot.
     * Existing duplicates are kept untouched until Admin manually deactivates extras.
     */
    private static function withRoleSlotLock(callable $work): mixed {
        $p = db();
        $lock = one($p, "SELECT GET_LOCK('mulawin_user_role_slots', 10) AS acquired");
        must((int)($lock['acquired'] ?? 0) === 1, 'User accounts are being updated. Please try again.', 409);
        try {
            return $work();
        } catch (PDOException $e) {
            if(in_array((int)($e->errorInfo[1]??0),[1205,1213],true))throw new BusinessRuleException('Concurrent account or turnover update. No account change was saved. Refresh and retry after the other transaction completes.',409);
            throw $e;
        } finally {
            try { one($p, "SELECT RELEASE_LOCK('mulawin_user_role_slots') AS released"); }
            catch (Throwable $ignored) { /* Connection shutdown releases locks too. */ }
        }
    }

    /** Active non-dispatcher roles have one slot; each dispatcher role has Day and Night slots. */
    private static function assertRoleShiftVacancy(PDO $p, string $role, ?string $shift, ?int $excludeId = null): void {
        if (roleIsDispatcher($role)) {
            must(in_array($shift, ['Day', 'Night'], true), 'Select a dispatcher shift.', 400);
            $occupied = one($p, 'SELECT id FROM users WHERE role=? AND shift=? AND active=1 AND id<>? LIMIT 1', [$role, $shift, $excludeId ?? 0]);
            must(!$occupied, 'This dispatcher role and shift already have an active account. Deactivate that account first.', 409);
        } else {
            $occupied = one($p, 'SELECT id FROM users WHERE role=? AND active=1 AND id<>? LIMIT 1', [$role, $excludeId ?? 0]);
            must(!$occupied, 'This role already has an active account. Deactivate that account first.', 409);
        }
    }

    // ---------------------------------------------------------------- users
    private static function emailField(array $d): string {
        $email = strtolower(field($d, 'email', 190));
        must((bool)filter_var($email, FILTER_VALIDATE_EMAIL), 'Enter a valid email address.');
        return $email;
    }

    /**
     * Admin creates or edits an account. A new account gets a random temporary password generated by the system
     * (never typed by the Admin). It is returned ONCE in this response; only its hash is stored, and it is never logged or emailed.
     */
    private static function userSave(array $u, array $d): array {
        requireRole($u, 'admin');
        $id = optId($d, 'id');
        $role = choiceField($d, 'role', allowedRoles());
        $shift = roleIsDispatcher($role) ? choiceField($d, 'shift', ['Day', 'Night']) : null;
        $name = field($d, 'full_name', 100);
        $email = self::emailField($d);
        return transaction(function (PDO $p) use ($u, $id, $role, $shift, $name, $email, $d) {
            must(!one($p, 'SELECT id FROM users WHERE email=? AND id<>?', [$email, $id ?? 0]), 'Another account already uses this email address.', 409);
            if ($id) {
                if ($id === $u['id']) must($role === 'admin', 'You cannot remove your own Admin role.');
                $oldUser = one($p, 'SELECT full_name, email, role, shift, active FROM users WHERE id=? FOR UPDATE', [$id]);
                must((bool)$oldUser, 'User not found.', 404);
                if($role!==$oldUser['role']||$shift!==$oldUser['shift']){
                    core('DispatcherTurnover');
                    DispatcherTurnover::guardAccountChange($p,$id,roleIsDispatcher($oldUser['role'])||roleIsDispatcher($role));
                }
                if ((int)$oldUser['active'] === 1 && ($role !== $oldUser['role'] || $shift !== $oldUser['shift'])) self::assertRoleShiftVacancy($p, $role, $shift, $id);
                run($p, 'UPDATE users SET full_name=?, email=?, role=?, shift=? WHERE id=?', [$name, $email, $role, $shift, $id]);
                auditWithChanges($p, $u['id'], 'Access', 'user ' . $id, 'account updated', "$name / $role" . ($shift ? " / $shift" : '') . ' / ' . $email,
                    ['Full name' => $oldUser['full_name'], 'Email' => $oldUser['email'], 'Role' => $oldUser['role'], 'Shift' => $oldUser['shift']],
                    ['Full name' => $name, 'Email' => $email, 'Role' => $role, 'Shift' => $shift]);
                return ['message' => 'Account updated.'];
            }
            $username = field($d, 'username', 70);
            must((bool)preg_match('/^[a-zA-Z0-9_.-]{3,70}$/', $username), 'Username: 3-70 letters, numbers, dot, dash or underscore.');
            must(!one($p, 'SELECT id FROM users WHERE username=?', [$username]), 'Username already exists.', 409);
            $pw = AccountSecurity::newTemporaryPassword();
            self::assertRoleShiftVacancy($p, $role, $shift);
            $id = insert($p, 'users', ['username' => $username, 'email' => $email, 'password_hash' => password_hash($pw, PASSWORD_DEFAULT),
                'full_name' => $name, 'role' => $role, 'shift' => $shift, 'must_change_password' => 1]);
            auditWithChanges($p, $u['id'], 'Access', 'user ' . $id, 'account created', "$username / $role / " . $email, [],
                ['Username' => $username, 'Full name' => $name, 'Email' => $email, 'Role' => $role, 'Shift' => $shift, 'Active' => 'Yes']);
            return ['id' => $id, 'temporary_password' => $pw, 'temporary_for' => $username,
                    'message' => "Account $username created. Copy the temporary password now: it is shown only once. The user must replace it at first login."];
        });
    }

    private static function userToggle(array $u, array $d): array {
        requireRole($u, 'admin');
        $id = idField($d, 'user_id'); $active = !empty($d['active']) ? 1 : 0;
        must($id !== $u['id'], 'You cannot deactivate your own account.');
        return transaction(function (PDO $p) use ($u, $id, $active) {
            $t = one($p, 'SELECT role, shift, full_name, active FROM users WHERE id=? FOR UPDATE', [$id]);
            must((bool)$t, 'User not found.', 404);
            if(!$active){core('DispatcherTurnover');DispatcherTurnover::guardAccountChange($p,$id,roleIsDispatcher($t['role']));}
            if (!$active && $t['role'] === 'admin') must((int)one($p, "SELECT COUNT(*) n FROM users WHERE role='admin' AND active=1")['n'] > 1, 'Keep at least one active Admin.');
            if ($active && (int)$t['active'] !== 1) self::assertRoleShiftVacancy($p, $t['role'], $t['shift'], $id);
            run($p, 'UPDATE users SET active=? WHERE id=?', [$active, $id]);
            auditWithChanges($p, $u['id'], 'Access', 'user ' . $id, $active ? 'account activated' : 'account deactivated', $t['full_name'],
                ['Account active' => (int)$t['active'] ? 'Yes' : 'No'], ['Account active' => $active ? 'Yes' : 'No']);
            return ['message' => $t['full_name'] . ($active ? ' can log in again.' : ' can no longer log in.')];
        });
    }

    /** Admin reset: generates a NEW random temporary password (the existing one is never readable). Shown once. */
    private static function userPassword(array $u, array $d): array {
        requireRole($u, 'admin');
        $id = idField($d, 'user_id');
        must($id !== $u['id'], 'Use Change password for your own account.');
        return transaction(function (PDO $p) use ($u, $id) {
            $t = one($p, 'SELECT username FROM users WHERE id=? FOR UPDATE', [$id]);
            must((bool)$t, 'User not found.', 404);
            $pw = AccountSecurity::newTemporaryPassword();
            run($p, 'UPDATE users SET password_hash=?, must_change_password=1 WHERE id=?', [password_hash($pw, PASSWORD_DEFAULT), $id]);
            AccountSecurity::passwordChanged($id);
            audit($p, $u['id'], 'Access', 'user ' . $id, 'password reset by Admin', 'new temporary password generated; old sessions signed out; user must change it at next login');
            return ['temporary_password' => $pw, 'temporary_for' => (string)$t['username'],
                    'message' => 'Password reset. Copy the temporary password now: it is shown only once. The user must replace it at next login.'];
        });
    }

    private static function changePassword(array $u, array $d): array {
        $current = field($d, 'current_password', 255); $new = field($d, 'new_password', 255);
        self::strongPassword($new, field($d, 'confirm', 255, false));
        must($new !== $current, 'Choose a different password.');
        return transaction(function (PDO $p) use ($u, $current, $new) {
            $row = one($p, 'SELECT password_hash FROM users WHERE id=?', [$u['id']]);
            must(password_verify($current, $row['password_hash']), 'Current password is wrong.', 403);
            run($p, 'UPDATE users SET password_hash=? WHERE id=?', [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
            $_SESSION['login_at'] = strtotime(AccountSecurity::passwordChanged($u['id']));
            audit($p, $u['id'], 'Access', 'user ' . $u['id'], 'own password changed');
            return ['message' => 'Password changed.'];
        });
    }

    /** Admin recovery code (no email in the system): printed and kept safe; works once. */
    private static function recoveryCodeCreate(array $u, array $d): array {
        requireRole($u, 'admin');
        $current = field($d, 'current_password', 255);
        $row = one(db(), 'SELECT * FROM users WHERE id=?', [$u['id']]);
        must(password_verify($current, (string)$row['password_hash']), 'Current password is wrong.', 403);
        $code = AccountSecurity::newCode();
        run(db(), 'UPDATE users SET recovery_hash=? WHERE id=?', [password_hash(AccountSecurity::normalizeCode($code), PASSWORD_DEFAULT), $u['id']]);
        audit(db(), $u['id'], 'Access', 'user ' . $u['id'], 'recovery code created', 'the previous code no longer works');
        return ['code' => $code, 'message' => 'Recovery code created. It is shown only once.'];
    }

    /** E-signature image of an approver (printed on approved purchase orders). */
    /**
     * Every user can save an e-signature: an uploaded PNG/JPG image or a drawing made in the
     * app (sent as PNG). It is stored privately (entity "user", not in the Documents list) and
     * is shown only through index.php?api=signature to signed-in users.
     */
    private static function signatureUpload(array $u, array $d): array {
        must(Files::has('signature'), 'Choose a signature image or draw your signature.');
        $f = Files::list('signature')[0];
        must(($f['error'] ?? 1) === UPLOAD_ERR_OK && (int)$f['size'] > 0, 'The signature upload failed. Try again.');
        must((int)$f['size'] <= 2 * 1024 * 1024, 'The signature image is larger than 2 MB.');
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        must(in_array($mime, ['image/png', 'image/jpeg'], true), 'The signature must be a PNG or JPG image.');
        $size = @getimagesize($f['tmp_name']);
        must($size && $size[0] >= 60 && $size[1] >= 20, 'The signature image is too small or damaged.');
        if (extension_loaded('gd') && $mime === 'image/png' && ($d['method'] ?? '') === 'draw') must(self::hasInk($f['tmp_name']), 'The drawing is empty. Draw your signature first.');
        return transaction(function (PDO $p) use ($u, $d) {
            $ids = Files::store($p, $u, 'signature', 'user', $u['id'], 'User E-Signature', true);
            run($p, 'UPDATE users SET signature_document_id=? WHERE id=?', [$ids[0], $u['id']]);
            audit($p, $u['id'], 'Access', 'user ' . $u['id'], 'e-signature saved', ($d['method'] ?? '') === 'draw' ? 'drawn in the app' : 'uploaded image');
            return ['message' => 'E-signature saved. It appears on the forms you prepare or approve.'];
        });
    }

    /** True when a drawn PNG has enough dark, visible pixels (not an empty box). */
    private static function hasInk(string $file): bool {
        $im = @imagecreatefrompng($file);
        if (!$im) return false;
        $w = imagesx($im); $h = imagesy($im); $ink = 0;
        for ($x = 0; $x < $w; $x += 2) for ($y = 0; $y < $h; $y += 2) {
            $c = imagecolorsforindex($im, imagecolorat($im, $x, $y));
            if ($c['alpha'] < 100 && ($c['red'] + $c['green'] + $c['blue']) < 450) { if (++$ink > 40) { imagedestroy($im); return true; } }
        }
        imagedestroy($im);
        return false;
    }

    // ---------------------------------------------------------------- settings
    private static function settingSave(array $u, array $d): array {
        requireRole($u,'admin');
        must(!in_array($d['key']??$d['setting_key']??null,['km_per_liter','fuel_price_per_liter'],true),'Use Pricing > Fuel Adjustment effective history.',409);
        requireRole($u, 'admin');
        $key = field($d, 'key', 80);
        must(isset(Settings::DEFS[$key]), 'Unknown setting.');
        [$label, , $unit, , $min, $max] = Settings::DEFS[$key];
        $raw = trim((string)($d['value'] ?? ''));
        must(is_numeric($raw) && (float)$raw >= $min && (float)$raw <= $max, "$label must be a number from $min to $max.");
        // DAILY_ATTENDANCE_V1: the reminder hour is a whole hour.
        $hourKeys = ['attendance_day_start_hour' => true, 'attendance_night_start_hour' => true];
        if (isset($hourKeys[$key])) must(ctype_digit($raw), "$label must be a whole hour (12:00 AM to 11:00 PM).");
        if ($key === 'crew_attendance_dispatch_check') must(in_array($raw, ['1', '2'], true), "$label must be Warn (1) or Enforce (2).");
        return transaction(function (PDO $p) use ($u, $key, $raw, $label, $hourKeys) {
            $previousSetting = one($p, 'SELECT setting_value FROM app_settings WHERE setting_key=?', [$key]);
            $p->prepare('INSERT INTO app_settings (setting_key, setting_value, updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_by=VALUES(updated_by)')
              ->execute([$key, $raw, $u['id']]);
            Settings::clearCache();
            // The reminder hour is shown as 12-hour times (stored value stays 0-23).
            $show = fn($v) => $v === null || $v === '' ? $v : (isset($hourKeys[$key]) ? CrewAttendance::hourLabel((int)$v)
                : ($key === 'crew_attendance_dispatch_check' ? CrewAttendance::modeName((int)$v) : $v));
            auditWithChanges($p, $u['id'], 'Settings', $key, 'setting changed', "$label = " . $show($raw),
                // A reminder hour never saved before was using its default: show that time as "before".
                [$label => $show($previousSetting['setting_value'] ?? (isset($hourKeys[$key]) || $key === 'crew_attendance_dispatch_check' ? (string)(int)Settings::default($key) : null))], [$label => $show($raw)]);
            return ['message' => "$label saved (" . $show($raw) . "). New records use the new value."];
        });
    }

    private static function fundCategorySave(array $u, array $d): array {
        requireRole($u, 'admin');
        $id = optId($d, 'id');
        $name = field($d, 'name', 80); $amount = moneyField($d, 'amount'); $active = !empty($d['active']) ? 1 : 0;
        $order = (int)(intField($d, 'sort_order', 0, false) ?? 0);
        return transaction(function (PDO $p) use ($u, $id, $name, $amount, $active, $order) {
            must(!one($p, 'SELECT id FROM fund_categories WHERE name=? AND id<>?', [$name, $id ?? 0]), 'Category "' . $name . '" already exists.', 409);
            $old = $id ? one($p, 'SELECT name, amount, active, sort_order FROM fund_categories WHERE id=? FOR UPDATE', [$id]) : null;
            if ($id) {
                must((bool)$old, 'Fund category not found.', 404);
                run($p, 'UPDATE fund_categories SET name=?, amount=?, active=?, sort_order=?, updated_by=? WHERE id=?', [$name, $amount, $active, $order, $u['id'], $id]);
            } else {
                $id = insert($p, 'fund_categories', ['name' => $name, 'amount' => $amount, 'active' => $active, 'sort_order' => $order, 'updated_by' => $u['id']]);
            }
            auditWithChanges($p, $u['id'], 'Settings', 'fund category ' . $id, $old ? 'fund category updated' : 'fund category created',
                "$name PHP $amount" . ($active ? '' : ' (inactive)'),
                $old ? ['Name' => $old['name'], 'Amount (PHP)' => $old['amount'], 'Active' => (int)$old['active'] ? 'Yes' : 'No', 'Sort order' => $old['sort_order']] : [],
                ['Name' => $name, 'Amount (PHP)' => $amount, 'Active' => $active ? 'Yes' : 'No', 'Sort order' => $order]);
            return ['message' => 'Fund category saved. Old fund requests keep their amounts.'];
        });
    }

    // ---------------------------------------------------------------- backup
    private static function backupCreate(array $u): array {
        requireRole($u, 'admin');
        $p = db();
        $dir = dirname(__DIR__, 3) . '/storage/backups';
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        $name = 'mulawin_backup_' . date('Ymd_His') . '.sql';
        $fh = fopen($dir . '/' . $name, 'wb');
        must((bool)$fh, 'Cannot write to storage/backups.', 500);
        fwrite($fh, "-- Mulawin FleetOps backup " . date('Y-m-d H:i:s') . "\n-- Restore into an EMPTY database with phpMyAdmin Import.\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
        foreach ($p->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $create = $p->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM)[1];
            fwrite($fh, "DROP TABLE IF EXISTS `$table`;\n$create;\n");
            $stmt = $p->query('SELECT * FROM `' . $table . '`');
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $vals = array_map(fn($v) => $v === null ? 'NULL' : $p->quote((string)$v), array_values($row));
                fwrite($fh, 'INSERT INTO `' . $table . '` (`' . implode('`,`', array_keys($row)) . '`) VALUES (' . implode(',', $vals) . ");\n");
            }
            fwrite($fh, "\n");
        }
        fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fh);
        audit($p, $u['id'], 'Backup', $name, 'database backup created');
        return ['file' => $name, 'message' => 'Backup created. Download it and keep a copy outside this computer, together with the storage/private folder (uploaded files).'];
    }

    // ---------------------------------------------------------------- notifications (every user, own only)
    /** Notification history page: active and resolved/archived, filtered by state. */
    private static function notificationsPage(array $u, array $q): array {
        $state = in_array($q['state'] ?? '', Notify::STATES, true) ? (string)$q['state'] : null;
        $w = 'WHERE user_id=?'; $a = [$u['id']];
        if ($state) { $w .= ' AND state=?'; $a[] = $state; }
        else $w .= " AND state<>'Archived'";
        return [
            'items' => many(db(), "SELECT id, category, message, page, state, created_at, read_at, resolved_at, resolved_by IS NULL AS auto_resolved FROM notifications $w ORDER BY FIELD(state,'Unread','Read','Resolved','Archived'), id DESC LIMIT 500", $a),
            'counts' => array_column(many(db(), 'SELECT state, COUNT(*) AS n FROM notifications WHERE user_id=? GROUP BY state', [$u['id']]), 'n', 'state'),
            'states' => Notify::STATES,
        ];
    }

    /** Mark read (one or all), or resolve / archive one notification. Only the user's own notifications. */
    private static function notificationsRead(array $u, array $d): array {
        $id = optId($d, 'id');
        $to = choiceField(['state' => $d['state'] ?? 'Read'], 'state', ['Read', 'Resolved', 'Archived']);
        $p = db();
        if (!$id) {
            must($to === 'Read', 'Only "mark all as read" works on all notifications.');
            run($p, "UPDATE notifications SET state='Read', read_at=COALESCE(read_at, NOW()) WHERE user_id=? AND state='Unread'", [$u['id']]);
            return ['message' => 'All notifications marked as read.'] + Notify::forUser($p, $u['id']);
        }
        $n = one($p, 'SELECT id, state FROM notifications WHERE id=? AND user_id=?', [$id, $u['id']]);
        must((bool)$n, 'Notification not found.', 404);
        if ($to === 'Read') run($p, "UPDATE notifications SET state='Read', read_at=COALESCE(read_at, NOW()) WHERE id=? AND state='Unread'", [$id]);
        elseif ($to === 'Resolved') run($p, "UPDATE notifications SET state='Resolved', read_at=COALESCE(read_at, NOW()), resolved_at=NOW(), resolved_by=? WHERE id=? AND state IN ('Unread','Read')", [$u['id'], $id]);
        else run($p, "UPDATE notifications SET state='Archived', read_at=COALESCE(read_at, NOW()), resolved_at=COALESCE(resolved_at, NOW()), resolved_by=COALESCE(resolved_by, ?) WHERE id=?", [$u['id'], $id]);
        return ['message' => $to === 'Read' ? 'Marked as read.' : ($to === 'Resolved' ? 'Notification resolved.' : 'Notification archived.')] + Notify::forUser($p, $u['id']);
    }
}
