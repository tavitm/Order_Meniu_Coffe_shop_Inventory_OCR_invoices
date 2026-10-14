<?php
declare(strict_types=1);
// Inventory helpers shared by the inventory API (inventar/), the admin API (menu sync) and the waiter API (sales).
// Every menu product has a stock row (created automatically); ingredients and consumables are added by hand.
// Quantities are integers in thousandths of the unit, so 3,5 kg is stored as 3500.

const STOCK_KINDS = ['in' => 'Intrare', 'out' => 'Ieșire', 'count' => 'Inventar', 'sale' => 'Vânzare'];

/** "3,5" / "3.5" / "12" -> thousandths; null when not a valid non-negative quantity (max 3 decimals). */
function stock_qty(mixed $v): ?int
{
    if (is_int($v)) $v = (string)$v;
    if (!is_string($v)) return null;
    $v = str_replace([' ', ','], ['', '.'], trim($v));
    if (!preg_match('/^\d{1,7}(\.\d{1,3})?$/', $v)) return null;
    [$int, $frac] = explode('.', $v . '.');
    return (int)$int * 1000 + (int)str_pad($frac, 3, '0');
}

/**
 * Mirror the menu into stock_items: new products get a row (not tracked until switched on or moved),
 * renamed/moved products are updated, products removed from the menu are archived with their history.
 * Writes only when something changed.
 */
function stock_sync_menu(?array $menu = null): void
{
    $menu ??= load_menu();
    $have = [];
    foreach (db()->query('SELECT product_id, name, variant, grp, pos, archived FROM stock_items WHERE product_id IS NOT NULL') as $r) {
        $have[(string)$r['product_id']] = $r;
    }
    $ops = [];
    $seen = [];
    $pos = 0;
    foreach ($menu['categories'] ?? [] as $c) {
        foreach ($c['items'] ?? [] as $i) {
            $pid = (string)($i['id'] ?? '');
            if ($pid === '' || isset($seen[$pid])) continue;
            $seen[$pid] = true;
            $row = [(string)($i['name'] ?? ''), (string)($i['subheader'] ?? ''), (string)($c['name'] ?? ''), ++$pos];
            $old = $have[$pid] ?? null;
            if (!$old) $ops[] = ['insert', $pid, $row];
            elseif ([$old['name'], $old['variant'], $old['grp'], (int)$old['pos']] !== $row || (int)$old['archived']) $ops[] = ['update', $pid, $row];
        }
    }
    foreach ($have as $pid => $old) {
        if (!isset($seen[(string)$pid]) && !(int)$old['archived']) $ops[] = ['archive', (string)$pid, null];
    }
    if (!$ops) return;
    in_transaction(function (PDO $db) use ($ops) {
        $ins = $db->prepare('INSERT INTO stock_items (product_id, name, variant, grp, pos, unit, tracked, created_at) VALUES (?, ?, ?, ?, ?, \'buc\', 0, ?)');
        $upd = $db->prepare('UPDATE stock_items SET name = ?, variant = ?, grp = ?, pos = ?, archived = 0 WHERE product_id = ?');
        $arc = $db->prepare('UPDATE stock_items SET archived = 1 WHERE product_id = ?');
        foreach ($ops as [$op, $pid, $row]) {
            if ($op === 'insert') $ins->execute([$pid, ...$row, now()]);
            elseif ($op === 'update') $upd->execute([...$row, $pid]);
            else $arc->execute([$pid]);
        }
    });
}

/**
 * Apply a stock change and log it. Call inside a transaction. Any movement switches tracking on.
 * Returns the stock after the change.
 */
function stock_apply(int $itemId, int $delta, string $kind, string $doc = '', string $note = '', ?int $orderId = null): int
{
    $db = db();
    $db->prepare('UPDATE stock_items SET qty = qty + ?, tracked = 1 WHERE id = ?')->execute([$delta, $itemId]);
    $q = $db->prepare('SELECT qty FROM stock_items WHERE id = ?');
    $q->execute([$itemId]);
    $balance = (int)$q->fetchColumn();
    $db->prepare('INSERT INTO stock_moves (item_id, kind, qty, balance, doc, note, order_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$itemId, $kind, $delta, $balance, $doc, $note, $orderId, now()]);
    return $balance;
}

/** Orders just marked paid: take the sold quantities out of the tracked products. Call inside the payment transaction. */
function stock_record_sales(array $orderIds): void
{
    if (!$orderIds) return;
    $in = implode(',', array_fill(0, count($orderIds), '?'));
    $q = db()->prepare(<<<SQL
        SELECT i.order_id, o.table_no, s.id AS item_id, SUM(i.qty) AS qty
        FROM order_items i
        JOIN orders o ON o.id = i.order_id
        JOIN stock_items s ON s.product_id = i.product_id
        WHERE i.order_id IN ($in) AND s.tracked = 1
        GROUP BY i.order_id, s.id
        ORDER BY i.order_id, s.id
    SQL);
    $q->execute(array_values($orderIds));
    foreach ($q->fetchAll() as $r) {
        stock_apply((int)$r['item_id'], -1000 * (int)$r['qty'], 'sale', '', 'Comanda #' . $r['order_id'] . ' · masa ' . $r['table_no'], (int)$r['order_id']);
    }
}

/** Pieces of each menu product in orders that are sent but not paid yet (product id => pieces). */
function stock_reserved(): array
{
    $rows = db()->query("SELECT i.product_id, SUM(i.qty) AS qty FROM order_items i JOIN orders o ON o.id = i.order_id WHERE o.status = 'open' GROUP BY i.product_id");
    $out = [];
    foreach ($rows as $r) $out[(string)$r['product_id']] = (int)$r['qty'];
    return $out;
}

/**
 * Whole pieces a client can still order, for tracked menu products only (product id => pieces, never negative):
 * stock minus what is already ordered and not paid (paying takes it out of the stock).
 * Untracked products are missing from the list and stay unlimited.
 */
function stock_available(): array
{
    $reserved = stock_reserved();
    $out = [];
    foreach (db()->query('SELECT product_id, qty FROM stock_items WHERE product_id IS NOT NULL AND tracked = 1 AND archived = 0') as $r) {
        $pid = (string)$r['product_id'];
        $out[$pid] = max(0, intdiv((int)$r['qty'] - 1000 * ($reserved[$pid] ?? 0), 1000));
    }
    return $out;
}

/** Message shown to the client when a product cannot be ordered in the wanted quantity. */
function stock_message(string $name, int $available): string
{
    return $available > 0
        ? 'Pentru produsul ' . $name . ' mai avem doar ' . $available . ' buc. Pentru mai multe, întreabă ospătarul.'
        : 'Pentru produsul ' . $name . ' întreabă ospătarul.';
}

/** All stock rows with the last goods receipt, as sent to the inventory page. */
function stock_list(): array
{
    $db = db();
    $reserved = stock_reserved();
    $last = [];
    foreach ($db->query("SELECT item_id, created_at, qty FROM stock_moves WHERE id IN (SELECT MAX(id) FROM stock_moves WHERE kind = 'in' GROUP BY item_id)") as $r) {
        $last[(int)$r['item_id']] = ['at' => $r['created_at'], 'qty' => (int)$r['qty']];
    }
    $rows = $db->query('SELECT * FROM stock_items ORDER BY product_id IS NOT NULL, pos, name COLLATE NOCASE')->fetchAll();
    return array_map(fn($r) => stock_row($r, $last[(int)$r['id']] ?? null, $reserved[(string)$r['product_id']] ?? 0), $rows);
}

function stock_row(array $r, ?array $lastIn = null, int $reserved = 0): array
{
    return [
        'id' => (int)$r['id'],
        'product' => $r['product_id'] !== null,
        'name' => $r['name'],
        'variant' => $r['variant'],
        'grp' => $r['grp'],
        'unit' => $r['unit'],
        'qty' => (int)$r['qty'],
        'min' => (int)$r['min_qty'],
        'tracked' => (bool)$r['tracked'],
        'archived' => (bool)$r['archived'],
        'last_in' => $lastIn,
        'reserved' => $r['product_id'] !== null ? $reserved * 1000 : 0,
    ];
}

function stock_item(int $id): array
{
    $q = db()->prepare('SELECT * FROM stock_items WHERE id = ?');
    $q->execute([$id]);
    $r = $q->fetch();
    if (!$r) fail('Articolul nu există.', 404);
    return $r;
}
