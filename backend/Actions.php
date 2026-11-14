<?php
/*
 * =====================================================================
 *  backend/Actions.php  —  sends each request to its module
 * =====================================================================
 *  Action names look like  "Trips.create"  =  module "Trips", action "create".
 *
 *  MODULE ISOLATION:
 *  A module file is loaded only when it is needed, inside try/catch.
 *  If backend/Modules/Accounting/Billing.php has an error, only Billing actions
 *  fail with "module unavailable"; Trips, Finance and the rest keep
 *  working. (PHP syntax errors in a required file are catchable as
 *  ParseError, so even a broken file does not stop the others.)
 * =====================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

final class ModuleUnavailable extends RuntimeException {}

final class Actions {
    /**
     * Modules grouped by DEPARTMENT (folder backend/Modules/<Department>/).
     *   Operations     : Fleet (trucks, drivers & helpers), Clients, Trips (with Inspections/Checklist.php), Routes (approved client routes)
     *   Maintenance    : Maintenance (corrective), Mechanics (masterlist), Purchasing (truck parts, inventory, purchase orders)
     *   Accounting     : Funds (trip funds), Billing, Finance
     *   General        : Analytics (dashboard and reports), Documents, Maps (road km and address search, server-side)
     *   Administration : Admin (users, settings, activity log, backup)
     */
    public const MODULES = [
        'Fleet' => 'Operations', 'Clients' => 'Operations', 'Trips' => 'Operations', 'Routes' => 'Operations',
        'Maintenance' => 'Maintenance', 'Mechanics' => 'Maintenance', 'Purchasing' => 'Maintenance',
        'Funds' => 'Accounting', 'Billing' => 'Accounting', 'Finance' => 'Accounting',
        'Attendance' => 'Administration',
        'Analytics' => 'General', 'Documents' => 'General', 'Maps' => 'General',
        'HeadManagement' => 'Administration',
        'Admin' => 'Administration', 'Oversight' => 'Administration',
    ];

    /** Loads backend/Modules/<Department>/<Name>.php safely and returns the class name. */
    public static function load(string $module): string {
        if (!isset(self::MODULES[$module])) throw new BusinessRuleException('Unknown module.', 404);
        if (class_exists($module, false)) return $module;
        try {
            require_once __DIR__ . '/Modules/' . self::MODULES[$module] . '/' . $module . '.php';
        } catch (Throwable $e) {
            error_log("Module $module failed to load: " . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            throw new ModuleUnavailable("The $module module is temporarily unavailable. Other modules are still working.");
        }
        if (!class_exists($module, false)) throw new ModuleUnavailable("The $module module is not installed correctly.");
        return $module;
    }

    /** POST ?api=Module.action */
    public static function run(string $api, array $data): array {
        must((bool)preg_match('/^([A-Z][A-Za-z]+)\.([a-z_]+)$/', $api, $m), 'Unknown action.', 404);
        core('Access');
        $u = currentUser();
        // Security S1: only the password-change workflow is permitted until the employee owns the credential.
        core('SecurityPasswordChange');
        if (in_array($api, ['Admin.password_change_force','Admin.password_change_request','Admin.password_change_confirm'], true)) {
            return match ($api) {
                'Admin.password_change_force' => SecurityPasswordChange::forceChange($u, $data),
                'Admin.password_change_request' => SecurityPasswordChange::request($u, $data),
                'Admin.password_change_confirm' => SecurityPasswordChange::confirm($u, $data),
            };
        }
        must(!SecurityPasswordChange::required((int)$u['id']), 'Complete your first-login password setup first.', 403);
        if (in_array($api, ['Admin.email_change_request','Admin.email_change_confirm'], true)) {
            return $api === 'Admin.email_change_request' ? SecurityPasswordChange::emailRequest($u, $data) : SecurityPasswordChange::emailConfirm($u, $data);
        }
        $class = self::load($m[1]);
        return $class::action($u, $m[2], $data);
    }

    /** GET ?api=data&page=xyz  -> data for one page only (each page loads separately). */
    public static function pageData(string $page, array $query): array {
        core('Access');
        $u = currentUser();
        core('SecurityPasswordChange');
        must(!SecurityPasswordChange::required((int)$u['id']), 'Complete your first-login password setup first.', 403);
        must(Access::canOpen($u, $page), 'You do not have access to this page.', 403);
        $class = self::load(Access::moduleOf($page));
        return $class::read($u, $page, $query);
    }
}

/**
 * Helper for modules: runs several read sections and keeps going when
 * one section fails (the page shows a small warning for that part only).
 */
/** Adds more read sections to an existing result (keeps all warnings). */
function mergeSections(array $data, array $sections): array {
    $more = readSections($sections);
    $data['_errors'] = array_merge($data['_errors'] ?? [], $more['_errors']);
    unset($more['_errors']);
    return $data + $more;
}
function readSections(array $sections): array {
    $out = ['_errors' => []];
    foreach ($sections as $name => $fn) {
        try { $out[$name] = $fn(); }
        catch (BusinessRuleException $e) { throw $e; }
        catch (Throwable $e) {
            error_log("Read section $name failed: " . $e->getMessage());
            $out[$name] = [];
            $out['_errors'][$name] = 'This part is temporarily unavailable. The rest of the page still works.';
        }
    }
    return $out;
}
