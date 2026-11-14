<?php
/*
 * backend/Core/Notify.php  —  in-app notifications (bell icon)
 * Pending approvals, trip updates, expiring documents and other important
 * activities (PDF #11 V.63).
 *
 * States:  Unread -> Read (opened) -> Resolved (done / no longer relevant) -> Archived (hidden)
 *  - The bell shows Unread and Read notifications (the active list) and counts the Unread ones.
 *  - A notification made with a task key (dedupe_key) is RESOLVED AUTOMATICALLY for every
 *    receiver when the task is done (for example the trip request was reviewed).
 *  - Nothing is deleted: resolved and archived notifications stay in the history.
 * A failed notification never stops the main transaction.
 */
declare(strict_types=1);

final class Notify {
    public const STATES = ['Unread', 'Read', 'Resolved', 'Archived'];

    /** Account-addressed operational tasks; planning shifts remain historical metadata. */
    public static function tripRoles(PDO $p, array $trip, array $roles, string $category, string $message, ?string $page = null, ?string $key = null): void {
        $dispatcher=$trip['dispatcher_role']??null;
        self::roles($p,array_values(array_filter($roles,fn($r)=>!roleIsDispatcher($r))),$category,$message,$page,$key);
        if(!$dispatcher||!in_array($dispatcher,$roles,true))return;
        try{$owner=one($p,'SELECT current_dispatcher_id FROM trip_responsibilities WHERE trip_id=?',[$trip['id']??0]);}
        catch(Throwable $e){$owner=null;}
        if($owner)self::user($p,(int)$owner['current_dispatcher_id'],$category,$message,$page,$key);
        else self::roles($p,['operations_head'],'Initial Dispatcher responsibility',($trip['trip_ref']??'Trip').': assign an initial responsible account before Dispatcher processing.','trips',$key);
    }

    /** Send to every active user with one of the roles. */
    public static function roles(PDO $p, array $roles, string $category, string $message, ?string $page = null, ?string $key = null): void {
        try {
            $roles = array_values(array_unique(array_filter($roles)));
            if (!$roles) return;
            $marks = implode(',', array_fill(0, count($roles), '?'));
            foreach (many($p, "SELECT id FROM users WHERE active=1 AND role IN ($marks)", $roles) as $u) {
                self::user($p, (int)$u['id'], $category, $message, $page, $key);
            }
        } catch (Throwable $e) { error_log('Notify::roles failed: ' . $e->getMessage()); }
    }

    /** Send to one user. With a key the same task is not repeated for that user. */
    public static function user(PDO $p, ?int $userId, string $category, string $message, ?string $page = null, ?string $key = null): void {
        if (!$userId) return;
        try {
            $p->prepare('INSERT IGNORE INTO notifications (user_id, category, message, page, dedupe_key) VALUES (?,?,?,?,?)')
              ->execute([$userId, $category, cut($message, 300), $page, $key]);
        } catch (Throwable $e) { error_log('Notify::user failed: ' . $e->getMessage()); }
    }

    /** Unique key for a task that can come back (for example a fund request submitted again). */
    public static function key(string $prefix): string { return $prefix . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(2)); }

    /**
     * The task behind the key is done: resolve its notification for everyone who received it.
     * A key ending in * resolves every key that starts with it (all rounds of the same task).
     */
    public static function resolve(PDO $p, string $key): void {
        try {
            if (str_ends_with($key, '*')) {
                run($p, "UPDATE notifications SET state='Resolved', resolved_at=NOW(), resolved_by=NULL WHERE dedupe_key LIKE ? AND state IN ('Unread','Read')", [str_replace(['%', '_'], ['\\%', '\\_'], substr($key, 0, -1)) . '%']);
                return;
            }
            run($p, "UPDATE notifications SET state='Resolved', resolved_at=NOW(), resolved_by=NULL WHERE dedupe_key=? AND state IN ('Unread','Read')", [$key]);
        } catch (Throwable $e) { error_log('Notify::resolve failed: ' . $e->getMessage()); }
    }

    /** Bell data for one user: active list (Unread + Read) and the unread count. */
    public static function forUser(PDO $p, int $userId): array {
        return [
            'items' => many($p, "SELECT id, category, message, page, state, created_at, read_at FROM notifications WHERE user_id=? AND (dedupe_key IS NULL OR dedupe_key NOT LIKE 'trip-review-%') AND state IN ('Unread','Read') ORDER BY (state='Unread') DESC, id DESC LIMIT 30", [$userId]),
            'unread' => (int)one($p, "SELECT COUNT(*) AS n FROM notifications WHERE user_id=? AND (dedupe_key IS NULL OR dedupe_key NOT LIKE 'trip-review-%') AND state='Unread'", [$userId])['n'],
            'active' => (int)one($p, "SELECT COUNT(*) AS n FROM notifications WHERE user_id=? AND (dedupe_key IS NULL OR dedupe_key NOT LIKE 'trip-review-%') AND state IN ('Unread','Read')", [$userId])['n'],
        ];
    }

    /**
     * Daily reminders: expiring truck documents, driver licenses, uploaded documents and
     * low stock. Runs when a dashboard is opened; the key makes it once per item per day.
     */
    public static function dailyReminders(PDO $p): void {
        try {
            core('Settings');
            $today = date('Y-m-d');
            $docDays = (int)Settings::value('document_alert_days');
            $licDays = (int)Settings::value('license_alert_days');
            foreach (many($p, "SELECT id, category, original_filename, expires_on FROM documents WHERE expires_on IS NOT NULL AND expires_on <= DATE_ADD(CURRENT_DATE(), INTERVAL ? DAY)", [$docDays]) as $d) {
                self::roles($p, ['admin'], 'Expiring document', $d['category'] . ' "' . $d['original_filename'] . '" expires on ' . $d['expires_on'], 'documents', 'doc-' . $d['id'] . '-' . $today);
            }
            foreach (many($p, "SELECT id, plate_number, registration_expiry, insurance_expiry FROM vehicles WHERE active=1 AND ((registration_expiry IS NOT NULL AND registration_expiry <= DATE_ADD(CURRENT_DATE(), INTERVAL ? DAY)) OR (insurance_expiry IS NOT NULL AND insurance_expiry <= DATE_ADD(CURRENT_DATE(), INTERVAL ? DAY)))", [$docDays, $docDays]) as $v) {
                self::roles($p, ['admin', 'operations_head'], 'Truck documents', 'Truck ' . $v['plate_number'] . ': registration ' . ($v['registration_expiry'] ?? '-') . ', insurance ' . ($v['insurance_expiry'] ?? '-'), 'trucks', 'veh-' . $v['id'] . '-' . $today);
            }
            foreach (many($p, "SELECT id, full_name, license_expiry FROM personnel WHERE active=1 AND license_expiry IS NOT NULL AND license_expiry <= DATE_ADD(CURRENT_DATE(), INTERVAL ? DAY)", [$licDays]) as $c) {
                self::roles($p, ['admin', 'operations_head'], 'Driver license', $c['full_name'] . ' license expires on ' . $c['license_expiry'], 'crew', 'lic-' . $c['id'] . '-' . $today);
            }
            foreach (many($p, 'SELECT id, part_number, description, quantity_on_hand, reorder_level FROM parts WHERE active=1 AND reorder_level > 0 AND quantity_on_hand <= reorder_level') as $pt) {
                self::roles($p, ['purchasing'], 'Low stock', $pt['part_number'] . ' ' . $pt['description'] . ': ' . $pt['quantity_on_hand'] . ' left (reorder level ' . $pt['reorder_level'] . ')', 'inventory', 'stock-' . $pt['id'] . '-' . $today);
            }
        } catch (Throwable $e) { error_log('dailyReminders failed: ' . $e->getMessage()); }
    }
}
