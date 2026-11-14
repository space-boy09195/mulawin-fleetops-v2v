<?php
/*
 * =====================================================================
 *  backend/Modules/General/Documents.php  —  DOCUMENT MANAGEMENT
 * =====================================================================
 *  PDF #11 II.M and II.O:
 *    - upload scanned documents, photos and files (bulk upload allowed)
 *    - attach to trips, vehicles, employees, transactions, maintenance
 *    - search, view, download by authorized users
 *    - categories, descriptions, expiry reminders
 *    - Admin keeps OR/CR, insurance, contracts, forms and templates
 *  Files are saved in storage/private with random names; they are only
 *  given out through the download check below.
 * =====================================================================
 */
declare(strict_types=1);
core('Settings');
core('Access');
core('DispatcherScope');

final class Documents {
    private const MIME = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    private const MAX_BYTES = 10000000; // 10 MB per file
    /** Record type -> table that must contain the linked id (null = company / administrative file). */
    public const ENTITY_TABLES = [
        'trip' => 'trips', 'incident' => 'incidents', 'vehicle' => 'vehicles', 'personnel' => 'personnel', 'client' => 'clients',
        'maintenance' => 'maintenance_jobs', 'purchase' => 'purchase_orders', 'fund' => 'fund_requests', 'expense' => 'expenses',
        'payable' => 'payables', 'billing' => 'billings', 'company' => null,
        // STOCK_COUNT_RECONCILIATION_V1: evidence of a physical stock count (migration 2026-11-12_13).
        'stock_count' => 'stock_counts',
    ];
    public const CATEGORIES = ['OR/CR', 'Insurance', 'Contract / Agreement', 'Company Form / Template', 'Permit / Pass', 'Waybill', 'Acknowledgement Receipt',
                               'Receipt / Invoice', 'Incident Photo', 'Police Report', 'Repair Evidence', 'Purchase Document', 'Employee Record', 'Other'];

    /** Upload rights per role (PDF #11 role list). */
    private static function canUpload(string $role, string $type): bool {
        if ($role === 'admin') return true;
        return in_array($type, match ($role) {
            'operations_head' => ['trip', 'vehicle', 'personnel', 'client'],
            'car_carrier_dispatcher', 'container_wingvan_dispatcher' => ['trip', 'incident'],
            'motorpool' => ['vehicle', 'maintenance', 'incident'],
            'purchasing' => ['purchase', 'stock_count'],
            'finance' => ['fund', 'expense', 'payable', 'purchase'],
            'billing' => ['billing', 'client'],
            default => [],
        }, true);
    }
    /** View rights: upload rights plus read-only access (Audit = financial and trip records). */
    private static function canView(string $role, string $type): bool {
        if (self::canUpload($role, $type)) return true;
        return in_array($type, match ($role) {
            'operations_head' => ['incident', 'maintenance', 'fund', 'billing'],
            'car_carrier_dispatcher', 'container_wingvan_dispatcher' => ['vehicle', 'client'],
            'motorpool' => ['trip'],   // purchase-order documents belong to Purchasing/Finance
            'purchasing' => ['vehicle'],   // repair evidence and incident photos stay with Motorpool
            'finance' => ['billing', 'trip', 'client'],
            'billing' => ['trip'],
            'audit' => ['trip', 'incident', 'fund', 'expense', 'payable', 'billing', 'purchase', 'client', 'maintenance', 'vehicle', 'company', 'stock_count'],
            default => [],
        }, true);
    }

    public static function read(array $u, string $page, array $q): array {
        $p = db();
        $types = array_values(array_filter(array_keys(self::ENTITY_TABLES), fn($t) => self::canView($u['role'], $t)));
        $data = readSections([
            'documents' => function () use ($p, $u, $types) {
                if (!$types) return [];
                $marks = implode(',', array_fill(0, count($types), '?'));
                $rows = many($p, "SELECT d.id, d.category, d.entity_type, d.entity_id, d.original_filename, d.mime_type, d.byte_size, d.expires_on, d.notes, d.created_at,
                        DATEDIFF(d.expires_on, CURRENT_DATE()) AS days_left, us.full_name AS uploader,
                        CASE d.entity_type WHEN 'trip' THEN (SELECT x.trip_ref FROM trips x WHERE x.id=d.entity_id)
                          WHEN 'vehicle' THEN (SELECT x.plate_number FROM vehicles x WHERE x.id=d.entity_id)
                          WHEN 'client' THEN (SELECT x.company_name FROM clients x WHERE x.id=d.entity_id)
                          WHEN 'personnel' THEN (SELECT x.full_name FROM personnel x WHERE x.id=d.entity_id)
                          WHEN 'incident' THEN (SELECT x.incident_ref FROM incidents x WHERE x.id=d.entity_id)
                          WHEN 'maintenance' THEN (SELECT x.job_ref FROM maintenance_jobs x WHERE x.id=d.entity_id)
                          WHEN 'purchase' THEN (SELECT x.po_ref FROM purchase_orders x WHERE x.id=d.entity_id)
                          WHEN 'fund' THEN (SELECT x.fund_ref FROM fund_requests x WHERE x.id=d.entity_id)
                          WHEN 'payable' THEN (SELECT x.payable_ref FROM payables x WHERE x.id=d.entity_id)
                          WHEN 'billing' THEN (SELECT COALESCE(x.invoice_number, CONCAT('Billing #', x.id)) FROM billings x WHERE x.id=d.entity_id)
                          WHEN 'stock_count' THEN CONCAT('Stock count #', d.entity_id)   -- no table read: works before migration 13 too
                          ELSE 'Company file' END AS record_label
                    FROM documents d JOIN users us ON us.id=d.uploaded_by WHERE d.entity_type IN ($marks) AND d.category<>'User E-Signature' ORDER BY d.id DESC LIMIT 2000", $types);
                // Enforce the SAME document authorization for every role on listings,
                // direct downloads, and uploads. Cache repeated record checks.
                $checked = [];
                $rows = array_values(array_filter($rows, static function (array $d) use ($p, $u, &$checked): bool {
                    $key = (string)$d['entity_type'] . ':' . (string)$d['entity_id'];
                    if (!array_key_exists($key, $checked)) {
                        $checked[$key] = self::authorizedDocument($p, $u, 'view',
                            (string)$d['entity_type'], (int)$d['entity_id'], (string)$d['category']);
                    }
                    return $checked[$key];
                }));
                return $rows;
            },
        ]);
        $data['uploadTypes'] = array_values(array_filter(['company', 'vehicle', 'personnel', 'client', 'trip'], fn($t) => self::canUpload($u['role'], $t)));
        // Records to choose from when uploading (other records get their files on their own screens).
        $data['records'] = readSections([
            'vehicle' => fn() => in_array('vehicle', $data['uploadTypes'], true) ? many($p, 'SELECT id, plate_number AS label FROM vehicles WHERE active=1 ORDER BY plate_number') : [],
            'personnel' => fn() => in_array('personnel', $data['uploadTypes'], true) ? many($p, 'SELECT id, full_name AS label FROM personnel WHERE active=1 ORDER BY full_name') : [],
            'client' => fn() => in_array('client', $data['uploadTypes'], true) ? many($p, "SELECT id, company_name AS label FROM clients WHERE status<>'Inactive' ORDER BY company_name") : [],
            'trip' => function()use($p,$u,$data){if(!in_array('trip',$data['uploadTypes'],true))return [];if(!roleIsDispatcher($u['role']))return many($p,'SELECT id,trip_ref AS label FROM trips ORDER BY id DESC LIMIT 300');[$scope,$args]=DispatcherScope::tripSql($u);return many($p,"SELECT t.id,t.trip_ref AS label FROM trips t WHERE 1=1 $scope ORDER BY t.id DESC LIMIT 300",$args);},
        ]);
        $data['categories'] = self::CATEGORIES;
        $data['entityLabels'] = ['trip' => 'Trip', 'incident' => 'Incident', 'vehicle' => 'Truck', 'personnel' => 'Driver / Helper', 'client' => 'Client',
            'maintenance' => 'Maintenance', 'purchase' => 'Purchase order', 'fund' => 'Fund request', 'expense' => 'Expense', 'payable' => 'Payable',
            'billing' => 'Billing', 'company' => 'Company / Administrative'];
        $data['alertDays'] = Settings::value('document_alert_days');
        return $data;
    }

    private static function dispatcherTrip(PDO $p, array $u, int $tripId): bool {
        [$scope,$args]=DispatcherScope::tripSql($u);return (bool)one($p,"SELECT t.id FROM trips t WHERE t.id=? $scope",[$tripId,...$args]);
    }

    private static function dispatcherIncident(PDO $p, array $u, int $incidentId): bool {
        [$scope,$args]=DispatcherScope::tripSql($u);return (bool)one($p,"SELECT i.id FROM incidents i JOIN trips t ON t.id=i.trip_id WHERE i.id=? $scope",[$incidentId,...$args]);
    }

    /**
     * S4B-2B: Record visibility for dispatcher document workflows.
     * Use one rule for list/upload/download; deny unlinked vehicles/clients.
     * Other roles keep their existing document-type grants pending separate audit.
     */
    private static function recordAllowed(PDO $p, array $u, string $type, ?int $id): bool {
        if (!roleIsDispatcher($u['role'])) return true;
        if ($id === null || $id <= 0) return false;
        [$scope,$args]=DispatcherScope::tripSql($u);$types=DispatcherScope::types($u);$marks=implode(',',array_fill(0,count($types),'?'));
        return match ($type) {
            'trip' => self::dispatcherTrip($p, $u, $id),
            'incident' => self::dispatcherIncident($p, $u, $id),
            'vehicle' => (bool)one($p,"SELECT v.id FROM vehicles v WHERE v.id=? AND v.truck_type IN ($marks)",[$id,...$types]),
            'client' => (bool)one($p,
                "SELECT t.id FROM trips t WHERE t.client_id=? $scope LIMIT 1",[$id,...$args]),
            default => false,
        };
    }

    /**
     * S4B-2C: Single entry point for document access across ALL roles.
     * Existing department-wide grants remain intact pending role-by-role
     * record ownership policy approval; dispatcher record scope is retained.
     * Signature documents are never exposed through generic document access.
     */
    private static function authorizedDocument(PDO $p, array $u, string $operation,
        string $type, ?int $id, string $category = ''): bool {
        if ($category === 'User E-Signature') return false;
        if (!array_key_exists($type, self::ENTITY_TABLES)) return false;
        if ($operation === 'upload') {
            if (!self::canUpload((string)$u['role'], $type)) return false;
        } elseif ($operation === 'view') {
            if (!self::canView((string)$u['role'], $type)) return false;
        } else {
            return false;
        }
        return self::recordAllowed($p, $u, $type, $id);
    }

    public static function action(array $u, string $action, array $d): array {
        throw new BusinessRuleException('Use the upload form for documents.', 404);
    }

    private static function assertLink(PDO $p, array $u, string $type, ?int $id): void {
        must(array_key_exists($type, self::ENTITY_TABLES), 'Unknown record type.');
        $table = self::ENTITY_TABLES[$type];
        if ($table === null) { must($id === null, 'Company documents are not linked to a record number.'); return; }
        must($id !== null, 'Enter the record number (ID) this file belongs to.');
        must((bool)one($p, "SELECT id FROM $table WHERE id=?", [$id]), 'The linked record was not found.', 404);
        must(self::authorizedDocument($p, $u, 'upload', $type, $id), 'You cannot attach documents to this record.', 403);
    }

    /** POST multipart: one or many files (bulk upload). */
    public static function upload(array $u): array {
        $type = trim((string)($_POST['entity_type'] ?? ''));
        must(self::canUpload($u['role'], $type), 'You cannot upload this type of document.', 403);
        $category = trim((string)($_POST['category'] ?? ''));
        must($category !== '' && textLength($category) <= 80, 'Choose a document category.');
        // E-signatures must only be created by the authenticated profile workflow.
        must(strcasecmp($category, 'User E-Signature') !== 0, 'This document category is reserved.', 403);
        $eid = trim((string)($_POST['entity_id'] ?? ''));
        $eid = $eid === '' ? null : filter_var($eid, FILTER_VALIDATE_INT);
        must($eid === null || ($eid !== false && $eid > 0), 'Invalid record number.');
        $expiry = trim((string)($_POST['expires_on'] ?? ''));
        if ($expiry !== '') must((bool)DateTimeImmutable::createFromFormat('!Y-m-d', $expiry), 'Invalid expiry date.');
        $notes = cut(trim((string)($_POST['notes'] ?? '')), 255);
        self::assertLink(db(), $u, $type, $eid);

        // Normalise single and multiple file inputs into one list.
        $files = [];
        $f = $_FILES['files'] ?? ($_FILES['file'] ?? null);
        must(is_array($f), 'No file received. Check the upload size limit in PHP (upload_max_filesize).');
        if (is_array($f['name'])) { foreach ($f['name'] as $i => $n) $files[] = ['name' => $n, 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]]; }
        else $files[] = $f;
        must(count($files) <= 20, 'Upload at most 20 files at a time.');

        $dir = dirname(__DIR__, 3) . '/storage/private';
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        $saved = []; $moved = [];
        try {
            $p = db(); $p->beginTransaction();
            foreach ($files as $file) {
                must(($file['error'] ?? 1) === UPLOAD_ERR_OK, 'Upload failed for ' . basename((string)$file['name']) . '.');
                $bytes = (int)$file['size'];
                must($bytes > 0 && $bytes <= self::MAX_BYTES, basename((string)$file['name']) . ' is larger than 10 MB.');
                must(is_uploaded_file($file['tmp_name']), 'Invalid upload.');
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
                must(isset(self::MIME[$mime]), 'Only PDF, JPG, PNG and WebP files are allowed (' . basename((string)$file['name']) . ').');
                $original = cut(basename((string)$file['name']), 220);
                $storage = bin2hex(random_bytes(20)) . '.' . self::MIME[$mime];
                must(move_uploaded_file($file['tmp_name'], $dir . '/' . $storage), 'Could not save the file. Check folder permissions of storage/private.', 500);
                $moved[] = $dir . '/' . $storage;
                $id = insert($p, 'documents', ['category' => $category, 'entity_type' => $type, 'entity_id' => $eid, 'original_filename' => $original,
                    'storage_name' => $storage, 'mime_type' => $mime, 'byte_size' => $bytes, 'expires_on' => $expiry ?: null, 'notes' => $notes ?: null, 'uploaded_by' => $u['id']]);
                auditWithChanges($p, $u['id'], 'Documents', 'document ' . $id, 'document uploaded', "$category / $type " . ($eid ?? '') . ' / ' . $original,
                    [], ['Document category' => $category, 'Linked to' => $type, 'Record ID' => $eid,
                         'File size (bytes)' => $bytes, 'Expiry date' => $expiry ?: null]);
                $saved[] = $id;
            }
            $p->commit();
        } catch (Throwable $e) {
            if (isset($p) && $p->inTransaction()) $p->rollBack();
            foreach ($moved as $m) @unlink($m);
            throw $e;
        }
        return ['documents' => $saved, 'message' => count($saved) . ' file(s) uploaded.'];
    }

    /** Authenticated download / inline view. */
    public static function download(array $u, int $id, bool $inline): never {
        $p = db();
        $d = one($p, 'SELECT * FROM documents WHERE id=?', [$id]);
        must((bool)$d, 'Document not found.', 404);
        // Signature files are private account credentials. Printable business forms
        // use the separate ?api=signature endpoint, with its own server-side policy.
        // Do not grant generic download access to all Purchasing/Finance signatures.
        if ($d['category'] === 'User E-Signature') {
            must($d['entity_type'] === 'user' && (int)$d['entity_id'] === (int)$u['id'],
                'You cannot open this signature.', 403);
        } else {
            must(self::authorizedDocument($p, $u, 'view', (string)$d['entity_type'],
                $d['entity_id'] === null ? null : (int)$d['entity_id'], (string)$d['category']),
                'You cannot open this document.', 403);
        }
        $file = dirname(__DIR__, 3) . '/storage/private/' . $d['storage_name'];
        must(is_file($file), 'The file is missing on the server.', 404);
        header('Content-Type: ' . $d['mime_type']);
        header('Content-Length: ' . filesize($file));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace(['"', "\r", "\n"], '_', $d['original_filename']) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($file);
        exit;
    }
}
