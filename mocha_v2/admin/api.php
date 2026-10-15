<?php
declare(strict_types=1);
require __DIR__ . '/../lib/common.php';

const ROOT = __DIR__ . '/..';
const BACKUP_DIR = ROOT . '/data/backups';
const UPLOAD_DIR = ROOT . '/uploads';

start_session('mocha_admin', 90 * 24 * 3600, 24 * 3600); // maximum 3 luni, dar expiră după 24 h fără nicio cerere
maybe_auto_close();

function logged_in(): bool
{
    return !empty($_SESSION['auth']);
}

function require_auth(): void
{
    if (!logged_in()) fail('Sesiunea a expirat. Autentifică-te din nou.', 401);
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf'] ?? '', $token)) fail('Cerere invalidă (token).', 403);
}

// Only image files directly inside uploads/ or assets/ (no dotfiles, no other extensions).
const IMAGE_PATH = '#^(uploads|assets)/[A-Za-z0-9_-][A-Za-z0-9._-]*\.(webp|png|jpe?g|gif|svg)$#i';

function clean_path(mixed $v): string
{
    $v = clean_str($v, 300);
    if ($v === '') return '';
    if (!preg_match(IMAGE_PATH, $v)) fail('Cale de imagine invalidă: ' . $v);
    return $v;
}

function clean_id(mixed $v, string $prefix): string
{
    $v = clean_str($v, 40);
    return preg_match('/^[A-Za-z0-9_-]+$/', $v) ? $v : $prefix . bin2hex(random_bytes(4));
}

/** Waiter names are public (the login buttons), so the password alone protects the account: refuse the guessable ones. */
function check_waiter_password(string $pass, string $name): void
{
    if (mb_strlen($pass) < 8) fail('Parola trebuie să aibă minimum 8 caractere.');
    $low = mb_strtolower($pass);
    $common = ['12345678', '123456789', '1234567890', '87654321', 'password', 'password1', 'parola123', 'parola1234',
        'qwertyui', 'qwerty123', 'abcdefgh', 'iloveyou', 'ospatar1', 'ospatar123', 'mocha123', 'mocha1234', 'mocha2026'];
    $trivial = in_array($low, $common, true)
        || preg_match('/^(.)\1+$/u', $low)                        // același caracter repetat
        || str_contains('01234567890123456789', $low)             // cifre în ordine (12345678, 23456789…)
        || str_contains('09876543210987654321', $low)
        || (mb_strlen($name) >= 3 && str_contains($low, mb_strtolower($name)));
    if ($trivial) fail('Parola este prea ușor de ghicit. Alege alta: nu numele ospătarului, nu cifre în ordine.');
}

function clean_menu(array $in): array
{
    $s = $in['settings'] ?? [];
    $b = $s['banner'] ?? [];
    $f = $s['footer'] ?? [];
    $o = $s['orders'] ?? [];
    $menu = [
        'settings' => [
            'title' => clean_str($s['title'] ?? '', 120),
            'currency' => clean_str($s['currency'] ?? 'lei', 10),
            'logo' => clean_path($s['logo'] ?? ''),
            'banner' => [
                'visible' => (bool)($b['visible'] ?? false),
                'image' => clean_path($b['image'] ?? ''),
                'alt' => clean_str($b['alt'] ?? '', 200),
            ],
            'footer' => [
                'days' => clean_str($f['days'] ?? '', 120),
                'hours' => clean_str($f['hours'] ?? '', 120),
            ],
            'orders' => [
                'enabled' => (bool)($o['enabled'] ?? true),
                'tables' => max(1, min(500, (int)($o['tables'] ?? 20))),
                'stockCheck' => (bool)($o['stockCheck'] ?? false),
            ],
        ],
        'categories' => [],
    ];
    foreach (array_slice($in['categories'] ?? [], 0, 200) as $c) {
        if (!is_array($c)) continue;
        $cat = [
            'id' => clean_id($c['id'] ?? '', 'c'),
            'name' => clean_str($c['name'] ?? '', 120),
            'visible' => (bool)($c['visible'] ?? true),
            'items' => [],
        ];
        foreach (array_slice($c['items'] ?? [], 0, 500) as $i) {
            if (!is_array($i)) continue;
            $price = clean_str($i['price'] ?? '', 20);
            $price = str_replace(',', '.', $price);
            if ($price !== '' && !preg_match('/^\d+(\.\d{1,2})?$/', $price)) fail('Preț invalid la „' . clean_str($i['name'] ?? '', 80) . '”: ' . $price);
            $cat['items'][] = [
                'id' => clean_id($i['id'] ?? '', 'p'),
                'name' => clean_str($i['name'] ?? '', 160),
                'subheader' => clean_str($i['subheader'] ?? '', 120),
                'description' => clean_str($i['description'] ?? '', 1000),
                'price' => $price,
                'image' => clean_path($i['image'] ?? ''),
                'visible' => (bool)($i['visible'] ?? true),
            ];
        }
        $menu['categories'][] = $cat;
    }
    return $menu;
}

/** New menu products get their inventory row right away. The menu is already saved, so a failure here is only logged. */
function sync_stock(array $menu): void
{
    try { stock_sync_menu($menu); } catch (Throwable $e) { error_log('[mocha] stock sync: ' . $e); }
}

function write_atomic(string $file, string $content): void
{
    $tmp = $file . '.tmp-' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $content, LOCK_EX) === false || !rename($tmp, $file)) {
        @unlink($tmp);
        fail('Nu am putut scrie fișierul. Verifică permisiunile folderului data/.', 500);
    }
}

function backup_menu(): void
{
    if (!is_file(MENU_FILE)) return;
    if (!is_dir(BACKUP_DIR)) @mkdir(BACKUP_DIR, 0755, true);
    @copy(MENU_FILE, BACKUP_DIR . '/menu-' . date('Ymd-His') . '.json');
    $files = glob(BACKUP_DIR . '/menu-*.json') ?: [];
    sort($files);
    foreach (array_slice($files, 0, max(0, count($files) - BACKUPS_TO_KEEP)) as $old) @unlink($old);
}

// Backup files: menu-* (automatic, on every save), manual-* (created by hand), restore-* (state before a restore).
const BACKUP_NAME = '/^(menu|manual|restore)-(\d{8})-(\d{6})(?:-[0-9a-f]{4})?(?:--([a-z0-9-]{1,40}))?\.json$/';
const BACKUP_FORMAT = 'mocha-menu-backup';
const IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

function backup_file(mixed $name): string
{
    if (!is_string($name) || !preg_match(BACKUP_NAME, $name)) fail('Backup invalid.');
    $file = BACKUP_DIR . '/' . $name;
    if (!is_file($file)) fail('Backup-ul nu mai există.', 404);
    return $file;
}

function read_menu_file(string $file): array
{
    $menu = json_decode((string)@file_get_contents($file), true);
    if (!is_array($menu) || !isset($menu['categories']) || !is_array($menu['categories'])) fail('Fișierul de backup este deteriorat.');
    return $menu;
}

function summary(array $menu): array
{
    $cats = $menu['categories'] ?? [];
    return [
        'categories' => count($cats),
        'hiddenCategories' => count(array_filter($cats, fn($c) => ($c['visible'] ?? true) === false)),
        'products' => array_sum(array_map(fn($c) => count($c['items'] ?? []), $cats)),
        'banner' => $menu['settings']['banner']['image'] ?? '',
    ];
}

function new_backup(string $prefix, string $label = ''): string
{
    if (!is_file(MENU_FILE)) fail('Nu există încă un meniu salvat.');
    if (!is_dir(BACKUP_DIR)) @mkdir(BACKUP_DIR, 0755, true);
    $ascii = strtr($label, ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't', 'Ă' => 'a', 'Â' => 'a', 'Î' => 'i', 'Ș' => 's', 'Ş' => 's', 'Ț' => 't', 'Ţ' => 't']);
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)) ?? '', '-');
    $suffix = $slug !== '' ? '--' . substr($slug, 0, 40) : '';
    $name = $prefix . '-' . date('Ymd-His') . $suffix . '.json';
    while (is_file(BACKUP_DIR . '/' . $name)) $name = $prefix . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2)) . $suffix . '.json';
    if (!@copy(MENU_FILE, BACKUP_DIR . '/' . $name)) fail('Nu am putut crea backup-ul. Verifică permisiunile folderului data/backups/.', 500);
    if ($prefix === 'restore') {
        $files = glob(BACKUP_DIR . '/restore-*.json') ?: [];
        sort($files);
        foreach (array_slice($files, 0, max(0, count($files) - BACKUPS_TO_KEEP)) as $old) @unlink($old);
    }
    return $name;
}

function restore_menu(array $in): array
{
    $menu = clean_menu($in);
    $before = is_file(MENU_FILE) ? new_backup('restore') : null;
    write_atomic(MENU_FILE, json_encode($menu, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    sync_stock($menu);
    return ['menu' => $menu, 'before' => $before];
}

function menu_images(array $menu): array
{
    $paths = [$menu['settings']['banner']['image'] ?? ''];
    foreach ($menu['categories'] ?? [] as $c) foreach ($c['items'] ?? [] as $i) $paths[] = $i['image'] ?? '';
    return array_values(array_unique(array_filter($paths, fn($p) => is_string($p) && str_starts_with($p, 'uploads/') && preg_match(IMAGE_PATH, $p))));
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'status':
        out(['ok' => true, 'loggedIn' => logged_in(), 'csrf' => logged_in() ? $_SESSION['csrf'] : null, 'configured' => mb_strlen(ADMIN_PASSWORD) >= 10]);

    case 'login':
        password_login(ADMIN_PASSWORD, 'Parola nu este setată. Editează admin/config.php (minimum 10 caractere).');
        out(['ok' => true, 'csrf' => $_SESSION['csrf']]);

    case 'logout':
        $_SESSION = [];
        session_destroy();
        out(['ok' => true]);

    case 'save':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Metodă invalidă.', 405);
        require_auth();
        $raw = file_get_contents('php://input') ?: '';
        if (strlen($raw) > 2_000_000) fail('Meniul este prea mare.');
        $in = json_decode($raw, true);
        if (!is_array($in) || !isset($in['categories']) || !is_array($in['categories'])) fail('Date invalide.');
        $menu = clean_menu($in);
        backup_menu();
        write_atomic(MENU_FILE, json_encode($menu, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        sync_stock($menu);
        out(['ok' => true, 'menu' => $menu]);

    case 'upload':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Metodă invalidă.', 405);
        require_auth();
        $f = $_FILES['image'] ?? null;
        if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) {
            $err = $f['error'] ?? -1;
            fail(in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'Imaginea depășește limita serverului.' : 'Încărcarea a eșuat.');
        }
        if ($f['size'] > MAX_UPLOAD_MB * 1024 * 1024) fail('Imaginea depășește ' . MAX_UPLOAD_MB . ' MB.');
        $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        if (!isset($types[$mime]) || @getimagesize($f['tmp_name']) === false) fail('Sunt acceptate doar imagini JPG, PNG, WEBP sau GIF.');
        $base = strtolower(pathinfo($f['name'], PATHINFO_FILENAME));
        $base = trim(preg_replace('/[^a-z0-9]+/', '-', $base) ?? '', '-') ?: 'imagine';
        $name = substr($base, 0, 40) . '-' . bin2hex(random_bytes(3)) . '.' . $types[$mime];
        if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0755, true);
        if (!move_uploaded_file($f['tmp_name'], UPLOAD_DIR . '/' . $name)) fail('Nu am putut salva imaginea. Verifică permisiunile folderului uploads/.', 500);
        out(['ok' => true, 'path' => 'uploads/' . $name]);

    case 'backups':
        require_auth();
        $list = [];
        foreach (glob(BACKUP_DIR . '/*.json') ?: [] as $file) {
            $name = basename($file);
            if (!preg_match(BACKUP_NAME, $name, $m)) continue;
            $menu = json_decode((string)@file_get_contents($file), true);
            $date = DateTime::createFromFormat('Ymd His', $m[2] . ' ' . $m[3]);
            $list[] = [
                'name' => $name,
                'type' => $m[1],
                'label' => str_replace('-', ' ', $m[4] ?? ''),
                'date' => $date ? $date->format('Y-m-d H:i:s') : '',
                'size' => filesize($file),
                'summary' => is_array($menu) ? summary($menu) : null,
            ];
        }
        usort($list, fn($a, $b) => strcmp($b['date'], $a['date']));
        out(['ok' => true, 'backups' => $list, 'current' => is_file(MENU_FILE) ? summary(read_menu_file(MENU_FILE)) : null]);

    case 'backup_create':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Metodă invalidă.', 405);
        require_auth();
        $body = json_decode(file_get_contents('php://input') ?: '', true);
        $name = new_backup('manual', clean_str(is_array($body) ? ($body['label'] ?? '') : '', 60));
        out(['ok' => true, 'name' => $name]);

    case 'backup_restore':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Metodă invalidă.', 405);
        require_auth();
        $body = json_decode(file_get_contents('php://input') ?: '', true);
        $menu = read_menu_file(backup_file(is_array($body) ? ($body['name'] ?? '') : ''));
        foreach (menu_images($menu) as $p) {
            if (!is_file(ROOT . '/' . $p)) fail('Imaginea ' . $p . ' din acest backup nu mai există pe server. Restaurează dintr-un fișier descărcat (conține imaginile).');
        }
        out(['ok' => true] + restore_menu($menu));

    case 'backup_delete':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Metodă invalidă.', 405);
        require_auth();
        $body = json_decode(file_get_contents('php://input') ?: '', true);
        if (!@unlink(backup_file(is_array($body) ? ($body['name'] ?? '') : ''))) fail('Nu am putut șterge backup-ul.', 500);
        out(['ok' => true]);

    case 'backup_download':
        // Bundle = menu data + the images it uses, so it can be restored even if uploads/ was lost.
        require_auth();
        $name = $_GET['name'] ?? '';
        $menu = read_menu_file($name === '' ? MENU_FILE : backup_file($name));
        $images = [];
        foreach (menu_images($menu) as $p) {
            $data = @file_get_contents(ROOT . '/' . $p);
            if ($data !== false) $images[$p] = base64_encode($data);
        }
        out(['ok' => true, 'bundle' => [
            'format' => BACKUP_FORMAT,
            'version' => 1,
            'created' => date('c'),
            'source' => $name === '' ? 'current' : $name,
            'menu' => $menu,
            'images' => $images,
        ]]);

    case 'backup_import':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Metodă invalidă.', 405);
        require_auth();
        $raw = file_get_contents('php://input') ?: '';
        if ($raw === '') fail('Fișierul este gol sau depășește limita serverului (post_max_size).');
        $in = json_decode($raw, true);
        if (!is_array($in)) fail('Fișierul nu este un backup valid.');
        // Accept the downloaded bundle, or a plain menu.json copy.
        if (($in['format'] ?? '') === BACKUP_FORMAT) {
            $menu = $in['menu'] ?? null;
            $images = is_array($in['images'] ?? null) ? $in['images'] : [];
        } else {
            $menu = $in;
            $images = [];
        }
        if (!is_array($menu) || !isset($menu['categories']) || !is_array($menu['categories'])) fail('Fișierul nu conține un meniu valid.');
        $menu = clean_menu($menu);
        $restored = 0;
        $missing = [];
        foreach (menu_images($menu) as $p) {
            if (is_file(ROOT . '/' . $p)) continue;
            $data = isset($images[$p]) && is_string($images[$p]) ? base64_decode($images[$p], true) : false;
            $mime = $data !== false ? (new finfo(FILEINFO_MIME_TYPE))->buffer($data) : '';
            $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
            $okExt = isset(IMAGE_TYPES[$mime]) && ($ext === IMAGE_TYPES[$mime] || ($ext === 'jpeg' && $mime === 'image/jpeg'));
            if ($data === false || !$okExt || strlen($data) > MAX_UPLOAD_MB * 1024 * 1024 || @getimagesizefromstring($data) === false) {
                $missing[] = $p;
                continue;
            }
            if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0755, true);
            if (@file_put_contents(ROOT . '/' . $p, $data, LOCK_EX) === false) fail('Nu am putut salva imaginile. Verifică permisiunile folderului uploads/.', 500);
            $restored++;
        }
        out(['ok' => true, 'imagesRestored' => $restored, 'imagesMissing' => $missing] + restore_menu($menu));

    case 'stats':
        require_auth();
        $cfg = auto_close_config();
        $next = null;
        if ($cfg['enabled']) {
            $next = date('Y-m-d') . ' ' . $cfg['time'] . ':00';
            if ($next <= now()) $next = date('Y-m-d', strtotime('+1 day')) . ' ' . $cfg['time'] . ':00';
        }
        out(['ok' => true, 'stats' => day_stats(), 'autoClose' => $cfg + ['next' => $next], 'currency' => (string)(load_menu()['settings']['currency'] ?? 'lei')]);

    case 'auto_close_save':
        require_post();
        require_auth();
        $body = json_body();
        $time = is_string($body['time'] ?? null) ? $body['time'] : '';
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) fail('Ora trebuie să fie în formatul HH:MM.');
        set_setting('auto_close_enabled', !empty($body['enabled']) ? '1' : '0');
        set_setting('auto_close_time', $time);
        // Do not retro-close past days when enabling or changing the time: start counting from the next occurrence.
        set_setting('auto_close_done', last_scheduled_close($time));
        out(['ok' => true]);

    case 'close_day':
        require_post();
        require_auth();
        $res = close_day();
        if (!$res) fail('Nu există comenzi plătite sau anulate de la ultima închidere.');
        out(['ok' => true] + $res);

    case 'reports':
        require_auth();
        $rows = db()->query('SELECT id, opened_at, closed_at, report, auto FROM days ORDER BY id DESC LIMIT 200')->fetchAll();
        out(['ok' => true, 'reports' => array_map(function ($r) {
            $rep = json_decode($r['report'], true) ?: [];
            return ['id' => (int)$r['id'], 'from' => $r['opened_at'], 'to' => $r['closed_at'], 'auto' => (bool)$r['auto'], 'paid' => $rep['paid'] ?? null, 'cancelled' => $rep['cancelled'] ?? null];
        }, $rows)]);

    case 'report':
        require_auth();
        $q = db()->prepare('SELECT id, report FROM days WHERE id = ?');
        $q->execute([(int)($_GET['id'] ?? 0)]);
        $r = $q->fetch();
        if (!$r) fail('Raportul nu există.', 404);
        out(['ok' => true, 'id' => (int)$r['id'], 'report' => json_decode($r['report'], true), 'currency' => (string)(load_menu()['settings']['currency'] ?? 'lei')]);

    /* ---------- waiters ---------- */

    case 'waiters':
        require_auth();
        $db = db();
        $list = waiter_list(true);
        foreach ($list as &$w) {
            $q = $db->prepare('SELECT id, started_at FROM shifts WHERE waiter_id = ? AND ended_at IS NULL ORDER BY id DESC LIMIT 1');
            $q->execute([$w['id']]);
            $open = $q->fetch();
            $w['open_shift'] = $open ? ['id' => (int)$open['id'], 'from' => $open['started_at']] : null;
        }
        out(['ok' => true, 'waiters' => $list, 'max' => MAX_WAITERS]);

    case 'waiter_save':
        require_post();
        require_auth();
        $body = json_body();
        $db = db();
        $id = (int)($body['id'] ?? 0);
        $name = clean_str($body['name'] ?? '', 40);
        $pass = is_string($body['password'] ?? null) ? $body['password'] : '';
        $active = !array_key_exists('active', $body) || !empty($body['active']);
        if ($name === '') fail('Scrie numele ospătarului.');
        $q = $db->prepare('SELECT COUNT(*) FROM waiters WHERE deleted = 0 AND name = ? COLLATE NOCASE AND id <> ?');
        $q->execute([$name, $id]);
        if ((int)$q->fetchColumn() > 0) fail('Există deja un ospătar cu acest nume.');
        if ($pass !== '') check_waiter_password($pass, $name);
        if ($id === 0) {
            if ((int)$db->query('SELECT COUNT(*) FROM waiters WHERE deleted = 0')->fetchColumn() >= MAX_WAITERS) fail('Poți avea maximum ' . MAX_WAITERS . ' ospătari. Șterge unul înainte să adaugi altul.');
            if ($pass === '') fail('Setează o parolă pentru ospătarul nou.');
            $db->prepare('INSERT INTO waiters (name, pass_hash, active, created_at) VALUES (?, ?, ?, ?)')
                ->execute([$name, password_hash($pass, PASSWORD_DEFAULT), $active ? 1 : 0, now()]);
        } else {
            $q = $db->prepare('UPDATE waiters SET name = ?, active = ? WHERE id = ? AND deleted = 0');
            $q->execute([$name, $active ? 1 : 0, $id]);
            if ($q->rowCount() === 0) {
                $q = $db->prepare('SELECT 1 FROM waiters WHERE deleted = 0 AND id = ?');
                $q->execute([$id]);
                if (!$q->fetchColumn()) fail('Ospătarul nu există.', 404);
            }
            if ($pass !== '') $db->prepare('UPDATE waiters SET pass_hash = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
        }
        out(['ok' => true]);

    case 'waiter_delete':
        // History stays in reports: the waiter is hidden, not removed.
        require_post();
        require_auth();
        $id = (int)(json_body()['id'] ?? 0);
        $db = db();
        $q = $db->prepare('SELECT COUNT(*) FROM shifts WHERE waiter_id = ? AND ended_at IS NULL');
        $q->execute([$id]);
        if ((int)$q->fetchColumn() > 0) fail('Ospătarul are o tură deschisă. Închide tura înainte de ștergere.');
        $q = $db->prepare('UPDATE waiters SET deleted = 1, active = 0 WHERE id = ? AND deleted = 0');
        $q->execute([$id]);
        if ($q->rowCount() === 0) fail('Ospătarul nu există.', 404);
        out(['ok' => true]);

    case 'shifts':
        require_auth();
        $waiter = (int)($_GET['waiter'] ?? 0);
        $sql = 'SELECT s.id, s.waiter_id, w.name, s.started_at, s.ended_at, s.closed_by, s.report FROM shifts s JOIN waiters w ON w.id = s.waiter_id'
            . ($waiter ? ' WHERE s.waiter_id = ?' : '') . ' ORDER BY s.id DESC LIMIT 200';
        $q = db()->prepare($sql);
        $q->execute($waiter ? [$waiter] : []);
        out(['ok' => true, 'shifts' => array_map(function ($s) {
            $rep = $s['report'] ? json_decode($s['report'], true) : shift_stats((int)$s['id']);
            return ['id' => (int)$s['id'], 'waiter_id' => (int)$s['waiter_id'], 'waiter' => $s['name'], 'from' => $s['started_at'], 'to' => $s['ended_at'],
                'closed_by' => $s['closed_by'], 'paid' => $rep['paid'], 'cash' => $rep['cash'], 'card' => $rep['card'], 'cancelled' => $rep['cancelled']];
        }, $q->fetchAll())]);

    case 'shift_report':
        require_auth();
        $q = db()->prepare('SELECT report FROM shifts WHERE id = ?');
        $q->execute([(int)($_GET['id'] ?? 0)]);
        $r = $q->fetchColumn();
        if ($r === false) fail('Tura nu există.', 404);
        out(['ok' => true, 'report' => $r ? json_decode($r, true) : shift_stats((int)$_GET['id'])]);

    case 'shift_close':
        require_post();
        require_auth();
        out(['ok' => true, 'report' => close_shift((int)(json_body()['id'] ?? 0), 'admin')]);

    /* ---------- photographed invoices ---------- */

    case 'invoices_info':
        require_auth();
        [$from, $to] = invoice_period($_GET);
        out(['ok' => true] + invoice_info($from, $to));

    case 'invoices_zip':
        if (!logged_in()) { http_response_code(401); exit('Autentifică-te în administrare.'); }
        [$from, $to] = invoice_period($_GET);
        invoice_zip($from, $to);

    case 'invoices_delete':
        // Frees disk space: only the photo files go; receipts, values and stock stay.
        require_post();
        require_auth();
        [$from, $to] = invoice_period(json_body());
        [$files, $bytes] = invoice_delete($from, $to);
        out(['ok' => true, 'files' => $files, 'bytes' => $bytes] + invoice_info('', ''));

    default:
        fail('Acțiune necunoscută.', 404);
}
