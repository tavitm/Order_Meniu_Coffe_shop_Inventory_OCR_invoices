<?php
declare(strict_types=1);
// Public API used by comanda.html: send an order, read the client's own open orders, ask for the bill.
require __DIR__ . '/lib/common.php';

function client_token(mixed $v): string
{
    if (!is_string($v) || !preg_match('/^[a-f0-9]{32}$/', $v)) fail('Sesiune client invalidă. Reîncarcă pagina.');
    return $v;
}

/**
 * What clients may still order when "block products without stock" is on: product id => pieces (capped at the
 * 50 allowed per line, so exact stock levels above that are not published). Null when the check is off.
 */
function public_stock(array $cfg): ?array
{
    if (!$cfg['enabled'] || !$cfg['stockCheck']) return null;
    return array_map(fn($n) => min($n, 50), stock_available());
}

/** The client's open orders merged into one list, plus totals, the pending bill request and what can still be ordered. */
function client_view(string $client): array
{
    global $cfg;
    $orders = open_orders('client = ?', [$client]);
    $lines = [];
    $total = 0;
    $payRequest = null;
    foreach ($orders as $o) {
        $total += $o['total_cents'];
        if ($o['pay_request']) $payRequest = $o['pay_request'];
        foreach ($o['items'] as $i) {
            $key = $i['product_id'] . '|' . $i['price_cents'];
            $lines[$key] ??= ['name' => $i['name'], 'subheader' => $i['subheader'], 'price_cents' => $i['price_cents'], 'qty' => 0];
            $lines[$key]['qty'] += $i['qty'];
        }
    }
    return [
        'ok' => true,
        'table' => $orders ? $orders[0]['table_no'] : null,
        'items' => array_values($lines),
        'total_cents' => $total,
        'pay_request' => $payRequest,
        'stock' => public_stock($cfg),
    ];
}

$action = $_GET['action'] ?? '';
maybe_auto_close();
$menu = load_menu();
$cfg = order_settings($menu);

switch ($action) {
    case 'stock':
        // Read by the menu page on load, so it can mark the products to ask the waiter about.
        rate_limit('stock-ip:' . client_ip(), 2000, 600);
        out(['ok' => true, 'stock' => public_stock($cfg)]);

    case 'mine':
        // POST only, so the client id never ends up in URLs or server logs. Polled every 15 s per phone.
        require_post();
        rate_limit('mine-ip:' . client_ip(), 2000, 600);
        out(client_view(client_token(json_body()['client'] ?? '')));

    case 'submit':
        require_post();
        if (!$cfg['enabled']) fail('Comenzile online nu sunt disponibile momentan. Te rugăm să chemi ospătarul.', 503);
        $body = json_body();
        $client = client_token($body['client'] ?? '');
        $table = filter_var($body['table'] ?? null, FILTER_VALIDATE_INT);
        if ($table === false || $table < 1 || $table > $cfg['tables']) fail('Numărul mesei trebuie să fie între 1 și ' . $cfg['tables'] . '.');
        $wanted = is_array($body['items'] ?? null) ? $body['items'] : [];
        if (!$wanted) fail('Comanda este goală.');
        if (count($wanted) > 60) fail('Prea multe produse într-o singură comandă.');

        // Prices always come from the published menu, never from the browser.
        $catalog = [];
        foreach ($menu['categories'] ?? [] as $c) {
            if (($c['visible'] ?? true) === false) continue;
            foreach ($c['items'] ?? [] as $i) {
                if (($i['visible'] ?? true) === false) continue;
                $cents = to_cents((string)($i['price'] ?? ''));
                if ($cents === null) continue;
                $catalog[$i['id']] = ['name' => (string)$i['name'], 'subheader' => (string)($i['subheader'] ?? ''), 'price_cents' => $cents];
            }
        }
        $lines = [];
        $total = 0;
        $pieces = [];
        foreach ($wanted as $w) {
            $id = is_array($w) && is_string($w['id'] ?? null) ? $w['id'] : '';
            $qty = is_array($w) ? filter_var($w['qty'] ?? null, FILTER_VALIDATE_INT) : false;
            if (!isset($catalog[$id])) fail('Un produs din coș nu mai este disponibil. Reîncarcă meniul.', 409);
            if ($qty === false || $qty < 1 || $qty > 50) fail('Cantitate invalidă pentru „' . $catalog[$id]['name'] . '”.');
            $lines[] = $catalog[$id] + ['id' => $id, 'qty' => $qty];
            $total += $catalog[$id]['price_cents'] * $qty;
            $pieces[$id] = ($pieces[$id] ?? 0) + $qty;
        }

        // Per phone, plus a generous per-IP cap: all guests on the café Wi-Fi share one public IP.
        rate_limit('submit:' . $client, 12, 600);
        rate_limit('submit-ip:' . client_ip(), 300, 600);
        $db = db();
        $q = $db->prepare("SELECT table_no FROM orders WHERE client = ? AND status = 'open' LIMIT 1");
        $q->execute([$client]);
        $openTable = $q->fetchColumn();
        if ($openTable !== false && (int)$openTable !== $table) fail('Ai deja o comandă deschisă la masa ' . $openTable . '.', 409);

        // The stock check and the insert share one write transaction: two phones cannot both take the last bottle.
        $refused = in_transaction(function (PDO $db) use ($cfg, $pieces, $catalog, $table, $client, $total, $lines) {
            if ($cfg['stockCheck']) {
                $available = stock_available();
                $msgs = [];
                foreach ($pieces as $id => $qty) {
                    if (isset($available[$id]) && $qty > $available[$id]) {
                        $c = $catalog[$id];
                        $msgs[] = stock_message($c['name'] . ($c['subheader'] !== '' ? ' (' . $c['subheader'] . ')' : ''), $available[$id]);
                    }
                }
                if ($msgs) return implode(' ', $msgs);
            }
            $db->prepare('INSERT INTO orders (table_no, client, status, total_cents, created_at) VALUES (?, ?, \'open\', ?, ?)')
                ->execute([$table, $client, $total, now()]);
            $orderId = (int)$db->lastInsertId();
            $ins = $db->prepare('INSERT INTO order_items (order_id, product_id, name, subheader, price_cents, qty) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($lines as $l) $ins->execute([$orderId, $l['id'], $l['name'], $l['subheader'], $l['price_cents'], $l['qty']]);
            return $orderId;
        });
        if (is_string($refused)) out(['ok' => false, 'error' => $refused, 'stock' => public_stock($cfg)], 409);
        out(['order' => $refused] + client_view($client));

    case 'pay':
        require_post();
        $body = json_body();
        $client = client_token($body['client'] ?? '');
        $method = $body['method'] ?? '';
        if (!in_array($method, ['cash', 'card'], true)) fail('Alege Card sau Cash.');
        rate_limit('pay:' . $client, 10, 600);
        rate_limit('pay-ip:' . client_ip(), 300, 600);
        $q = db()->prepare("UPDATE orders SET pay_request = ?, pay_requested_at = ? WHERE client = ? AND status = 'open'");
        $q->execute([$method, now(), $client]);
        if ($q->rowCount() === 0) fail('Nu ai nicio comandă deschisă.', 404);
        out(client_view($client));

    default:
        fail('Acțiune necunoscută.', 404);
}
