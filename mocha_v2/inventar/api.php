<?php
declare(strict_types=1);
// Inventory API: stock rows, goods receipts, stock-outs, stock counts and the movement log.
// Same password as the admin page, with its own session (cookie limited to this folder).
require __DIR__ . '/../lib/common.php';

start_session('mocha_inventory', 90 * 24 * 3600, 24 * 3600); // ca la admin: maximum 3 luni, expiră după 24 h fără activitate
maybe_auto_close();

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

function unit_field(mixed $v): string
{
    $u = clean_str($v, 12);
    return $u === '' ? 'buc' : $u;
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
        out(['ok' => true, 'loggedIn' => $in, 'csrf' => $in ? $_SESSION['csrf'] : null, 'configured' => mb_strlen(ADMIN_PASSWORD) >= 10]);

    case 'login':
        password_login(ADMIN_PASSWORD, 'Parola nu este setată. Editează admin/config.php (minimum 10 caractere).');
        out(['ok' => true, 'csrf' => $_SESSION['csrf']]);

    case 'logout':
        $_SESSION = [];
        session_destroy();
        out(['ok' => true]);

    case 'list':
        require_session_auth();
        stock_sync_menu();
        out(['ok' => true, 'items' => stock_list()]);

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
        if ($item && $item['product_id'] !== null) {
            $tracked = array_key_exists('tracked', $b) ? !empty($b['tracked']) : (bool)$item['tracked'];
            db()->prepare('UPDATE stock_items SET unit = ?, min_qty = ?, tracked = ? WHERE id = ?')->execute([$unit, $min, $tracked ? 1 : 0, $id]);
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
        $id = in_transaction(function (PDO $db) use ($id, $name, $grp, $unit, $min, $tracked, $initial) {
            if ($id) {
                $db->prepare('UPDATE stock_items SET name = ?, grp = ?, unit = ?, min_qty = ?, tracked = ? WHERE id = ?')
                    ->execute([$name, $grp, $unit, $min, $tracked ? 1 : 0, $id]);
                return $id;
            }
            $db->prepare('INSERT INTO stock_items (product_id, name, grp, unit, min_qty, tracked, created_at) VALUES (NULL, ?, ?, ?, ?, ?, ?)')
                ->execute([$name, $grp, $unit, $min, $tracked ? 1 : 0, now()]);
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
        // Goods receipt: one or more lines from the same document (invoice / delivery note).
        require_post();
        require_session_auth();
        $b = json_body();
        $doc = clean_str($b['doc'] ?? '', 60);
        $note = clean_str($b['note'] ?? '', 200);
        $lines = is_array($b['lines'] ?? null) ? $b['lines'] : [];
        if (!$lines) fail('Adaugă cel puțin un articol.');
        if (count($lines) > 200) fail('Prea multe rânduri într-o singură recepție (maximum 200).');
        $todo = [];
        foreach (array_values($lines) as $n => $l) {
            if (!is_array($l)) fail('Rândul ' . ($n + 1) . ' este invalid.');
            $item = active_item($l['item'] ?? 0);
            $todo[] = [(int)$item['id'], qty_field($l['qty'] ?? '', 'Rândul ' . ($n + 1) . ' („' . $item['name'] . '”)')];
        }
        in_transaction(function () use ($todo, $doc, $note) {
            foreach ($todo as [$id, $qty]) stock_apply($id, $qty, 'in', $doc, $note);
        });
        out(['ok' => true, 'lines' => count($todo)]);

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
        $q = db()->prepare('SELECT m.id, m.item_id, s.name, s.variant, s.unit, m.kind, m.qty, m.balance, m.doc, m.note, m.order_id, m.created_at
                            FROM stock_moves m JOIN stock_items s ON s.id = m.item_id'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY m.id DESC LIMIT $limit");
        $q->execute($args);
        out(['ok' => true, 'moves' => array_map(fn($m) => [
            'id' => (int)$m['id'], 'item' => (int)$m['item_id'], 'name' => $m['name'], 'variant' => $m['variant'], 'unit' => $m['unit'],
            'kind' => $m['kind'], 'qty' => (int)$m['qty'], 'balance' => (int)$m['balance'],
            'doc' => $m['doc'], 'note' => $m['note'], 'order' => $m['order_id'] !== null ? (int)$m['order_id'] : null, 'at' => $m['created_at'],
        ], $q->fetchAll())]);

    default:
        fail('Acțiune necunoscută.', 404);
}
