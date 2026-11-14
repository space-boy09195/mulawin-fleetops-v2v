<?php
/*
 * backend/Data.php  —  session information for the screen
 * Returns who is signed in, the sidebar pages for the role and the
 * latest notifications. Page data is loaded separately per page by
 * Actions::pageData(), so one broken page never blocks the others.
 */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

final class Data {
    public static function me(): array {
        core('Access');
        $u = currentUser();
        core('SecurityPasswordChange');
        $u['setup_required'] = SecurityPasswordChange::required((int)$u['id']);
        if ($u['setup_required']) {
            // Forced password change pending: nothing but the password screen's own needs (no menu, notifications or settings).
            return ['ok' => true, 'user' => ['id' => $u['id'], 'username' => $u['username'], 'full_name' => $u['full_name'], 'role' => $u['role'],
                    'role_label' => $u['role_label'], 'setup_required' => true],
                    'csrf' => $_SESSION['csrf'] ?? '', 'passwordMin' => PASSWORD_MIN_LENGTH];
        }
        $bell = ['items' => [], 'unread' => 0, 'active' => 0];
        try { core('Notify'); if ($u['role'] === 'admin') { require_once __DIR__ . '/Modules/Administration/Attendance.php'; Attendance::remind(db()); } $bell = Notify::forUser(db(), $u['id']); }
        catch (Throwable $e) { error_log('Notifications unavailable: ' . $e->getMessage()); }
        $u['has_signature'] = (bool)(one(db(), 'SELECT signature_document_id FROM users WHERE id=?', [$u['id']])['signature_document_id'] ?? null);
        return [
            'ok' => true, 'user' => $u, 'csrf' => $_SESSION['csrf'] ?? '',
            'nav' => Access::navFor($u), 'routes' => Access::routesFor($u), 'sections' => Access::SECTIONS,
            'notifications' => $bell['items'], 'unread' => $bell['unread'], 'activeNotifications' => $bell['active'],
            'map' => self::mapConfig(),
            'company' => ['name' => envValue('COMPANY_NAME', 'R P MULAWIN TRUCKING SERVICE'),
                          'address' => envValue('COMPANY_ADDRESS', '#086-A Purok 2 Brgy. Luyos Tanauan City, Batangas')],
            'truckTypes' => TRUCK_TYPES, 'passwordMin' => PASSWORD_MIN_LENGTH, 'roles' => ROLE_LABELS,
        ];
    }

    /**
     * Map settings for the screen (Checklist #5). Map tiles: OpenStreetMap (MAP_TILE_URL in .env).
     * Road distance and address search go through the server (backend/Core/Routing.php):
     * the ORS API key is NEVER included here.
     */
    private static function mapConfig(): array {
        $osm = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
        $tile = envValue('MAP_TILE_URL', $osm) ?: $osm;
        if (!preg_match('#^https://[A-Za-z0-9.-]+(:\d+)?/[^\s"\'<>]*\{z\}[^\s"\'<>]*\{x\}[^\s"\'<>]*\{y\}[^\s"\'<>]*$#', $tile)) $tile = $osm;
        $attr = envValue('MAP_TILE_ATTRIBUTION', '') ?: '© OpenStreetMap contributors';
        try { core('Routing'); $routing = Routing::configured(); $options = array_map(fn($o) => $o['label'], Routing::OPTIONS); }
        catch (Throwable $e) { $routing = false; $options = []; }
        try { core('RouteDistance'); $ratio = RouteDistance::OVERRIDE_WARNING_RATIO; } catch (Throwable $e) { $ratio = 0.15; }
        return ['tileUrl' => $tile, 'tileAttribution' => cut($attr, 200), 'routing' => $routing, 'routeOptions' => $options,
                'routeAttribution' => '© openrouteservice.org by HeiGIT', 'defaultRouteOption' => 'recommended', 'overrideWarningRatio' => $ratio];
    }
}
