<?php
declare(strict_types=1);
// Shared helpers for the public order API, the waiter API and the admin API.

require_once __DIR__ . '/../admin/config.php';
date_default_timezone_set('Europe/Bucharest');
ini_set('display_errors', '0');
// Unexpected errors go to the server log; the browser only gets a generic message (no paths or SQL).
set_exception_handler(function (Throwable $e): void {
    error_log('[mocha] ' . $e);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode(['ok' => false, 'error' => 'Eroare internă. Încearcă din nou.'], JSON_UNESCAPED_UNICODE);
});

const APP_ROOT = __DIR__ . '/..';
const MENU_FILE = APP_ROOT . '/data/menu.json';
const DB_DIR = APP_ROOT . '/data/db';
const DB_FILE = DB_DIR . '/orders.sqlite';

function out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $msg, int $code = 400): never
{
    out(['ok' => false, 'error' => $msg], $code);
}

function require_post(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Metodă invalidă.', 405);
}

function json_body(int $max = 200_000): array
{
    $raw = file_get_contents('php://input') ?: '';
    if (strlen($raw) > $max) fail('Cerere prea mare.');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Session with its own storage folder and lifetime. The lifetime is absolute, counted from login:
 * the cookie expires then, and the session is refused after that even if the cookie survives.
 * With $idle, the session also ends after that many seconds without any request (a lost device
 * stops being logged in sooner). A dedicated folder per session type keeps the host's (or the
 * other type's) cleanup from deleting it early.
 */
function start_session(string $name, int $lifetime, int $idle = 0): void
{
    $dir = APP_ROOT . '/data/sessions/' . preg_replace('/[^a-z0-9_-]/i', '', $name);
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    if (!is_file(APP_ROOT . '/data/sessions/.htaccess')) @file_put_contents(APP_ROOT . '/data/sessions/.htaccess', "Require all denied\n");
    if (is_dir($dir) && is_writable($dir)) session_save_path($dir);
    ini_set('session.gc_maxlifetime', (string)$lifetime);
    ini_set('session.use_strict_mode', '1');
    session_name($name);
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path' => dirname($_SERVER['SCRIPT_NAME']) . '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
    if (!empty($_SESSION['auth'])) {
        $now = time();
        $last = (int)($_SESSION['seen_at'] ?? $_SESSION['login_at'] ?? 0);
        if ((int)($_SESSION['login_at'] ?? 0) + $lifetime < $now || ($idle > 0 && $last + $idle < $now)) {
            $_SESSION = [];
            session_regenerate_id(true);
        } else {
            $_SESSION['seen_at'] = $now;
        }
    }
}

/**
 * Password login with lockouts: 5 failures per session and 20 per IP address in 15 minutes.
 * $expected may be the plain password or a password_hash() value ($2y$… / $argon2…).
 */
function password_login(string $expected, string $notSetMsg): void
{
    require_post();
    if (mb_strlen($expected) < 10) fail($notSetMsg, 503);
    $now = time();
    $_SESSION['fails'] = array_values(array_filter($_SESSION['fails'] ?? [], fn($t) => $t > $now - 900));
    if (count($_SESSION['fails']) >= 5) fail('Prea multe încercări. Așteaptă 15 minute.', 429);
    rate_check('login-fail:' . client_ip(), 20, 900, 'Prea multe încercări greșite. Așteaptă 15 minute.');
    $body = json_body();
    $pass = is_string($body['password'] ?? null) ? $body['password'] : '';
    $hashed = str_starts_with($expected, '$2y$') || str_starts_with($expected, '$argon2');
    if (!($hashed ? password_verify($pass, $expected) : hash_equals($expected, $pass))) {
        $_SESSION['fails'][] = $now;
        rate_hit('login-fail:' . client_ip());
        sleep(1);
        fail('Parolă greșită.', 401);
    }
    session_regenerate_id(true);
    $_SESSION = ['auth' => true, 'csrf' => bin2hex(random_bytes(32)), 'login_at' => time()];
}

function require_session_auth(): void
{
    if (empty($_SESSION['auth'])) fail('Sesiunea a expirat. Autentifică-te din nou.', 401);
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf'] ?? '', $token)) fail('Cerere invalidă (token).', 403);
}

/** Single-line text from the browser: control characters removed, trimmed, cut to $max characters. */
function clean_str(mixed $v, int $max = 500): string
{
    if (!is_string($v) && !is_int($v) && !is_float($v)) return '';
    $v = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string)$v) ?? '');
    return mb_substr($v, 0, $max);
}

function load_menu(): array
{
    $menu = json_decode((string)@file_get_contents(MENU_FILE), true);
    return is_array($menu) ? $menu : ['settings' => [], 'categories' => []];
}

/** Ordering settings with defaults (older menu.json files have no such keys). */
function order_settings(array $menu): array
{
    $s = $menu['settings']['orders'] ?? [];
    return [
        'enabled' => (bool)($s['enabled'] ?? true),
        'tables' => max(1, min(500, (int)($s['tables'] ?? 20))),
        'stockCheck' => (bool)($s['stockCheck'] ?? false),
    ];
}

function to_cents(string $price): ?int
{
    $price = str_replace(',', '.', trim($price));
    if (!preg_match('/^\d+(\.\d{1,2})?$/', $price)) return null;
    return (int)round((float)$price * 100);
}

/* ---------- database ---------- */

const SCHEMA_VERSION = 3;

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) fail('Serverul nu are activat SQLite (pdo_sqlite). Activează-l din cPanel → Select PHP Version → Extensions.', 500);
    if (!is_dir(DB_DIR) && !@mkdir(DB_DIR, 0755, true)) fail('Nu pot crea folderul data/db. Verifică permisiunile folderului data/.', 500);
    if (!is_file(DB_DIR . '/.htaccess')) @file_put_contents(DB_DIR . '/.htaccess', "Require all denied\n");
    $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA foreign_keys = ON');
    // Schema setup runs only when the database is older than this code (not on every request).
    if ((int)$pdo->query('PRAGMA user_version')->fetchColumn() >= SCHEMA_VERSION) return $pdo;
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            table_no INTEGER NOT NULL,
            client TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'open',
            total_cents INTEGER NOT NULL,
            created_at TEXT NOT NULL,
            closed_at TEXT,
            payment TEXT,
            pay_request TEXT,
            pay_requested_at TEXT,
            day_id INTEGER
        );
        CREATE INDEX IF NOT EXISTS orders_status ON orders(status);
        CREATE INDEX IF NOT EXISTS orders_client ON orders(client, status);
        CREATE INDEX IF NOT EXISTS orders_day ON orders(day_id);
        CREATE TABLE IF NOT EXISTS order_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id INTEGER NOT NULL REFERENCES orders(id),
            product_id TEXT NOT NULL,
            name TEXT NOT NULL,
            subheader TEXT NOT NULL DEFAULT '',
            price_cents INTEGER NOT NULL,
            qty INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS items_order ON order_items(order_id);
        CREATE TABLE IF NOT EXISTS days (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            opened_at TEXT NOT NULL,
            closed_at TEXT NOT NULL,
            report TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS hits (
            key TEXT NOT NULL,
            at INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS hits_key ON hits(key, at);
        CREATE TABLE IF NOT EXISTS waiters (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            pass_hash TEXT NOT NULL,
            active INTEGER NOT NULL DEFAULT 1,
            deleted INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS shifts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            waiter_id INTEGER NOT NULL REFERENCES waiters(id),
            started_at TEXT NOT NULL,
            ended_at TEXT,
            closed_by TEXT,
            report TEXT
        );
        CREATE INDEX IF NOT EXISTS shifts_waiter ON shifts(waiter_id, ended_at);
        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        );
        -- Inventory. Quantities are integers in thousandths of the unit (3,5 kg = 3500).
        CREATE TABLE IF NOT EXISTS stock_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id TEXT UNIQUE,
            name TEXT NOT NULL,
            variant TEXT NOT NULL DEFAULT '',
            grp TEXT NOT NULL DEFAULT '',
            pos INTEGER NOT NULL DEFAULT 0,
            unit TEXT NOT NULL DEFAULT 'buc',
            qty INTEGER NOT NULL DEFAULT 0,
            min_qty INTEGER NOT NULL DEFAULT 0,
            tracked INTEGER NOT NULL DEFAULT 1,
            archived INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS stock_moves (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            item_id INTEGER NOT NULL REFERENCES stock_items(id),
            kind TEXT NOT NULL,
            qty INTEGER NOT NULL,
            balance INTEGER NOT NULL,
            doc TEXT NOT NULL DEFAULT '',
            note TEXT NOT NULL DEFAULT '',
            order_id INTEGER,
            created_at TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS stock_moves_item ON stock_moves(item_id, id);
        CREATE INDEX IF NOT EXISTS stock_moves_kind ON stock_moves(kind, id);
    SQL);
    // Columns added after the first release.
    $cols = array_column($pdo->query('PRAGMA table_info(orders)')->fetchAll(), 'name');
    if (!in_array('waiter_id', $cols, true)) $pdo->exec('ALTER TABLE orders ADD COLUMN waiter_id INTEGER');
    if (!in_array('shift_id', $cols, true)) $pdo->exec('ALTER TABLE orders ADD COLUMN shift_id INTEGER');
    $pdo->exec('CREATE INDEX IF NOT EXISTS orders_shift ON orders(shift_id)');
    $cols = array_column($pdo->query('PRAGMA table_info(days)')->fetchAll(), 'name');
    if (!in_array('auto', $cols, true)) $pdo->exec('ALTER TABLE days ADD COLUMN auto INTEGER NOT NULL DEFAULT 0');
    $pdo->exec('PRAGMA user_version = ' . SCHEMA_VERSION);
    return $pdo;
}

function setting(string $key, ?string $default = null): ?string
{
    $q = db()->prepare('SELECT value FROM settings WHERE key = ?');
    $q->execute([$key]);
    $v = $q->fetchColumn();
    return $v === false ? $default : (string)$v;
}

function set_setting(string $key, string $value): void
{
    db()->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')->execute([$key, $value]);
}

/** Runs $fn inside a write transaction; rolls back if it throws. */
function in_transaction(callable $fn): mixed
{
    $db = db();
    $db->exec('BEGIN IMMEDIATE');
    try {
        $result = $fn($db);
        $db->exec('COMMIT');
        return $result;
    } catch (Throwable $e) {
        try { $db->exec('ROLLBACK'); } catch (Throwable) {}
        throw $e;
    }
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

/** Sliding-window limits stored in SQLite. rate_check() refuses once $max hits happened in $seconds. */
function rate_check(string $key, int $max, int $seconds, string $msg = 'Prea multe cereri. Încearcă din nou peste câteva minute.'): void
{
    $db = db();
    $t = time();
    $db->prepare('DELETE FROM hits WHERE at < ?')->execute([$t - 3600]);
    $q = $db->prepare('SELECT COUNT(*) FROM hits WHERE key = ? AND at > ?');
    $q->execute([$key, $t - $seconds]);
    if ((int)$q->fetchColumn() >= $max) fail($msg, 429);
}

function rate_hit(string $key): void
{
    db()->prepare('INSERT INTO hits (key, at) VALUES (?, ?)')->execute([$key, time()]);
}

function rate_limit(string $key, int $max, int $seconds): void
{
    rate_check($key, $max, $seconds);
    rate_hit($key);
}

function client_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

/** Open orders with their items, optionally filtered. */
function open_orders(string $where = '1', array $args = []): array
{
    $db = db();
    $q = $db->prepare("SELECT * FROM orders WHERE status = 'open' AND ($where) ORDER BY created_at, id");
    $q->execute($args);
    $orders = $q->fetchAll();
    if (!$orders) return [];
    $ids = array_column($orders, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $q = $db->prepare("SELECT order_id, product_id, name, subheader, price_cents, qty FROM order_items WHERE order_id IN ($in) ORDER BY id");
    $q->execute($ids);
    $items = [];
    foreach ($q->fetchAll() as $it) $items[$it['order_id']][] = $it;
    foreach ($orders as &$o) {
        $o['id'] = (int)$o['id'];
        $o['table_no'] = (int)$o['table_no'];
        $o['total_cents'] = (int)$o['total_cents'];
        $o['items'] = array_map(fn($i) => [
            'product_id' => $i['product_id'],
            'name' => $i['name'],
            'subheader' => $i['subheader'],
            'price_cents' => (int)$i['price_cents'],
            'qty' => (int)$i['qty'],
        ], $items[$o['id']] ?? []);
        unset($o['client']);
    }
    return $orders;
}

/**
 * Statistics over a set of orders. $where selects the orders (alias o), $args are its parameters.
 * Paid/cancelled figures only count closed orders; "open" counts orders still open in the set.
 */
function order_stats(string $where, array $args = []): array
{
    $db = db();
    $all = function (string $sql) use ($db, $args) { $q = $db->prepare($sql); $q->execute($args); return $q->fetchAll(); };
    $one = fn(string $sql) => $all($sql)[0];

    $sum = fn(string $cond) => $one("SELECT COUNT(*) AS n, COALESCE(SUM(o.total_cents), 0) AS cents FROM orders o WHERE ($where) AND $cond");
    $paid = $sum("o.status = 'paid'");
    $cash = $sum("o.status = 'paid' AND o.payment = 'cash'");
    $card = $sum("o.status = 'paid' AND o.payment = 'card'");
    $cancelled = $sum("o.status = 'cancelled'");
    $open = $sum("o.status = 'open'");

    $tables = $all(<<<SQL
        SELECT o.table_no,
               SUM(o.status = 'paid') AS paid_orders,
               COALESCE(SUM(CASE WHEN o.status = 'paid' THEN o.total_cents END), 0) AS paid_cents,
               SUM(o.status = 'cancelled') AS cancelled_orders,
               SUM(o.status = 'open') AS open_orders,
               COALESCE(SUM(CASE WHEN o.status = 'open' THEN o.total_cents END), 0) AS open_cents
        FROM orders o WHERE ($where)
        GROUP BY o.table_no ORDER BY paid_cents DESC, o.table_no
    SQL);

    $products = $all(<<<SQL
        SELECT i.name, i.subheader, SUM(i.qty) AS qty, SUM(i.qty * i.price_cents) AS cents
        FROM order_items i JOIN orders o ON o.id = i.order_id
        WHERE ($where) AND o.status = 'paid'
        GROUP BY i.product_id, i.name, i.subheader ORDER BY qty DESC, cents DESC
    SQL);

    $hours = $all(<<<SQL
        SELECT substr(o.closed_at, 12, 2) AS hour, COUNT(*) AS n, SUM(o.total_cents) AS cents
        FROM orders o WHERE ($where) AND o.status = 'paid'
        GROUP BY hour ORDER BY hour
    SQL);

    $waiters = $all(<<<SQL
        SELECT o.waiter_id, COALESCE(w.name, 'Necunoscut') AS name,
               SUM(o.status = 'paid') AS paid_orders,
               COALESCE(SUM(CASE WHEN o.status = 'paid' THEN o.total_cents END), 0) AS paid_cents,
               COALESCE(SUM(CASE WHEN o.status = 'paid' AND o.payment = 'cash' THEN o.total_cents END), 0) AS cash_cents,
               COALESCE(SUM(CASE WHEN o.status = 'paid' AND o.payment = 'card' THEN o.total_cents END), 0) AS card_cents,
               SUM(o.status = 'cancelled') AS cancelled_orders,
               COALESCE(SUM(CASE WHEN o.status = 'cancelled' THEN o.total_cents END), 0) AS cancelled_cents
        FROM orders o LEFT JOIN waiters w ON w.id = o.waiter_id
        WHERE ($where) AND o.status IN ('paid', 'cancelled')
        GROUP BY o.waiter_id ORDER BY paid_cents DESC
    SQL);

    $cancelledList = $all(<<<SQL
        SELECT o.id, o.table_no, o.total_cents, o.created_at, o.closed_at, COALESCE(w.name, '') AS waiter
        FROM orders o LEFT JOIN waiters w ON w.id = o.waiter_id
        WHERE ($where) AND o.status = 'cancelled' ORDER BY o.closed_at DESC LIMIT 100
    SQL);

    // Cast numeric columns explicitly (SQLite may return them as strings).
    $int = function (array $rows, array $keys): array {
        foreach ($rows as &$r) foreach ($keys as $k) $r[$k] = (int)($r[$k] ?? 0);
        return $rows;
    };
    $n = fn($r) => ['count' => (int)$r['n'], 'cents' => (int)$r['cents']];

    return [
        'paid' => $n($paid),
        'cash' => $n($cash),
        'card' => $n($card),
        'cancelled' => $n($cancelled),
        'open' => $n($open),
        'tables' => $int($tables, ['table_no', 'paid_orders', 'paid_cents', 'cancelled_orders', 'open_orders', 'open_cents']),
        'products' => $int($products, ['qty', 'cents']),
        'hours' => $int($hours, ['n', 'cents']),
        'waiters' => $int($waiters, ['waiter_id', 'paid_orders', 'paid_cents', 'cash_cents', 'card_cents', 'cancelled_orders', 'cancelled_cents']),
        'cancelledList' => $int($cancelledList, ['id', 'table_no', 'total_cents']),
    ];
}

/* ---------- day (Z report) ---------- */

function day_start(): string
{
    $db = db();
    return $db->query('SELECT MAX(closed_at) FROM days')->fetchColumn()
        ?: ($db->query('SELECT MIN(created_at) FROM orders WHERE day_id IS NULL')->fetchColumn() ?: now());
}

/** Current day; with $cutoff only orders closed until then (used by the automatic close). */
function day_stats(?string $cutoff = null): array
{
    $to = $cutoff ?? now();
    $where = "o.day_id IS NULL AND (o.status = 'open' OR o.closed_at <= ?)";
    return ['from' => day_start(), 'to' => $to] + order_stats($where, [$to]);
}

/** Freeze the Z report up to $cutoff and start a new day. Returns [id, report] or null if there was nothing to close. */
function close_day(?string $cutoff = null, bool $auto = false): ?array
{
    $db = db();
    $db->exec('BEGIN IMMEDIATE');
    try {
        $report = day_stats($cutoff);
        if ($report['paid']['count'] === 0 && $report['cancelled']['count'] === 0) {
            $db->exec('ROLLBACK');
            return null;
        }
        $report['auto'] = $auto;
        $db->prepare('INSERT INTO days (opened_at, closed_at, report, auto) VALUES (?, ?, ?, ?)')
            ->execute([$report['from'], $report['to'], json_encode($report, JSON_UNESCAPED_UNICODE), $auto ? 1 : 0]);
        $id = (int)$db->lastInsertId();
        $db->prepare("UPDATE orders SET day_id = ? WHERE day_id IS NULL AND status IN ('paid', 'cancelled') AND closed_at <= ?")
            ->execute([$id, $report['to']]);
        $db->exec('COMMIT');
        return ['id' => $id, 'report' => $report];
    } catch (Throwable $e) {
        try { $db->exec('ROLLBACK'); } catch (Throwable) {}
        throw $e;
    }
}

function auto_close_config(): array
{
    $time = setting('auto_close_time', '04:00');
    return ['enabled' => setting('auto_close_enabled', '0') === '1', 'time' => preg_match('/^\d{2}:\d{2}$/', (string)$time) ? $time : '04:00'];
}

/** Most recent moment the scheduled close time was reached (today or yesterday). */
function last_scheduled_close(string $time): string
{
    $today = date('Y-m-d') . ' ' . $time . ':00';
    return $today <= now() ? $today : date('Y-m-d', strtotime('-1 day')) . ' ' . $time . ':00';
}

/** Runs on every API call: closes the day once the scheduled time has passed. No cron needed. */
function maybe_auto_close(): void
{
    $cfg = auto_close_config();
    if (!$cfg['enabled']) return;
    $cutoff = last_scheduled_close($cfg['time']);
    if (setting('auto_close_done') === $cutoff) return;
    $last = (string)db()->query('SELECT MAX(closed_at) FROM days')->fetchColumn();
    if ($last < $cutoff) close_day($cutoff, true);
    set_setting('auto_close_done', $cutoff);
}

/* ---------- waiters and shifts ---------- */

const MAX_WAITERS = 6;

function waiter_list(bool $all = false): array
{
    $rows = db()->query('SELECT id, name, active FROM waiters WHERE deleted = 0' . ($all ? '' : ' AND active = 1') . ' ORDER BY name COLLATE NOCASE')->fetchAll();
    return array_map(fn($w) => ['id' => (int)$w['id'], 'name' => $w['name'], 'active' => (bool)$w['active']], $rows);
}

function shift_stats(int $shiftId): array
{
    $q = db()->prepare('SELECT s.*, w.name FROM shifts s JOIN waiters w ON w.id = s.waiter_id WHERE s.id = ?');
    $q->execute([$shiftId]);
    $s = $q->fetch();
    if (!$s) fail('Tura nu există.', 404);
    return [
        'shift' => (int)$s['id'],
        'waiter_id' => (int)$s['waiter_id'],
        'waiter' => $s['name'],
        'from' => $s['started_at'],
        'to' => $s['ended_at'] ?: now(),
        'closed' => (bool)$s['ended_at'],
    ] + order_stats('o.shift_id = ?', [$shiftId]);
}

/** Close a shift and store its handover report. $by is "waiter" or "admin". */
function close_shift(int $shiftId, string $by): array
{
    $db = db();
    $db->exec('BEGIN IMMEDIATE');
    $q = $db->prepare('SELECT ended_at FROM shifts WHERE id = ?');
    $q->execute([$shiftId]);
    $ended = $q->fetchColumn();
    if ($ended === false || $ended !== null) { $db->exec('ROLLBACK'); fail('Tura este deja închisă.', 409); }
    $end = now();
    $db->prepare('UPDATE shifts SET ended_at = ?, closed_by = ? WHERE id = ?')->execute([$end, $by, $shiftId]);
    $report = shift_stats($shiftId);
    $db->prepare('UPDATE shifts SET report = ? WHERE id = ?')->execute([json_encode($report, JSON_UNESCAPED_UNICODE), $shiftId]);
    $db->exec('COMMIT');
    return $report;
}

require_once __DIR__ . '/stock.php';
