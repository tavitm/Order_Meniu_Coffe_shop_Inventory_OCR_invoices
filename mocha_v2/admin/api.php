<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
date_default_timezone_set('Europe/Bucharest');

const ROOT = __DIR__ . '/..';
const MENU_FILE = ROOT . '/data/menu.json';
const BACKUP_DIR = ROOT . '/data/backups';
const UPLOAD_DIR = ROOT . '/uploads';

session_name('mocha_admin');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => dirname($_SERVER['SCRIPT_NAME']) . '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $msg, int $code = 400): never
{
    out(['ok' => false, 'error' => $msg], $code);
}

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

function clean_str(mixed $v, int $max = 500): string
{
    if (!is_string($v) && !is_int($v) && !is_float($v)) return '';
    $v = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string)$v) ?? '');
    return mb_substr($v, 0, $max);
}

function clean_path(mixed $v): string
{
    $v = clean_str($v, 300);
    if ($v === '') return '';
    if (!preg_match('#^(uploads|assets)/[A-Za-z0-9._-]+$#', $v)) fail('Cale de imagine invalidă: ' . $v);
    return $v;
}

function clean_id(mixed $v, string $prefix): string
{
    $v = clean_str($v, 40);
    return preg_match('/^[A-Za-z0-9_-]+$/', $v) ? $v : $prefix . bin2hex(random_bytes(4));
}

function clean_menu(array $in): array
{
    $s = $in['settings'] ?? [];
    $b = $s['banner'] ?? [];
    $f = $s['footer'] ?? [];
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
    return ['menu' => $menu, 'before' => $before];
}

function menu_images(array $menu): array
{
    $paths = [$menu['settings']['banner']['image'] ?? ''];
    foreach ($menu['categories'] ?? [] as $c) foreach ($c['items'] ?? [] as $i) $paths[] = $i['image'] ?? '';
    return array_values(array_unique(array_filter($paths, fn($p) => is_string($p) && preg_match('#^uploads/[A-Za-z0-9._-]+$#', $p))));
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'status':
        out(['ok' => true, 'loggedIn' => logged_in(), 'csrf' => logged_in() ? $_SESSION['csrf'] : null, 'configured' => mb_strlen(ADMIN_PASSWORD) >= 10]);

    case 'login':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Metodă invalidă.', 405);
        if (mb_strlen(ADMIN_PASSWORD) < 10) fail('Parola nu este setată. Editează admin/config.php (minimum 10 caractere).', 503);
        $now = time();
        $_SESSION['fails'] = array_values(array_filter($_SESSION['fails'] ?? [], fn($t) => $t > $now - 900));
        if (count($_SESSION['fails']) >= 5) fail('Prea multe încercări. Așteaptă 15 minute.', 429);
        $body = json_decode(file_get_contents('php://input') ?: '', true);
        $pass = is_array($body) && is_string($body['password'] ?? null) ? $body['password'] : '';
        if (!hash_equals(ADMIN_PASSWORD, $pass)) {
            $_SESSION['fails'][] = $now;
            sleep(1);
            fail('Parolă greșită.', 401);
        }
        session_regenerate_id(true);
        $_SESSION = ['auth' => true, 'csrf' => bin2hex(random_bytes(32))];
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

    default:
        fail('Acțiune necunoscută.', 404);
}
