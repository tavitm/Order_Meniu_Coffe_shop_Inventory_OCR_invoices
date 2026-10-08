<?php
declare(strict_types=1);
// Waiter API: per-waiter login (managed in admin), live orders, payments/cancellations attributed to the waiter's shift.
require __DIR__ . '/../lib/common.php';

start_session('mocha_waiter', 14 * 3600); // 14 ore; tura rămâne deschisă, ospătarul se autentifică din nou
maybe_auto_close();

/** Logged-in waiter, re-checked on every request (admin may deactivate or close the shift meanwhile). */
function me(): array
{
    if (empty($_SESSION['auth'])) fail('Sesiunea a expirat. Autentifică-te din nou.', 401);
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf'] ?? '', $token)) fail('Cerere invalidă (token).', 403);
    $q = db()->prepare('SELECT w.id, w.name, s.id AS shift_id, s.started_at FROM waiters w JOIN shifts s ON s.waiter_id = w.id
                        WHERE w.id = ? AND w.active = 1 AND w.deleted = 0 AND s.id = ? AND s.ended_at IS NULL');
    $q->execute([$_SESSION['waiter_id'] ?? 0, $_SESSION['shift_id'] ?? 0]);
    $me = $q->fetch();
    if (!$me) {
        $_SESSION = [];
        fail('Tura a fost închisă sau contul a fost dezactivat. Autentifică-te din nou.', 401);
    }
    return ['id' => (int)$me['id'], 'name' => $me['name'], 'shift_id' => (int)$me['shift_id'], 'started_at' => $me['started_at']];
}

function close_orders(array $me, string $where, array $args, string $status, ?string $payment): int
{
    $q = db()->prepare("UPDATE orders SET status = ?, payment = ?, closed_at = ?, waiter_id = ?, shift_id = ? WHERE status = 'open' AND ($where)");
    $q->execute(array_merge([$status, $payment, now(), $me['id'], $me['shift_id']], $args));
    return $q->rowCount();
}

function payment_method(array $body): string
{
    $m = $body['method'] ?? '';
    if (!in_array($m, ['cash', 'card'], true)) fail('Alege Numerar sau Card.');
    return $m;
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'status':
        $in = !empty($_SESSION['auth']);
        $res = ['ok' => true, 'loggedIn' => $in, 'csrf' => $in ? $_SESSION['csrf'] : null];
        if (!$in) $res['waiters'] = array_map(fn($w) => ['id' => $w['id'], 'name' => $w['name']], waiter_list());
        out($res);

    case 'login':
        require_post();
        rate_check('waiter-login-fail:' . client_ip(), 20, 900, 'Prea multe încercări greșite. Așteaptă 15 minute.');
        $now = time();
        $_SESSION['fails'] = array_values(array_filter($_SESSION['fails'] ?? [], fn($t) => $t > $now - 900));
        if (count($_SESSION['fails']) >= 5) fail('Prea multe încercări. Așteaptă 15 minute.', 429);
        $body = json_body();
        $q = db()->prepare('SELECT id, name, pass_hash FROM waiters WHERE id = ? AND active = 1 AND deleted = 0');
        $q->execute([(int)($body['waiter'] ?? 0)]);
        $w = $q->fetch();
        $pass = is_string($body['password'] ?? null) ? $body['password'] : '';
        if (!$w || !password_verify($pass, $w['pass_hash'])) {
            $_SESSION['fails'][] = $now;
            rate_hit('waiter-login-fail:' . client_ip());
            sleep(1);
            fail('Nume sau parolă greșită.', 401);
        }
        // Resume the open shift (e.g. after a phone change) or start a new one.
        $db = db();
        $q = $db->prepare('SELECT id FROM shifts WHERE waiter_id = ? AND ended_at IS NULL ORDER BY id DESC LIMIT 1');
        $q->execute([$w['id']]);
        $shift = $q->fetchColumn();
        if ($shift === false) {
            $db->prepare('INSERT INTO shifts (waiter_id, started_at) VALUES (?, ?)')->execute([$w['id'], now()]);
            $shift = $db->lastInsertId();
        }
        session_regenerate_id(true);
        $_SESSION = ['auth' => true, 'csrf' => bin2hex(random_bytes(32)), 'login_at' => time(), 'waiter_id' => (int)$w['id'], 'shift_id' => (int)$shift];
        out(['ok' => true, 'csrf' => $_SESSION['csrf']]);

    case 'logout':
        // Leaves the shift open; only "Închide tura" ends it.
        $_SESSION = [];
        session_destroy();
        out(['ok' => true]);

    case 'live':
        $me = me();
        $q = db()->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(total_cents), 0) AS cents,
                                   COALESCE(SUM(CASE WHEN payment = 'cash' THEN total_cents END), 0) AS cash
                            FROM orders WHERE shift_id = ? AND status = 'paid'");
        $q->execute([$me['shift_id']]);
        $mine = $q->fetch();
        out(['ok' => true, 'now' => now(), 'orders' => open_orders(), 'currency' => (string)(load_menu()['settings']['currency'] ?? 'lei'),
            'me' => $me + ['paid_count' => (int)$mine['n'], 'paid_cents' => (int)$mine['cents'], 'cash_cents' => (int)$mine['cash']]]);

    case 'cancel':
        require_post();
        $me = me();
        $id = filter_var(json_body()['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || close_orders($me, 'id = ?', [$id], 'cancelled', null) === 0) fail('Comanda nu mai este deschisă.', 409);
        out(['ok' => true]);

    case 'pay':
        require_post();
        $me = me();
        $body = json_body();
        $id = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || close_orders($me, 'id = ?', [$id], 'paid', payment_method($body)) === 0) fail('Comanda nu mai este deschisă.', 409);
        out(['ok' => true]);

    case 'pay_table':
        require_post();
        $me = me();
        $body = json_body();
        $table = filter_var($body['table'] ?? null, FILTER_VALIDATE_INT);
        // Only the orders the waiter had on screen: an order arriving meanwhile must not be charged unseen.
        $ids = array_values(array_unique(array_filter(array_map('intval', is_array($body['ids'] ?? null) ? $body['ids'] : []))));
        if (!$table || !$ids || count($ids) > 200) fail('Reîncarcă lista de comenzi și încearcă din nou.', 409);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $n = close_orders($me, "table_no = ? AND id IN ($in)", array_merge([$table], $ids), 'paid', payment_method($body));
        if ($n === 0) fail('Masa nu mai are comenzi deschise.', 409);
        out(['ok' => true, 'closed' => $n]);

    case 'shift':
        $me = me();
        out(['ok' => true, 'report' => shift_stats($me['shift_id'])]);

    case 'shift_close':
        require_post();
        $me = me();
        $report = close_shift($me['shift_id'], 'waiter');
        $_SESSION = [];
        session_destroy();
        out(['ok' => true, 'report' => $report]);

    default:
        fail('Acțiune necunoscută.', 404);
}
