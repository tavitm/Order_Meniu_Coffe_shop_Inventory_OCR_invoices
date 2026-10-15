<?php
declare(strict_types=1);
// Inventory API: stock rows, goods receipts, stock-outs, stock counts and the movement log.
// Same password as the admin page, with its own session (cookie limited to this folder).
require __DIR__ . '/../lib/common.php';

start_session('mocha_inventory', 90 * 24 * 3600, 24 * 3600); // ca la admin: maximum 3 luni, expiră după 24 h fără activitate
maybe_auto_close();
// A manager whose password was changed or removed in Administrare → Angajați is logged out at the next request.
if (($_SESSION['role'] ?? '') === 'manager' && (manager_hash() === '' || (int)($_SESSION['login_at'] ?? 0) < (int)setting('manager_pass_set_at', '0'))) {
    $_SESSION = [];
}

/** Quantity field: $allowZero for a stock count (the shelf may be empty). */
function qty_field(mixed $v, string $label, bool $allowZero = false): int
{
    $q = stock_qty($v);
    if ($q === null) fail($label . ': scrie un număr (ex. 12 sau 3,5), cu cel mult 3 zecimale.');
    if ($q === 0 && !$allowZero) fail($label . ': cantitatea trebuie să fie mai mare decât 0.');
    return $q;
}

function min_field(mixed $v): int
{
    if ($v === null || $v === '') return 0;
    return qty_field($v, 'Stoc minim', true);
}

/** Units of the article in one box / case / pack ("24"); empty = not known. */
function pack_field(mixed $v): ?int
{
    if ($v === null || $v === '') return null;
    return qty_field($v, 'Bax/cutie');
}

function unit_field(mixed $v): string
{
    $u = clean_str($v, 12);
    return $u === '' ? 'buc' : $u;
}

const MAX_PHOTOS = 8;
const MAX_PHOTO_MB = 10;

/** Request body: JSON, or the "data" field of a multipart form (a receipt sent together with invoice photos). */
function body(): array
{
    if (isset($_POST['data']) && is_string($_POST['data'])) {
        if (strlen($_POST['data']) > 500_000) fail('Cerere prea mare.');
        $data = json_decode($_POST['data'], true);
        return is_array($data) ? $data : [];
    }
    return json_body();
}

/** Uploaded invoice photos, checked by content (not by name), moved into data/invoices/. Returns the stored file names. */
function store_photos(): array
{
    $f = $_FILES['photos'] ?? null;
    if (!$f || !is_array($f['name'] ?? null)) return [];
    $count = count($f['name']);
    if ($count > MAX_PHOTOS) fail('Cel mult ' . MAX_PHOTOS . ' poze pe o recepție.');
    $ready = [];
    for ($i = 0; $i < $count; $i++) {
        if (($f['error'][$i] ?? 1) !== UPLOAD_ERR_OK) fail('Poza ' . ($i + 1) . ' nu a ajuns pe server (prea mare sau conexiune întreruptă).');
        if ($f['size'][$i] > MAX_PHOTO_MB * 1024 * 1024) fail('Poza ' . ($i + 1) . ' depășește ' . MAX_PHOTO_MB . ' MB.');
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name'][$i]);
        if (!isset(PHOTO_TYPES[$mime]) || @getimagesize($f['tmp_name'][$i]) === false) fail('Poza ' . ($i + 1) . ' nu este o imagine JPG, PNG sau WEBP.');
        $ready[] = [$f['tmp_name'][$i], PHOTO_TYPES[$mime]];
    }
    if (!is_dir(INVOICE_DIR) && !@mkdir(INVOICE_DIR, 0755, true)) fail('Nu pot crea folderul data/invoices. Verifică permisiunile folderului data/.', 500);
    if (!is_file(INVOICE_DIR . '/.htaccess')) @file_put_contents(INVOICE_DIR . '/.htaccess', "Require all denied\n");
    $names = [];
    foreach ($ready as $n => [$tmp, $ext]) {
        $name = 'factura-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '-' . ($n + 1) . '.' . $ext;
        if (!move_uploaded_file($tmp, INVOICE_DIR . '/' . $name)) {
            foreach ($names as $done) @unlink(INVOICE_DIR . '/' . $done);
            fail('Nu am putut salva pozele. Verifică permisiunile folderului data/.', 500);
        }
        $names[] = $name;
    }
    return $names;
}

/** "2026-10-15" or "" (the invoice date is optional). */
function date_field(mixed $v): ?string
{
    $v = clean_str($v, 10);
    if ($v === '') return null;
    $d = DateTime::createFromFormat('!Y-m-d', $v);
    if (!$d || $d->format('Y-m-d') !== $v) fail('Data facturii nu este validă.');
    return $v;
}

/** Line value in lei ("120,50") -> bani; null when not given. */
function cost_field(mixed $v, string $label): ?int
{
    if ($v === null || $v === '') return null;
    $c = is_string($v) || is_int($v) || is_float($v) ? to_cents((string)$v) : null;
    if ($c === null || $c > 100_000_000_00) fail($label . ': valoarea trebuie să fie un număr (ex. 120,50).');
    return $c;
}

function receipt_row(array $r): array
{
    return [
        'id' => (int)$r['id'], 'supplier' => $r['supplier'], 'doc_no' => $r['doc_no'], 'doc_date' => $r['doc_date'], 'note' => $r['note'],
        'photos' => count(json_decode($r['photos'], true) ?: []), 'created_at' => $r['created_at'],
        'lines' => (int)($r['lines'] ?? 0), 'total' => $r['total'] !== null ? (int)$r['total'] : null,
    ];
}

/** An item that can still receive movements. */
function active_item(mixed $id): array
{
    $item = stock_item((int)$id);
    if ((int)$item['archived']) fail('„' . $item['name'] . '” este arhivat. Restaurează-l înainte de a-i modifica stocul.');
    return $item;
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'status':
        $in = !empty($_SESSION['auth']);
        out(['ok' => true, 'loggedIn' => $in, 'csrf' => $in ? $_SESSION['csrf'] : null, 'role' => $in ? ($_SESSION['role'] ?? 'admin') : null,
            'configured' => admin_account() !== '' || manager_hash() !== '']);

    case 'login':
        // The admin password or the inventory manager's password; the manager can only ever get in here.
        $role = password_login(['admin' => admin_account(), 'manager' => manager_hash()],
            'Nu există nicio parolă pentru inventar: setează parola de admin în admin/config.php sau o parolă de gestionar din Administrare → Angajați.', 'inventar');
        out(['ok' => true, 'csrf' => $_SESSION['csrf'], 'role' => $role]);

    case 'logout':
        $_SESSION = [];
        session_destroy();
        out(['ok' => true]);

    case 'list':
        require_session_auth();
        stock_sync_menu();
        $aliases = db()->query('SELECT a.text, a.item_id, a.factor FROM stock_aliases a JOIN stock_items s ON s.id = a.item_id WHERE s.archived = 0')->fetchAll();
        out(['ok' => true, 'items' => stock_list(), 'aliases' => array_map(fn($a) => ['text' => $a['text'], 'item' => (int)$a['item_id'], 'factor' => (int)$a['factor']], $aliases)]);

    case 'item_save':
        // Menu products: only unit, minimum and tracking (name and group come from the menu).
        // Ingredients / consumables: everything; id 0 creates a new one, optionally with an opening stock.
        require_post();
        require_session_auth();
        $b = json_body();
        $id = (int)($b['id'] ?? 0);
        $unit = unit_field($b['unit'] ?? '');
        $min = min_field($b['min'] ?? '');
        $item = $id ? stock_item($id) : null;
        $pack = array_key_exists('pack', $b) ? pack_field($b['pack']) : ($item && $item['pack'] !== null ? (int)$item['pack'] : null);
        if ($item && $item['product_id'] !== null) {
            $tracked = array_key_exists('tracked', $b) ? !empty($b['tracked']) : (bool)$item['tracked'];
            db()->prepare('UPDATE stock_items SET unit = ?, min_qty = ?, tracked = ?, pack = ? WHERE id = ?')->execute([$unit, $min, $tracked ? 1 : 0, $pack, $id]);
            out(['ok' => true, 'item' => stock_row(stock_item($id))]);
        }
        $name = clean_str($b['name'] ?? '', 80);
        $grp = clean_str($b['grp'] ?? '', 40) ?: 'Ingrediente';
        if ($name === '') fail('Scrie numele articolului.');
        $q = db()->prepare('SELECT COUNT(*) FROM stock_items WHERE product_id IS NULL AND archived = 0 AND name = ? COLLATE NOCASE AND id <> ?');
        $q->execute([$name, $id]);
        if ((int)$q->fetchColumn() > 0) fail('Există deja un articol cu numele „' . $name . '”.');
        $tracked = !array_key_exists('tracked', $b) || !empty($b['tracked']);
        $initial = ($b['initial'] ?? '') === '' ? null : qty_field($b['initial'], 'Stoc inițial', true);
        $id = in_transaction(function (PDO $db) use ($id, $name, $grp, $unit, $min, $tracked, $initial, $pack) {
            if ($id) {
                $db->prepare('UPDATE stock_items SET name = ?, grp = ?, unit = ?, min_qty = ?, tracked = ?, pack = ? WHERE id = ?')
                    ->execute([$name, $grp, $unit, $min, $tracked ? 1 : 0, $pack, $id]);
                return $id;
            }
            $db->prepare('INSERT INTO stock_items (product_id, name, grp, unit, min_qty, tracked, pack, created_at) VALUES (NULL, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$name, $grp, $unit, $min, $tracked ? 1 : 0, $pack, now()]);
            $new = (int)$db->lastInsertId();
            if ($initial !== null) stock_apply($new, $initial, 'count', '', 'Stoc inițial');
            return $new;
        });
        out(['ok' => true, 'item' => stock_row(stock_item($id))]);

    case 'item_delete':
        // Ingredients without history are deleted; with history they are archived (the movement log stays complete).
        require_post();
        require_session_auth();
        $item = stock_item((int)(json_body()['id'] ?? 0));
        if ($item['product_id'] !== null) fail('Produsele din meniu se șterg din administrarea meniului.');
        $q = db()->prepare('SELECT COUNT(*) FROM stock_moves WHERE item_id = ?');
        $q->execute([$item['id']]);
        if ((int)$q->fetchColumn() > 0) {
            db()->prepare('UPDATE stock_items SET archived = 1 WHERE id = ?')->execute([$item['id']]);
            out(['ok' => true, 'archived' => true]);
        }
        db()->prepare('DELETE FROM stock_items WHERE id = ?')->execute([$item['id']]);
        out(['ok' => true, 'archived' => false]);

    case 'item_restore':
        require_post();
        require_session_auth();
        $item = stock_item((int)(json_body()['id'] ?? 0));
        if ($item['product_id'] !== null) fail('Produsul revine automat când este adăugat din nou în meniu.');
        $q = db()->prepare('SELECT COUNT(*) FROM stock_items WHERE product_id IS NULL AND archived = 0 AND name = ? COLLATE NOCASE');
        $q->execute([$item['name']]);
        if ((int)$q->fetchColumn() > 0) fail('Există deja un articol activ cu numele „' . $item['name'] . '”.');
        db()->prepare('UPDATE stock_items SET archived = 0 WHERE id = ?')->execute([$item['id']]);
        out(['ok' => true, 'item' => stock_row(stock_item((int)$item['id']))]);

    case 'receive':
        // Goods receipt: one invoice (or delivery note) with its lines, optionally with the invoice photos.
        // Lines read from a photo carry the invoice wording ("src") and the unit conversion, which are remembered for next time.
        require_post();
        require_session_auth();
        $b = body();
        $supplier = clean_str($b['supplier'] ?? '', 80);
        $docNo = clean_str($b['doc_no'] ?? '', 40);
        $docDate = date_field($b['doc_date'] ?? '');
        $note = clean_str($b['note'] ?? '', 200);
        $doc = trim(($docNo !== '' ? 'Factura ' . $docNo : clean_str($b['doc'] ?? '', 60)) . ($supplier !== '' ? ' · ' . $supplier : ''), ' ·');
        $lines = is_array($b['lines'] ?? null) ? $b['lines'] : [];
        if (!$lines) fail('Adaugă cel puțin un articol.');
        if (count($lines) > 200) fail('Prea multe rânduri într-o singură recepție (maximum 200).');
        $todo = [];
        foreach (array_values($lines) as $n => $l) {
            if (!is_array($l)) fail('Rândul ' . ($n + 1) . ' este invalid.');
            $item = active_item($l['item'] ?? 0);
            $label = 'Rândul ' . ($n + 1) . ' („' . $item['name'] . '”)';
            $src = clean_str($l['src'] ?? '', 120);
            $factor = ($l['factor'] ?? '') === '' ? 1000 : qty_field($l['factor'], $label . ', conversia');
            // A line bought by the box / case teaches the article's box size, for any supplier next time.
            $pack = ($l['pack'] ?? '') === '' ? null : qty_field($l['pack'], $label . ', bax/cutie');
            $todo[] = [(int)$item['id'], qty_field($l['qty'] ?? '', $label), cost_field($l['cost'] ?? '', $label), $src, $factor, $pack];
        }
        $photos = store_photos();
        try {
            $id = in_transaction(function (PDO $db) use ($supplier, $docNo, $docDate, $note, $photos, $todo, $doc) {
                $db->prepare('INSERT INTO receipts (supplier, doc_no, doc_date, note, photos, created_at) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$supplier, $docNo, $docDate, $note, json_encode($photos), now()]);
                $id = (int)$db->lastInsertId();
                $alias = $db->prepare('INSERT INTO stock_aliases (text, item_id, factor, updated_at) VALUES (?, ?, ?, ?)
                                       ON CONFLICT(text) DO UPDATE SET item_id = excluded.item_id, factor = excluded.factor, updated_at = excluded.updated_at');
                $setPack = $db->prepare('UPDATE stock_items SET pack = ? WHERE id = ?');
                foreach ($todo as [$item, $qty, $cost, $src, $factor, $pack]) {
                    stock_apply($item, $qty, 'in', $doc, $note, null, $id, $cost);
                    if ($src !== '') $alias->execute([$src, $item, $factor, now()]);
                    if ($pack !== null) $setPack->execute([$pack, $item]);
                }
                return $id;
            });
        } catch (Throwable $e) {
            foreach ($photos as $name) @unlink(INVOICE_DIR . '/' . $name);
            throw $e;
        }
        out(['ok' => true, 'id' => $id, 'lines' => count($todo), 'photos' => count($photos)]);

    case 'receipts':
        require_session_auth();
        $q = clean_str($_GET['q'] ?? '', 60);
        $sql = "SELECT r.*, COUNT(m.id) AS lines, SUM(m.cost) AS total FROM receipts r LEFT JOIN stock_moves m ON m.receipt_id = r.id"
            . ($q !== '' ? " WHERE r.supplier LIKE ? ESCAPE '\\' OR r.doc_no LIKE ? ESCAPE '\\' OR r.note LIKE ? ESCAPE '\\'" : '')
            . ' GROUP BY r.id ORDER BY r.id DESC LIMIT 300';
        $st = db()->prepare($sql);
        $like = '%' . addcslashes($q, '%_') . '%';
        $st->execute($q !== '' ? [$like, $like, $like] : []);
        out(['ok' => true, 'receipts' => array_map('receipt_row', $st->fetchAll())]);

    case 'receipt_photo':
        // Opened as a normal link or <img>, so it cannot carry the CSRF header: reading only needs the login.
        if (empty($_SESSION['auth'])) { http_response_code(401); exit('Autentifică-te în pagina de inventar.'); }
        $st = db()->prepare('SELECT photos FROM receipts WHERE id = ?');
        $st->execute([(int)($_GET['id'] ?? 0)]);
        $list = json_decode((string)$st->fetchColumn(), true) ?: [];
        $name = $list[(int)($_GET['n'] ?? 0)] ?? null;
        if (!is_string($name) || !preg_match(PHOTO_NAME, $name) || !is_file(INVOICE_DIR . '/' . $name)) { http_response_code(404); exit('Poza nu există.'); }
        header('Content-Type: ' . array_search(pathinfo($name, PATHINFO_EXTENSION), PHOTO_TYPES, true));
        header('Content-Length: ' . filesize(INVOICE_DIR . '/' . $name));
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        readfile(INVOICE_DIR . '/' . $name);
        exit;

    case 'move':
        // Stock-out (breakage, expiry, own use, sales outside the online orders) or a stock count.
        require_post();
        require_session_auth();
        $b = json_body();
        $item = active_item($b['item'] ?? 0);
        $kind = $b['kind'] ?? '';
        if (!in_array($kind, ['out', 'count'], true)) fail('Tip de mișcare invalid.');
        $qty = qty_field($b['qty'] ?? '', $kind === 'count' ? 'Stoc numărat' : 'Cantitate', $kind === 'count');
        $note = clean_str($b['note'] ?? '', 200);
        $balance = in_transaction(function () use ($item, $kind, $qty, $note) {
            if ($kind === 'out') return stock_apply((int)$item['id'], -$qty, 'out', '', $note);
            // The count is the truth: record the difference against the stock known right now.
            $q = db()->prepare('SELECT qty FROM stock_items WHERE id = ?');
            $q->execute([$item['id']]);
            return stock_apply((int)$item['id'], $qty - (int)$q->fetchColumn(), 'count', '', $note);
        });
        out(['ok' => true, 'balance' => $balance]);

    case 'moves':
        require_session_auth();
        $where = [];
        $args = [];
        if (!empty($_GET['item'])) { $where[] = 'm.item_id = ?'; $args[] = (int)$_GET['item']; }
        $kind = is_string($_GET['kind'] ?? null) ? $_GET['kind'] : '';
        if (isset(STOCK_KINDS[$kind])) { $where[] = 'm.kind = ?'; $args[] = $kind; }
        $limit = max(1, min(1000, (int)($_GET['limit'] ?? 300)));
        if (!empty($_GET['receipt'])) { $where[] = 'm.receipt_id = ?'; $args[] = (int)$_GET['receipt']; }
        $q = db()->prepare('SELECT m.id, m.item_id, s.name, s.variant, s.unit, m.kind, m.qty, m.balance, m.doc, m.note, m.order_id, m.receipt_id, m.cost, m.created_at
                            FROM stock_moves m JOIN stock_items s ON s.id = m.item_id'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY m.id DESC LIMIT $limit");
        $q->execute($args);
        out(['ok' => true, 'moves' => array_map(fn($m) => [
            'id' => (int)$m['id'], 'item' => (int)$m['item_id'], 'name' => $m['name'], 'variant' => $m['variant'], 'unit' => $m['unit'],
            'kind' => $m['kind'], 'qty' => (int)$m['qty'], 'balance' => (int)$m['balance'],
            'doc' => $m['doc'], 'note' => $m['note'], 'order' => $m['order_id'] !== null ? (int)$m['order_id'] : null, 'at' => $m['created_at'],
            'receipt' => $m['receipt_id'] !== null ? (int)$m['receipt_id'] : null, 'cost' => $m['cost'] !== null ? (int)$m['cost'] : null,
        ], $q->fetchAll())]);

    case 'invoices_info':
        require_session_auth();
        [$from, $to] = invoice_period($_GET);
        out(['ok' => true] + invoice_info($from, $to));

    case 'invoices_zip':
        // A plain link (the browser saves the file itself, even hundreds of MB on a phone): reading only needs the login.
        if (empty($_SESSION['auth'])) { http_response_code(401); exit('Autentifică-te în pagina de inventar.'); }
        [$from, $to] = invoice_period($_GET);
        invoice_zip($from, $to);

    default:
        fail('Acțiune necunoscută.', 404);
}
