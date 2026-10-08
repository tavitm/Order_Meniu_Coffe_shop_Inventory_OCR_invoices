<?php
declare(strict_types=1);
// Public API used by comanda.html: send an order, read the client's own open orders, ask for the bill.
require __DIR__ . '/lib/common.php';

function client_token(mixed $v): string
{
    if (!is_string($v) || !preg_match('/^[a-f0-9]{32}$/', $v)) fail('Sesiune client invalidă. Reîncarcă pagina.');
    return $v;
}

/** The client's open orders merged into one list, plus totals and the pending bill request. */
function client_view(string $client): array
{
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
    ];
}

$action = $_GET['action'] ?? '';
maybe_auto_close();
$menu = load_menu();
$cfg = order_settings($menu);

switch ($action) {
    case 'mine':
        // POST keeps the client id out of URLs and server logs; GET is still accepted.
        out(client_view(client_token($_SERVER['REQUEST_METHOD'] === 'POST' ? (json_body()['client'] ?? '') : ($_GET['client'] ?? ''))));

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
        foreach ($wanted as $w) {
            $id = is_array($w) && is_string($w['id'] ?? null) ? $w['id'] : '';
            $qty = is_array($w) ? filter_var($w['qty'] ?? null, FILTER_VALIDATE_INT) : false;
            if (!isset($catalog[$id])) fail('Un produs din coș nu mai este disponibil. Reîncarcă meniul.', 409);
            if ($qty === false || $qty < 1 || $qty > 50) fail('Cantitate invalidă pentru „' . $catalog[$id]['name'] . '”.');
            $lines[] = $catalog[$id] + ['id' => $id, 'qty' => $qty];
            $total += $catalog[$id]['price_cents'] * $qty;
        }

        // Per phone, plus a generous per-IP cap: all guests on the café Wi-Fi share one public IP.
        rate_limit('submit:' . $client, 12, 600);
        rate_limit('submit-ip:' . client_ip(), 300, 600);
        $db = db();
        $q = $db->prepare("SELECT table_no FROM orders WHERE client = ? AND status = 'open' LIMIT 1");
        $q->execute([$client]);
        $openTable = $q->fetchColumn();
        if ($openTable !== false && (int)$openTable !== $table) fail('Ai deja o comandă deschisă la masa ' . $openTable . '.', 409);

        $db->beginTransaction();
        $db->prepare('INSERT INTO orders (table_no, client, status, total_cents, created_at) VALUES (?, ?, \'open\', ?, ?)')
            ->execute([$table, $client, $total, now()]);
        $orderId = (int)$db->lastInsertId();
        $ins = $db->prepare('INSERT INTO order_items (order_id, product_id, name, subheader, price_cents, qty) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($lines as $l) $ins->execute([$orderId, $l['id'], $l['name'], $l['subheader'], $l['price_cents'], $l['qty']]);
        $db->commit();
        out(['order' => $orderId] + client_view($client));

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
