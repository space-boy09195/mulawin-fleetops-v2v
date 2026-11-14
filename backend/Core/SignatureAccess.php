<?php
/* S4A phase 1: restrict e-signatures to owner or signatories of records
 * available to the viewer's current role. Final record-specific links
 * should additionally bind the signature URL to a record ID. */
declare(strict_types=1);

final class SignatureAccess {
    public static function canView(array $viewer, int $signerId, string $recordType = '', int $recordId = 0): bool {
        $self = (int)($viewer['id'] ?? 0);
        if ($self <= 0 || $signerId <= 0) return false;
        // Own signature is visible in My Profile even without a business record.
        if ($self === $signerId) return true;
        if ($recordId <= 0) return false;
        core('Access');
        $role = (string)($viewer['role'] ?? '');
        switch ($recordType) {
            case 'purchase_order':
                if (!Access::canOpen($viewer, 'purchase_orders')) return false;
                return self::exists('SELECT id FROM purchase_orders WHERE id=? AND (requested_by=? OR decided_by=?) LIMIT 1', [$recordId, $signerId, $signerId]);
            case 'part_request':
                // Purchasing fulfils requests; Motorpool prints the request form of its own repairs.
                if (!Access::canOpen($viewer, 'parts_requests') && $role !== 'motorpool') return false;
                return self::exists('SELECT id FROM part_requests WHERE id=? AND (requested_by=? OR processed_by=?) LIMIT 1', [$recordId, $signerId, $signerId]);
            case 'fund_request':
                if (!Access::canOpen($viewer, 'funds')) return false;
                if (roleIsDispatcher($role)) {
                    core('DispatcherScope');[$scope,$args]=DispatcherScope::tripSql($viewer);
                    return self::exists('SELECT f.id FROM fund_requests f JOIN trips t ON t.id=f.trip_id WHERE f.id=? AND (f.requested_by=? OR f.approved_by=? OR f.released_by=?) '.$scope.' LIMIT 1',
                        [$recordId, $signerId, $signerId, $signerId, ...$args]);
                }
                return self::exists('SELECT id FROM fund_requests WHERE id=? AND (requested_by=? OR approved_by=? OR released_by=?) LIMIT 1', [$recordId, $signerId, $signerId, $signerId]);
            case 'billing':
                if (!Access::canOpen($viewer, 'billing')) return false;
                return self::exists('SELECT id FROM billings WHERE id=? AND finalized_by=? LIMIT 1', [$recordId, $signerId]);
            case 'collection':
                if (!Access::canOpen($viewer, 'billing')) return false;
                return self::exists('SELECT id FROM collections WHERE id=? AND recorded_by=? LIMIT 1', [$recordId, $signerId]);
            case 'incident':
                if (!Access::canOpen($viewer, 'trips')) return false;
                if (roleIsDispatcher($role)) {
                    core('DispatcherScope');[$scope,$args]=DispatcherScope::tripSql($viewer);
                    return self::exists('SELECT i.id FROM incidents i JOIN trips t ON t.id=i.trip_id WHERE i.id=? AND (i.reported_by=? OR i.assessed_by=?) '.$scope.' LIMIT 1',
                        [$recordId, $signerId, $signerId, ...$args]);
                }
                return self::exists('SELECT id FROM incidents WHERE id=? AND (reported_by=? OR assessed_by=?) LIMIT 1', [$recordId, $signerId, $signerId]);
            default:
                return false;
        }
    }

    private static function exists(string $sql, array $params): bool {
        // Database errors must never grant access.
        return (bool)one(db(), $sql, $params);
    }
}
