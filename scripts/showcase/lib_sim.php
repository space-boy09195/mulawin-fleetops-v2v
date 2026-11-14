<?php
/*
 * scripts/showcase/lib_sim.php — discrete-event simulation engine and helpers for the showcase dataset.
 * Loaded only by populate.php inside the launcher's temporary copy.
 */
declare(strict_types=1);

final class Sim {
    public static PDO $p;
    /** table => [[column, 'date'|'datetime'], ...] for tables with an AUTO_INCREMENT id. */
    public static array $tables = [];
    /** table => [text columns] whose new rows may carry the real date in their text. */
    private const TEXT = ['trip_events' => ['notes'], 'audit_events' => ['details'], 'notifications' => ['message'], 'maintenance_events' => ['details']];
    /** Columns never moved by the real-clock fix (managed explicitly). */
    private const KEEP = ['trips.scheduled_at'];
    private static SplPriorityQueue $q;
    private static int $seq = 0;
    public static int $steps = 0;
    public static int $cur = 0;
    public static array $notes = [];
    public static array $corrections = [];

    public static function init(PDO $p): void {
        self::$p = $p;
        self::$q = new SplPriorityQueue();
        self::$q->setExtractFlags(SplPriorityQueue::EXTR_DATA);
        $rows = many($p, "SELECT c.TABLE_NAME AS t, c.COLUMN_NAME AS c, c.DATA_TYPE AS ty FROM information_schema.COLUMNS c
            WHERE c.TABLE_SCHEMA=DATABASE() AND c.DATA_TYPE IN ('date','datetime','timestamp') AND c.EXTRA NOT LIKE '%GENERATED%'
              AND EXISTS (SELECT 1 FROM information_schema.COLUMNS k WHERE k.TABLE_SCHEMA=c.TABLE_SCHEMA AND k.TABLE_NAME=c.TABLE_NAME AND k.COLUMN_NAME='id' AND k.EXTRA LIKE '%auto_increment%')
            ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION");
        foreach (many($p, "SELECT TABLE_NAME AS t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME='id' AND EXTRA LIKE '%auto_increment%'") as $r) self::$tables[$r['t']] = [];
        foreach ($rows as $r) self::$tables[$r['t']][] = [$r['c'], $r['ty'] === 'date' ? 'date' : 'datetime'];
    }

    public static function maxIds(): array {
        $sql = implode(' UNION ALL ', array_map(fn($t) => "SELECT '$t' AS t, COALESCE(MAX(id),0) AS m FROM `$t`", array_keys(self::$tables)));
        $out = [];
        foreach (self::$p->query($sql)->fetchAll() as $r) $out[$r['t']] = (int)$r['m'];
        return $out;
    }
    public static function createdIds(array $baseline): array {
        $out = [];
        foreach (array_keys(self::$tables) as $t) {
            $ids = array_map('intval', self::$p->query("SELECT id FROM `$t` WHERE id > " . (int)($baseline[$t] ?? 0) . ' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
            if ($ids) $out[$t] = $ids;
        }
        return $out;
    }

    /** Schedules a step. $when: 'Y-m-d H:i[:s]' or a Unix time. Steps run in time order (ties: in the order added). */
    public static function at(string|int $when, string $label, callable $fn): void {
        $ts = is_int($when) ? $when : strtotime($when);
        if ($ts === false) throw new RuntimeException("Bad time for $label");
        if (self::$cur && $ts < self::$cur) throw new RuntimeException("Step '$label' is scheduled before the current simulation time.");
        self::$q->insert([$ts, $label, $fn], [-$ts, -(self::$seq++)]);
    }

    public static function run(): void {
        while (!self::$q->isEmpty()) {
            [$ts, $label, $fn] = self::$q->extract();
            if ($ts > time() - 60) throw new RuntimeException("Step '$label' targets the future (" . date('Y-m-d H:i', $ts) . ').');
            self::$cur = $ts;
            self::$p->exec('SET TIMESTAMP=' . $ts);
            $before = self::maxIds();
            $real = time();
            try { $fn(); }
            catch (Throwable $e) { throw new RuntimeException("Step '$label' at " . date('Y-m-d H:i', $ts) . ' failed: ' . $e->getMessage(), 0, $e); }
            self::fix($before, $ts, $real);
            self::$p->exec('SET TIMESTAMP=DEFAULT');
            self::$steps++;
            if (self::$steps % 250 === 0) echo '  ... ' . self::$steps . ' steps, simulated ' . date('Y-m-d H:i', $ts) . "\n";
        }
    }

    /** Moves values stamped with the REAL clock on rows created in this step to the step's target time. */
    private static function fix(array $before, int $ts, int $real): void {
        $after = self::maxIds();
        $realDate = date('Y-m-d', $real); $targetDate = date('Y-m-d', $ts);
        foreach (self::$tables as $t => $cols) {
            $b = $before[$t] ?? 0;
            if (($after[$t] ?? 0) <= $b) continue;
            foreach ($cols as [$c, $type]) {
                if (in_array("$t.$c", self::KEEP, true)) continue;
                if ($type === 'date') { if ($realDate !== $targetDate) run(self::$p, "UPDATE `$t` SET `$c`=? WHERE id>? AND `$c`=?", [$targetDate, $b, $realDate]); }
                else run(self::$p, "UPDATE `$t` SET `$c` = `$c` - INTERVAL ? SECOND WHERE id>? AND `$c` >= FROM_UNIXTIME(?)", [$real - $ts, $b, $real - 5]);
            }
            if ($realDate !== $targetDate && isset(self::TEXT[$t])) foreach (self::TEXT[$t] as $c)
                run(self::$p, "UPDATE `$t` SET `$c`=REPLACE(REPLACE(`$c`, ?, ?), ?, ?) WHERE id>?", [$realDate, $targetDate, date('M j, Y', $real), date('M j, Y', $ts), $b]);
        }
        if ($realDate !== $targetDate && ($after['audit_events'] ?? 0) > ($before['audit_events'] ?? 0))
            run(self::$p, 'UPDATE audit_event_changes SET changes_json=REPLACE(changes_json, ?, ?) WHERE audit_event_id>?', [$realDate, $targetDate, $before['audit_events']]);
    }

    /** Replaces a real-clock datetime string in the text of rows created since $sinceAudit (e.g. a reschedule time). */
    public static function retext(string $from, string $to, array $since): void {
        foreach (self::TEXT as $t => $cols) foreach ($cols as $c) run(self::$p, "UPDATE `$t` SET `$c`=REPLACE(`$c`, ?, ?) WHERE id>?", [$from, $to, $since[$t] ?? 0]);
        run(self::$p, 'UPDATE audit_event_changes SET changes_json=REPLACE(changes_json, ?, ?) WHERE audit_event_id>?', [$from, $to, $since['audit_events'] ?? 0]);
    }

    /** Trip schedule helpers (real-time gates). */
    public static function schedForGates(int $tripId): void {
        run(self::$p, 'UPDATE trips SET scheduled_at=? WHERE id=?', [date('Y-m-d H:i:00', time() + 120), $tripId]);
    }
    public static function schedFinal(int $tripId, int $when, string $why): void {
        run(self::$p, 'UPDATE trips SET scheduled_at=? WHERE id=?', [date('Y-m-d H:i:00', $when), $tripId]);
        self::$corrections[] = ['trip_id' => $tripId, 'column' => 'trips.scheduled_at', 'value' => date('Y-m-d H:i', $when), 'why' => $why];
    }
}

/** Controlled system-loader actors (inactive, unusable password): actions are attributed to them, never to staff. */
final class Actors {
    public static array $u = [];
    public static function create(PDO $p, string $when): void {
        $defs = [
            'ops' => ['operations_head', null, 'System Loader – Operations Head'],
            'cw_day' => ['container_wingvan_dispatcher', 'Day', 'System Loader – Container/Wing Van Dispatch (Day)'],
            'cw_night' => ['container_wingvan_dispatcher', 'Night', 'System Loader – Container/Wing Van Dispatch (Night)'],
            'cc_day' => ['car_carrier_dispatcher', 'Day', 'System Loader – Car Carrier Dispatch (Day)'],
            'cc_night' => ['car_carrier_dispatcher', 'Night', 'System Loader – Car Carrier Dispatch (Night)'],
            'mp' => ['motorpool', null, 'System Loader – Motorpool'],
            'pur' => ['purchasing', null, 'System Loader – Purchasing'],
            'fin' => ['finance', null, 'System Loader – Finance'],
            'bill' => ['billing', null, 'System Loader – Billing'],
            'adm' => ['admin', null, 'System Loader – Head Management'],
        ];
        foreach ($defs as $k => [$role, $shift, $name]) {
            $uname = LOADER_PREFIX . $k;
            $id = insert($p, 'users', ['username' => $uname, 'email' => $uname . '@fleetops.invalid', 'password_hash' => '!' . bin2hex(random_bytes(24)),
                'full_name' => $name, 'role' => $role, 'shift' => $shift, 'active' => 0, 'must_change_password' => 1, 'created_at' => $when]);
            self::$u[$k] = ['id' => $id, 'username' => $uname, 'email' => $uname . '@fleetops.invalid', 'full_name' => $name, 'role' => $role, 'shift' => $shift];
        }
    }
    /** Dispatcher on duty for a truck type at a time (Day 06:00-17:59, Night otherwise). */
    public static function dispatcher(string $type, int $ts): array {
        $h = (int)date('G', $ts); $day = $h >= 6 && $h < 18;
        return self::$u[($type === 'Car Carrier' ? 'cc_' : 'cw_') . ($day ? 'day' : 'night')];
    }
}

/** Labelled non-official placeholder attachments. */
final class Placeholder {
    private static string $dir = '';
    private static function dir(): string {
        if (self::$dir === '') { self::$dir = sys_get_temp_dir() . '/mulawin_showcase_files_' . bin2hex(random_bytes(4)); @mkdir(self::$dir, 0700, true); }
        return self::$dir;
    }
    public static function pdf(string $category, string $ref): array {
        $lines = ['Mulawin FleetOps - DEVELOPMENT PLACEHOLDER ATTACHMENT', 'Category: ' . $category, 'Record: ' . $ref,
            'NOT AN OFFICIAL DOCUMENT. It does not represent any registration, permit, tax form,',
            'waybill, delivery or acknowledgement receipt, official receipt, signature or payment',
            'confirmation. Generated for the isolated development database only.'];
        $text = ''; $y = 760;
        foreach ($lines as $l) { $text .= 'BT /F1 11 Tf 50 ' . $y . ' Td (' . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $l) . ") Tj ET\n"; $y -= 18; }
        $objs = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            "<< /Length " . strlen($text) . " >>\nstream\n" . $text . "endstream", '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
        $pdf = "%PDF-1.4\n"; $off = [];
        foreach ($objs as $i => $o) { $off[] = strlen($pdf); $pdf .= ($i + 1) . " 0 obj\n$o\nendobj\n"; }
        $x = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n" . implode('', array_map(fn($o) => sprintf("%010d 00000 n \n", $o), $off));
        $pdf .= "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n$x\n%%EOF\n";
        return self::file($pdf, $category . ' - ' . $ref . ' (development placeholder).pdf');
    }
    /** Grey striped PNG (no photo content). */
    public static function png(string $category, string $ref): array {
        $w = 160; $h = 90; $raw = '';
        for ($y = 0; $y < $h; $y++) { $raw .= "\0"; for ($x = 0; $x < $w; $x++) { $v = (($x + $y) % 20) < 10 ? 205 : 225; $raw .= chr($v) . chr($v) . chr($v); } }
        $chunk = fn($type, $data) => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        $png = "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0)) . $chunk('IDAT', gzcompress($raw, 9)) . $chunk('IEND', '');
        return self::file($png, $category . ' - ' . $ref . ' (development placeholder).png');
    }
    private static function file(string $bytes, string $name): array {
        $path = self::dir() . '/' . bin2hex(random_bytes(8)); file_put_contents($path, $bytes);
        return ['name' => $name, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes)];
    }
}

/** Runs an application action as an actor. $files: input => ['pdf'|'png', category label, record ref]. */
function act(array $u, string $module, string $action, array $d, array $files = []): array {
    $_FILES = [];
    foreach ($files as $input => [$kind, $cat, $ref]) $_FILES[$input] = $kind === 'png' ? Placeholder::png($cat, $ref) : Placeholder::pdf($cat, $ref);
    try { return $module::action($u, $action, $d); }
    finally { $_FILES = []; }
}

/** Checklist answers: all OK, or the listed item keys NOT OK with remarks. */
function checklistItems(array $notOk = []): array {
    $items = [];
    foreach (Checklist::sections() as $s) foreach ($s['items'] as $i => $label) {
        $k = $s['key'] . '_' . str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT);
        $items[$k] = isset($notOk[$k]) ? ['condition' => 'NOT OK', 'remarks' => $notOk[$k]] : ['condition' => 'OK', 'remarks' => ''];
    }
    return $items;
}
function checklistKeys(): array {
    $keys = [];
    foreach (Checklist::sections() as $s) foreach ($s['items'] as $i => $label) $keys[$s['key'] . '_' . str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT)] = $label;
    return $keys;
}
function rnd(int $a, int $b): int { return mt_rand($a, $b); }
function pick(array $a) { return $a[array_rand($a)]; }
function money2(float $v): string { return number_format(round($v, 2), 2, '.', ''); }
