<?php
/* Babyglee admin panel: orders, order status, analytics, products, settings (GTM, delivery, Meta), backup. */
declare(strict_types=1);
require __DIR__ . '/../api/lib.php';

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('bg_admin');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => $secure]);
session_start();
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

$pdo = db();
$page = preg_replace('/[^a-z]/', '', (string) ($_GET['p'] ?? 'dashboard')) ?: 'dashboard';
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">';
if ($isPost && !hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) { http_response_code(400); exit('Session expired. Go back, reload the page and try again.'); }

function go(string $q = ''): void { header('Location: index.php' . ($q !== '' ? '?' . $q : '')); exit; }
function flash(?string $m = null): string { if ($m !== null) { $_SESSION['flash'] = $m; return ''; } $f = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']); return $f; }
function badge(string $s): string { return '<span class="st st-' . h($s) . '">' . h(BG_STATUSES[$s] ?? $s) . '</span>'; }

/* ---------------- first-time setup and login ---------------- */
$hash = setting('admin_hash');
$error = '';
if ($hash === '') {
    if ($isPost) {
        $pw = (string) ($_POST['password'] ?? '');
        if (!hash_equals((string) (cfg()['setup_key'] ?? ''), (string) ($_POST['setup_key'] ?? '')) || strpos((string) cfg()['setup_key'], 'CHANGE-THIS') === 0) $error = 'Setup key is wrong (or still the sample value in api/config.php).';
        elseif (strlen($pw) < 10) $error = 'Password must be at least 10 characters.';
        elseif ($pw !== ($_POST['password2'] ?? '')) $error = 'The two passwords do not match.';
        else { set_setting('admin_hash', password_hash($pw, PASSWORD_DEFAULT)); session_regenerate_id(true); $_SESSION['admin'] = true; flash('Admin password created. Welcome!'); go(); }
    }
    page_head('Set up admin', false);
    echo '<form method="post" class="card auth"><h1>Set up admin</h1><p class="mut">First visit: create the admin password. The setup key is the one you wrote in <code>api/config.php</code>.</p>' . ($error ? '<p class="err">' . h($error) . '</p>' : '') . $csrf .
        '<label>Setup key<input name="setup_key" required autocomplete="off"></label><label>New password (10+ characters)<input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
        <label>Repeat password<input type="password" name="password2" required autocomplete="new-password"></label><button class="btn">Create admin</button></form>';
    page_foot(); exit;
}
if (empty($_SESSION['admin'])) {
    if ($isPost) {
        $ip = client_ip();
        $c = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND created_at > ?'); $c->execute([$ip, date('Y-m-d H:i:s', time() - 900)]);
        if ((int) $c->fetchColumn() >= 8) $error = 'Too many attempts. Wait 15 minutes and try again.';
        elseif (password_verify((string) ($_POST['password'] ?? ''), $hash)) { session_regenerate_id(true); $_SESSION['admin'] = true; $_SESSION['csrf'] = bin2hex(random_bytes(16)); go(); }
        else { $pdo->prepare('INSERT INTO login_attempts (ip, created_at) VALUES (?, ?)')->execute([$ip, now()]); $error = 'Wrong password.'; }
    }
    page_head('Log in', false);
    echo '<form method="post" class="card auth"><h1>Babyglee admin</h1>' . ($error ? '<p class="err">' . h($error) . '</p>' : '') . $csrf .
        '<label>Password<input type="password" name="password" required autofocus autocomplete="current-password"></label><button class="btn">Log in</button></form>';
    page_foot(); exit;
}
if ($page === 'logout' && $isPost) { $_SESSION = []; session_destroy(); go(); }

/* ---------------- actions (POST) ---------------- */
if ($isPost) {
    $act = (string) ($_POST['act'] ?? '');
    if ($act === 'status') {
        $id = (string) $_POST['id']; $st = (string) $_POST['status']; $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 500);
        if (isset(BG_STATUSES[$st])) {
            $cur = $pdo->prepare('SELECT status FROM orders WHERE id = ?'); $cur->execute([$id]); $was = $cur->fetchColumn();
            if ($was !== false && ($was !== $st || $note !== '')) {
                $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([$st, $id]);
                $pdo->prepare('INSERT INTO order_log (order_id, created_at, status, note) VALUES (?,?,?,?)')->execute([$id, now(), $st, $note]);
                flash('Order #' . $id . ' is now ' . BG_STATUSES[$st] . '.');
            }
        }
        go((string) ($_POST['back'] ?? '') === 'list' ? 'p=orders&' . http_build_query(['status' => $_POST['f_status'] ?? '', 'q' => $_POST['f_q'] ?? '']) : 'p=order&id=' . rawurlencode($id));
    }
    if ($act === 'note') {
        $pdo->prepare('UPDATE orders SET note = ? WHERE id = ?')->execute([mb_substr(trim((string) $_POST['note']), 0, 2000), (string) $_POST['id']]);
        flash('Note saved.'); go('p=order&id=' . rawurlencode((string) $_POST['id']));
    }
    if ($act === 'product') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = mb_substr(trim((string) $_POST['name']), 0, 150); $color = mb_substr(trim((string) $_POST['color']), 0, 80);
        $sku = mb_substr(preg_replace('/[^A-Za-z0-9._-]/', '', (string) $_POST['sku']) ?? '', 0, 60);
        $price = max(0, (int) $_POST['price']); $list = max($price, (int) $_POST['list_price']); $usd = max(0, (int) $_POST['price_usd']);
        $hex = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $_POST['color_hex']) ? (string) $_POST['color_hex'] : '#24babc';
        $sizes = implode(',', array_values(array_intersect(BG_SIZES, array_map('intval', (array) ($_POST['sizes'] ?? [])))));
        $active = empty($_POST['active']) ? 0 : 1; $sort = (int) ($_POST['sort'] ?? 0);
        $img = null; $err = '';
        if (!empty($_FILES['image']['name'])) {
            $f = $_FILES['image']; $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
            $info = $f['error'] === UPLOAD_ERR_OK ? @getimagesize($f['tmp_name']) : false;
            if (!$info || !isset($types[$info[2]])) $err = 'Image must be a JPG, PNG or WebP file.';
            elseif ($f['size'] > 3 * 1024 * 1024) $err = 'Image is larger than 3 MB.';
            else {
                $dir = __DIR__ . '/../uploads'; if (!is_dir($dir)) mkdir($dir, 0755, true);
                $fn = 'p-' . bin2hex(random_bytes(8)) . '.' . $types[$info[2]];
                if (move_uploaded_file($f['tmp_name'], $dir . '/' . $fn)) $img = 'uploads/' . $fn; else $err = 'Could not save the image (check the uploads folder permission).';
            }
        }
        if ($name === '' || $sku === '' || $price < 1 || $sizes === '') $err = $err ?: 'Name, SKU, price and at least one size are required.';
        if (!$id && !$img && !$err) $err = 'Please choose a product image.';
        if ($err) { flash('Not saved: ' . $err); go('p=product' . ($id ? '&id=' . $id : '')); }
        if ($id) {
            $pdo->prepare('UPDATE products SET name=?, color=?, sku=?, price=?, list_price=?, price_usd=?, color_hex=?, sizes=?, active=?, sort=?' . ($img ? ', img=?' : '') . ' WHERE id=?')
                ->execute(array_merge([$name, $color, $sku, $price, $list, $usd, $hex, $sizes, $active, $sort], $img ? [$img] : [], [$id]));
            flash('Product saved.');
        } else {
            $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name . '-' . $color)) ?? '', '-') ?: 'product';
            $base = $slug; $n = 2; $chk = $pdo->prepare('SELECT 1 FROM products WHERE slug = ?');
            while ($chk->execute([$slug]) && $chk->fetchColumn()) $slug = $base . '-' . $n++;
            $pdo->prepare('INSERT INTO products (slug, sku, name, color, color_hex, img, price, list_price, price_usd, sizes, page, active, sort, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$slug, $sku, $name, $color, $hex, $img, $price, $list, $usd, $sizes, '', $active, $sort, now()]);
            flash('Product added. It is now in the shop.');
        }
        go('p=products');
    }
    if ($act === 'product_delete') {
        $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([(int) $_POST['id']]);
        flash('Product removed. Past orders keep their details.'); go('p=products');
    }
    if ($act === 'settings') {
        $clean = [
            'gtm_id' => preg_match('/^GTM-[A-Z0-9]{4,12}$/', $g = strtoupper(trim((string) $_POST['gtm_id']))) ? $g : '',
            'gtm_src' => filter_var($s = trim((string) $_POST['gtm_src']), FILTER_VALIDATE_URL) && strpos($s, 'https://') === 0 ? $s : '',
            'whatsapp' => preg_replace('/\D/', '', (string) $_POST['whatsapp']) ?: BG_DEFAULTS['whatsapp'],
            'delivery_dhaka' => (string) max(0, (int) $_POST['delivery_dhaka']), 'delivery_outside' => (string) max(0, (int) $_POST['delivery_outside']),
            'meta_pixel_id' => preg_replace('/\D/', '', (string) $_POST['meta_pixel_id']) ?? '',
            'meta_test_code' => preg_replace('/[^A-Za-z0-9]/', '', (string) $_POST['meta_test_code']) ?? '',
            'meta_api_version' => preg_match('/^v\d{1,2}\.\d$/', $v = trim((string) $_POST['meta_api_version'])) ? $v : BG_DEFAULTS['meta_api_version'],
        ];
        foreach ($clean as $k => $v) set_setting($k, $v);
        $tok = trim((string) $_POST['meta_capi_token']);
        if ($tok !== '') set_setting('meta_capi_token', $tok === 'REMOVE' ? '' : $tok);
        $bad = [];
        if (trim((string) $_POST['gtm_id']) !== '' && $clean['gtm_id'] === '') $bad[] = 'GTM ID must look like GTM-XXXXXXX';
        if (trim((string) $_POST['gtm_src']) !== '' && $clean['gtm_src'] === '') $bad[] = 'GTM loader URL must start with https://';
        flash($bad ? 'Saved, except: ' . implode('; ', $bad) . '.' : 'Settings saved. The website updates within a minute.'); go('p=settings');
    }
    if ($act === 'password') {
        $pw = (string) $_POST['password'];
        if (!password_verify((string) $_POST['current'], $hash)) flash('Current password is wrong.');
        elseif (strlen($pw) < 10 || $pw !== $_POST['password2']) flash('New password must be 10+ characters and typed the same twice.');
        else { set_setting('admin_hash', password_hash($pw, PASSWORD_DEFAULT)); flash('Password changed.'); }
        go('p=settings');
    }
    go();
}

/* ---------------- downloads ---------------- */
if ($page === 'export') {
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="babyglee-orders-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Order ID', 'Date', 'Status', 'Source', 'Name', 'Phone', 'Email', 'District', 'Address', 'Area', 'Items', 'Subtotal', 'Delivery', 'Total', 'UTM source', 'UTM campaign', 'Note']);
    $safe = fn($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : $v;   // stop spreadsheet formula injection
    foreach ($pdo->query('SELECT * FROM orders ORDER BY created_at DESC') as $o) {
        $items = implode(' | ', array_map(fn($i) => $i['product'] . ' EU ' . $i['size'] . ' x' . $i['qty'], json_decode((string) $o['items'], true) ?: []));
        $t = json_decode((string) $o['tracking'], true) ?: [];
        fputcsv($out, array_map($safe, [$o['id'], $o['created_at'], $o['status'], $o['source'], $o['name'], $o['phone'], $o['email'], $o['district'], $o['address'], $o['area'], $items, $o['subtotal'], $o['delivery'], $o['total'], $t['utm_source'] ?? '', $t['utm_campaign'] ?? '', $o['note']]));
    }
    exit;
}
if ($page === 'backup') {
    header('Content-Type: application/json; charset=utf-8'); header('Content-Disposition: attachment; filename="babyglee-backup-' . date('Y-m-d-His') . '.json"');
    $settings = []; foreach ($pdo->query('SELECT k, v FROM settings') as $r) if (!in_array($r['k'], ['admin_hash', 'meta_capi_token'], true)) $settings[$r['k']] = $r['v'];
    echo json_encode(['created_at' => now(), 'note' => 'Babyglee backup. Passwords and API tokens are not included.', 'settings' => $settings,
        'products' => $pdo->query('SELECT * FROM products')->fetchAll(), 'orders' => $pdo->query('SELECT * FROM orders')->fetchAll(), 'order_log' => $pdo->query('SELECT * FROM order_log')->fetchAll()], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/* ---------------- pages ---------------- */
function page_head(string $title, bool $nav = true): void {
    global $page, $csrf;
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><title>' . h($title) . ' — Babyglee admin</title><link rel="stylesheet" href="admin.css"></head><body>';
    if ($nav) {
        $links = ['dashboard' => 'Dashboard', 'orders' => 'Orders', 'products' => 'Products', 'settings' => 'Settings'];
        echo '<header class="top"><a class="logo" href="index.php">Babyglee <span>admin</span></a><nav>';
        foreach ($links as $k => $l) echo '<a href="index.php?p=' . $k . '"' . (($page === $k || ($k === 'orders' && $page === 'order') || ($k === 'products' && $page === 'product')) ? ' class="on"' : '') . '>' . $l . '</a>';
        echo '</nav><form method="post" action="index.php?p=logout">' . $csrf . '<button class="link">Log out</button></form></header>';
        if ($f = flash()) echo '<p class="flash" role="status">' . h($f) . '</p>';
    }
    echo '<main class="wrap">';
}
function page_foot(): void { echo '</main></body></html>'; }

if ($page === 'orders') {
    $status = isset(BG_STATUSES[$_GET['status'] ?? '']) ? (string) $_GET['status'] : ''; $q = trim((string) ($_GET['q'] ?? ''));
    $where = []; $args = [];
    if ($status !== '') { $where[] = 'status = ?'; $args[] = $status; }
    if ($q !== '') { $where[] = '(id LIKE ? OR phone LIKE ? OR name LIKE ?)'; $like = '%' . $q . '%'; array_push($args, $like, $like, $like); }
    $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $cnt = $pdo->prepare('SELECT COUNT(*) FROM orders' . $w); $cnt->execute($args); $total = (int) $cnt->fetchColumn();
    $per = 30; $pg = max(1, (int) ($_GET['pg'] ?? 1)); $pages = max(1, (int) ceil($total / $per)); $pg = min($pg, $pages);
    $st = $pdo->prepare('SELECT * FROM orders' . $w . ' ORDER BY created_at DESC LIMIT ' . $per . ' OFFSET ' . (($pg - 1) * $per)); $st->execute($args); $rows = $st->fetchAll();
    $counts = []; foreach ($pdo->query('SELECT status, COUNT(*) c FROM orders GROUP BY status') as $r) $counts[$r['status']] = (int) $r['c'];
    page_head('Orders');
    echo '<div class="row"><h1>Orders</h1><a class="btn ghost" href="index.php?p=export">Download CSV</a></div><div class="tabs"><a href="index.php?p=orders"' . ($status === '' ? ' class="on"' : '') . '>All <b>' . array_sum($counts) . '</b></a>';
    foreach (BG_STATUSES as $k => $l) echo '<a href="index.php?p=orders&status=' . $k . '"' . ($status === $k ? ' class="on"' : '') . '>' . $l . ' <b>' . ($counts[$k] ?? 0) . '</b></a>';
    echo '</div><form class="search" method="get"><input type="hidden" name="p" value="orders"><input type="hidden" name="status" value="' . h($status) . '"><input name="q" value="' . h($q) . '" placeholder="Search order ID, phone or name" inputmode="search"><button class="btn">Search</button></form>';
    if (!$rows) echo '<p class="card mut">No orders found.</p>';
    foreach ($rows as $o) {
        $items = json_decode((string) $o['items'], true) ?: [];
        echo '<article class="card order"><div class="o-top"><a class="oid" href="index.php?p=order&id=' . rawurlencode($o['id']) . '">#' . h($o['id']) . '</a>' . badge($o['status']) . '<span class="src">' . h($o['source']) . '</span><time>' . h(date('d M, h:i A', strtotime($o['created_at']))) . '</time></div>
            <div class="o-mid"><div><b>' . h($o['name']) . '</b> · <a href="tel:' . h($o['phone']) . '">' . h($o['phone']) . '</a><br><span class="mut">' . h($o['district']) . ' · ' . h($o['area']) . '</span></div><div class="o-total">' . taka($o['total']) . '</div></div>
            <p class="o-items">' . h(implode(', ', array_map(fn($i) => $i['product'] . ' (EU ' . $i['size'] . ') × ' . $i['qty'], $items))) . '</p>
            <form method="post" class="o-act">' . $GLOBALS['csrf'] . '<input type="hidden" name="act" value="status"><input type="hidden" name="back" value="list"><input type="hidden" name="id" value="' . h($o['id']) . '"><input type="hidden" name="f_status" value="' . h($status) . '"><input type="hidden" name="f_q" value="' . h($q) . '"><select name="status" aria-label="Status for order ' . h($o['id']) . '">';
        foreach (BG_STATUSES as $k => $l) echo '<option value="' . $k . '"' . ($o['status'] === $k ? ' selected' : '') . '>' . $l . '</option>';
        echo '</select><button class="btn sm">Update</button><a class="btn sm ghost" href="index.php?p=order&id=' . rawurlencode($o['id']) . '">Details</a></form></article>';
    }
    if ($pages > 1) { echo '<div class="pager">'; for ($i = 1; $i <= $pages; $i++) echo '<a' . ($i === $pg ? ' class="on"' : '') . ' href="index.php?' . h(http_build_query(['p' => 'orders', 'status' => $status, 'q' => $q, 'pg' => $i])) . '">' . $i . '</a>'; echo '</div>'; }
    page_foot(); exit;
}

if ($page === 'order') {
    $st = $pdo->prepare('SELECT * FROM orders WHERE id = ?'); $st->execute([(string) ($_GET['id'] ?? '')]); $o = $st->fetch();
    page_head('Order');
    if (!$o) { echo '<p class="card">Order not found. <a href="index.php?p=orders">Back to orders</a></p>'; page_foot(); exit; }
    $items = json_decode((string) $o['items'], true) ?: []; $t = json_decode((string) $o['tracking'], true) ?: [];
    $log = $pdo->prepare('SELECT * FROM order_log WHERE order_id = ? ORDER BY id DESC'); $log->execute([$o['id']]);
    $wa = 'https://wa.me/' . preg_replace('/\D/', '', $o['phone']) . '?text=' . rawurlencode('Hello ' . $o['name'] . ', this is Babyglee about your order #' . $o['id'] . '.');
    echo '<p><a href="index.php?p=orders">← Orders</a></p><div class="row"><h1>Order #' . h($o['id']) . '</h1>' . badge($o['status']) . '</div><p class="mut">' . h(date('d M Y, h:i A', strtotime($o['created_at']))) . ' · from ' . h($o['source']) . ' page · ' . h($o['payment']) . '</p>
    <div class="grid2"><section class="card"><h2>Customer</h2><p><b>' . h($o['name']) . '</b><br>' . h($o['phone']) . ($o['email'] ? '<br>' . h($o['email']) : '') . '</p><p>' . nl2br(h($o['address'])) . '<br>' . h($o['district']) . ' · ' . h($o['area']) . '</p>
      <div class="acts"><a class="btn sm" href="tel:' . h($o['phone']) . '">Call</a><a class="btn sm wa" href="' . h($wa) . '" target="_blank" rel="noopener">WhatsApp</a></div></section>
    <section class="card"><h2>Items</h2><table class="tbl">';
    foreach ($items as $i) echo '<tr><td>' . h($i['product']) . '<br><span class="mut">' . h($i['sku'] ?? '') . ' · EU ' . h($i['size']) . '</span></td><td>× ' . (int) $i['qty'] . '</td><td class="r">' . taka($i['qty'] * $i['price']) . '</td></tr>';
    echo '<tr><td colspan="2">Subtotal</td><td class="r">' . taka($o['subtotal']) . '</td></tr><tr><td colspan="2">Delivery</td><td class="r">' . taka($o['delivery']) . '</td></tr><tr class="tot"><td colspan="2">Total to collect (COD)</td><td class="r">' . taka($o['total']) . '</td></tr></table></section></div>
    <div class="grid2"><section class="card"><h2>Change status</h2><form method="post">' . $csrf . '<input type="hidden" name="act" value="status"><input type="hidden" name="id" value="' . h($o['id']) . '"><label>Status<select name="status">';
    foreach (BG_STATUSES as $k => $l) echo '<option value="' . $k . '"' . ($o['status'] === $k ? ' selected' : '') . '>' . $l . '</option>';
    echo '</select></label><label>Comment (optional)<input name="note" maxlength="500" placeholder="e.g. confirmed by phone, size 28"></label><button class="btn">Update status</button></form>
      <h3>History</h3><ul class="log">';
    foreach ($log as $l) echo '<li>' . badge($l['status']) . ' <time>' . h(date('d M, h:i A', strtotime($l['created_at']))) . '</time>' . ($l['note'] ? '<br><span class="mut">' . h($l['note']) . '</span>' : '') . '</li>';
    echo '</ul></section><section class="card"><h2>Internal note</h2><form method="post">' . $csrf . '<input type="hidden" name="act" value="note"><input type="hidden" name="id" value="' . h($o['id']) . '"><textarea name="note" rows="4" maxlength="2000">' . h($o['note']) . '</textarea><button class="btn">Save note</button></form>
      <h3>Where it came from</h3><table class="tbl small">';
    foreach (['utm_source' => 'UTM source', 'utm_medium' => 'UTM medium', 'utm_campaign' => 'UTM campaign', 'utm_content' => 'UTM content', 'fbclid' => 'Meta click ID', 'gclid' => 'Google click ID', 'referrer' => 'Referrer', 'page_url' => 'Order page', 'event_id' => 'Event ID'] as $k => $l) if (!empty($t[$k])) echo '<tr><td>' . $l . '</td><td class="brk">' . h($t[$k]) . '</td></tr>';
    echo '<tr><td>Meta CAPI</td><td>' . h($o['capi_status'] ?: 'not configured') . '</td></tr><tr><td>IP</td><td>' . h($o['ip']) . '</td></tr></table></section></div>';
    page_foot(); exit;
}

if ($page === 'products') {
    $rows = products(false);
    page_head('Products');
    echo '<div class="row"><h1>Products</h1><a class="btn" href="index.php?p=product">Add product</a></div><div class="pgrid">';
    foreach ($rows as $p) echo '<a class="card prod' . ($p['active'] ? '' : ' off') . '" href="index.php?p=product&id=' . (int) $p['id'] . '"><img src="../' . h($p['img']) . '" alt="" loading="lazy"><b>' . h($p['name']) . '</b><span class="mut">' . h($p['sku']) . '</span><span><s>' . taka($p['list_price']) . '</s> <b class="pr">' . taka($p['price']) . '</b></span>' . ($p['active'] ? '' : '<span class="st st-cancelled">Hidden</span>') . '</a>';
    echo '</div>'; page_foot(); exit;
}

if ($page === 'product') {
    $id = (int) ($_GET['id'] ?? 0); $p = null;
    if ($id) { $st = $pdo->prepare('SELECT * FROM products WHERE id = ?'); $st->execute([$id]); $p = $st->fetch() ?: null; }
    $v = $p ?: ['name' => 'Babyglee Bounce — ', 'color' => '', 'sku' => 'BSTD-01-', 'price' => 1290, 'list_price' => 1800, 'price_usd' => 15, 'color_hex' => '#24babc', 'sizes' => implode(',', BG_SIZES), 'active' => 1, 'sort' => 10, 'img' => ''];
    $have = array_map('intval', explode(',', (string) $v['sizes']));
    page_head($p ? 'Edit product' : 'Add product');
    echo '<p><a href="index.php?p=products">← Products</a></p><h1>' . ($p ? 'Edit product' : 'Add product') . '</h1><form method="post" enctype="multipart/form-data" class="card form">' . $csrf . '<input type="hidden" name="act" value="product"><input type="hidden" name="id" value="' . $id . '">
      <label>Product name<input name="name" required maxlength="150" value="' . h($v['name']) . '"></label>
      <div class="f2"><label>Colour name<input name="color" maxlength="80" value="' . h($v['color']) . '" placeholder="e.g. Grey"></label><label>Colour dot<input type="color" name="color_hex" value="' . h($v['color_hex']) . '"></label></div>
      <label>SKU (item ID sent to GA4 / Meta)<input name="sku" required maxlength="60" value="' . h($v['sku']) . '"></label>
      <div class="f3"><label>Price (৳)<input type="number" name="price" min="1" required inputmode="numeric" value="' . (int) $v['price'] . '"></label><label>Old price (৳)<input type="number" name="list_price" min="0" inputmode="numeric" value="' . (int) $v['list_price'] . '"></label><label>USD price<input type="number" name="price_usd" min="0" inputmode="numeric" value="' . (int) $v['price_usd'] . '"></label></div>
      <fieldset><legend>Sizes in stock (EU)</legend><div class="sizes">';
    foreach (BG_SIZES as $s) echo '<label class="chk"><input type="checkbox" name="sizes[]" value="' . $s . '"' . (in_array($s, $have, true) ? ' checked' : '') . '><span>' . $s . '</span></label>';
    echo '</div><p class="mut">Untick a size when it is out of stock: customers can no longer choose it.</p></fieldset>
      <label>Product image (JPG, PNG or WebP, up to 3 MB; a cut-out photo on a transparent background looks best)' . ($v['img'] ? '<img class="cur" src="../' . h($v['img']) . '" alt="">' : '') . '<input type="file" name="image" accept="image/jpeg,image/png,image/webp"' . ($p ? '' : ' required') . '></label>
      <div class="f2"><label>Order in shop (small number first)<input type="number" name="sort" value="' . (int) $v['sort'] . '"></label><label class="chk big"><input type="checkbox" name="active" value="1"' . ($v['active'] ? ' checked' : '') . '><span>Show in shop</span></label></div>
      <button class="btn">' . ($p ? 'Save product' : 'Add product') . '</button></form>';
    if ($p) echo '<form method="post" class="card danger" onsubmit="return confirm(\'Remove this product from the shop? Past orders are kept.\')">' . $csrf . '<input type="hidden" name="act" value="product_delete"><input type="hidden" name="id" value="' . $id . '"><h2>Remove product</h2><p class="mut">To hide it for a while, untick "Show in shop" instead.</p><button class="btn red">Remove product</button></form>';
    page_foot(); exit;
}

if ($page === 'settings') {
    page_head('Settings');
    $s = fn($k) => h(setting($k));
    echo '<h1>Settings</h1><form method="post" class="card form">' . $csrf . '<input type="hidden" name="act" value="settings">
      <h2>Google Tag Manager</h2><p class="mut">All tracking tags (GA4, Meta Pixel, Google Ads, Clarity) are managed inside GTM. Paste the container ID here to switch tracking on.</p>
      <label>GTM container ID<input name="gtm_id" value="' . $s('gtm_id') . '" placeholder="GTM-XXXXXXX" autocapitalize="characters"></label>
      <label>Server-side GTM loader URL (optional)<input name="gtm_src" value="' . $s('gtm_src') . '" placeholder="https://sgtm.yourdomain.com/gtm.js" inputmode="url"></label>
      <h2>Delivery &amp; contact</h2><div class="f2"><label>Delivery inside Dhaka (৳)<input type="number" name="delivery_dhaka" min="0" inputmode="numeric" value="' . $s('delivery_dhaka') . '"></label><label>Delivery outside Dhaka (৳)<input type="number" name="delivery_outside" min="0" inputmode="numeric" value="' . $s('delivery_outside') . '"></label></div>
      <label>WhatsApp number (with country code, digits only)<input name="whatsapp" value="' . $s('whatsapp') . '" inputmode="numeric"></label>
      <h2>Meta Conversions API (server-side Purchase)</h2><p class="mut">Optional. When both fields are filled, every order is also sent to Meta from the server with the same event ID as the browser event, so Meta counts it once. Leave empty if you send Purchase from a server-side GTM container instead (do not do both).</p>
      <div class="f2"><label>Pixel (dataset) ID<input name="meta_pixel_id" value="' . $s('meta_pixel_id') . '" inputmode="numeric"></label><label>Graph API version<input name="meta_api_version" value="' . $s('meta_api_version') . '"></label></div>
      <label>Access token ' . (setting('meta_capi_token') !== '' ? '(saved; leave empty to keep it, type REMOVE to delete it)' : '(not set)') . '<input name="meta_capi_token" value="" autocomplete="off" placeholder="' . (setting('meta_capi_token') !== '' ? '••••••••' : 'EAAG…') . '"></label>
      <label>Test event code (only while testing in Events Manager)<input name="meta_test_code" value="' . $s('meta_test_code') . '" placeholder="TEST12345"></label>
      <button class="btn">Save settings</button></form>
    <div class="grid2"><form method="post" class="card form">' . $csrf . '<input type="hidden" name="act" value="password"><h2>Change password</h2><label>Current password<input type="password" name="current" required autocomplete="current-password"></label><label>New password (10+ characters)<input type="password" name="password" required minlength="10" autocomplete="new-password"></label><label>Repeat new password<input type="password" name="password2" required autocomplete="new-password"></label><button class="btn">Change password</button></form>
    <section class="card"><h2>Backup</h2><p class="mut">Download a copy of all orders, products and settings. Do this regularly and keep the file safe: it contains customer details. Passwords and API tokens are not included.</p><div class="acts"><a class="btn" href="index.php?p=backup">Download backup (JSON)</a><a class="btn ghost" href="index.php?p=export">Orders as CSV</a></div></section></div>';
    page_foot(); exit;
}

/* ---------------- dashboard / analytics ---------------- */
$days = (int) ($_GET['days'] ?? 30); if (!in_array($days, [7, 30, 90], true)) $days = 30;
$since = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));
$st = $pdo->prepare('SELECT id, created_at, status, source, district, total, subtotal, items, tracking FROM orders WHERE created_at >= ? ORDER BY created_at'); $st->execute([$since]); $rows = $st->fetchAll();
$dead = ['cancelled', 'returned'];
$k = ['orders' => 0, 'value' => 0, 'delivered' => 0, 'delivered_value' => 0, 'pending' => 0, 'cancelled' => 0, 'pairs' => 0, 'today' => 0, 'today_value' => 0];
$byDay = []; for ($i = $days - 1; $i >= 0; $i--) $byDay[date('Y-m-d', strtotime("-$i days"))] = ['n' => 0, 'v' => 0];
$byStatus = array_fill_keys(array_keys(BG_STATUSES), 0); $byProduct = []; $bySize = []; $bySource = []; $byDistrict = []; $byUtm = [];
foreach ($rows as $o) {
    $byStatus[$o['status']] = ($byStatus[$o['status']] ?? 0) + 1;
    $live = !in_array($o['status'], $dead, true); $day = substr($o['created_at'], 0, 10);
    $k['orders']++; if ($o['status'] === 'pending') $k['pending']++; if (!$live) $k['cancelled']++;
    if ($o['status'] === 'delivered') { $k['delivered']++; $k['delivered_value'] += (int) $o['total']; }
    if (!$live) continue;
    $k['value'] += (int) $o['total']; if (isset($byDay[$day])) { $byDay[$day]['n']++; $byDay[$day]['v'] += (int) $o['total']; }
    if ($day === date('Y-m-d')) { $k['today']++; $k['today_value'] += (int) $o['total']; }
    foreach (json_decode((string) $o['items'], true) ?: [] as $i) {
        $k['pairs'] += (int) $i['qty'];
        $pn = preg_replace('/^Babyglee\s+/u', '', (string) $i['product']); $byProduct[$pn] = ($byProduct[$pn] ?? 0) + (int) $i['qty'];
        $bySize['EU ' . $i['size']] = ($bySize['EU ' . $i['size']] ?? 0) + (int) $i['qty'];
    }
    $t = json_decode((string) $o['tracking'], true) ?: [];
    $src = $t['utm_source'] ?? (!empty($t['fbclid']) ? 'facebook (click id)' : (!empty($t['gclid']) ? 'google (click id)' : 'direct / unknown'));
    foreach ([[&$bySource, $o['source'] === 'landing' ? 'Landing pages' : 'Store'], [&$byDistrict, $o['district'] ?: 'Unknown'], [&$byUtm, $src]] as $pair) {
        $pair[0][$pair[1]] = ($pair[0][$pair[1]] ?? ['n' => 0, 'v' => 0]); $pair[0][$pair[1]]['n']++; $pair[0][$pair[1]]['v'] += (int) $o['total'];
    }
}
$valid = $k['orders'] - $k['cancelled'];
function bars(array $data, bool $money = true, int $limit = 8): string {
    if (!$data) return '<p class="mut">No data yet.</p>';
    uasort($data, fn($a, $b) => (is_array($b) ? $b['n'] : $b) <=> (is_array($a) ? $a['n'] : $a)); $data = array_slice($data, 0, $limit, true);
    $max = max(array_map(fn($x) => is_array($x) ? $x['n'] : $x, $data)) ?: 1; $out = '<ul class="bars">';
    foreach ($data as $label => $x) { $n = is_array($x) ? $x['n'] : $x; $out .= '<li><span class="bl">' . h($label) . '</span><span class="bt"><i style="width:' . round($n / $max * 100) . '%"></i></span><span class="bn">' . $n . ($money && is_array($x) ? ' · ' . taka($x['v']) : '') . '</span></li>'; }
    return $out . '</ul>';
}
page_head('Dashboard');
echo '<div class="row"><h1>Dashboard</h1><div class="tabs sm">';
foreach ([7, 30, 90] as $d) echo '<a href="index.php?days=' . $d . '"' . ($days === $d ? ' class="on"' : '') . '>' . $d . ' days</a>';
echo '</div></div>';
if ($k['pending']) echo '<a class="alert" href="index.php?p=orders&status=pending"><b>' . $k['pending'] . '</b> order' . ($k['pending'] > 1 ? 's' : '') . ' waiting to be confirmed →</a>';
if (setting('gtm_id') === '') echo '<a class="alert soft" href="index.php?p=settings">Tracking is off: add your GTM container ID in Settings →</a>';
echo '<div class="kpis"><div class="card kpi"><span>Today</span><b>' . $k['today'] . '</b><small>' . taka($k['today_value']) . '</small></div>
  <div class="card kpi"><span>Orders (' . $days . 'd)</span><b>' . $valid . '</b><small>' . $k['cancelled'] . ' cancelled / returned</small></div>
  <div class="card kpi"><span>Order value</span><b>' . taka($k['value']) . '</b><small>avg ' . taka($valid ? $k['value'] / $valid : 0) . '</small></div>
  <div class="card kpi"><span>Delivered</span><b>' . $k['delivered'] . '</b><small>' . taka($k['delivered_value']) . ' collected</small></div>
  <div class="card kpi"><span>Pairs sold</span><b>' . $k['pairs'] . '</b><small>' . ($k['orders'] ? round($k['cancelled'] / $k['orders'] * 100) : 0) . '% cancel rate</small></div></div>';
$maxDay = max(array_map(fn($d) => $d['n'], $byDay)) ?: 1;
echo '<section class="card"><h2>Orders per day</h2><div class="chart" role="img" aria-label="Orders per day for the last ' . $days . ' days">';
foreach ($byDay as $d => $x) echo '<div class="col" title="' . h(date('d M', strtotime($d))) . ': ' . $x['n'] . ' orders, ' . taka($x['v']) . '"><i style="height:' . max(2, round($x['n'] / $maxDay * 100)) . '%"></i></div>';
echo '</div><div class="axis"><span>' . h(date('d M', strtotime(array_key_first($byDay)))) . '</span><span>busiest day: ' . $maxDay . ' orders</span><span>' . h(date('d M', strtotime(array_key_last($byDay)))) . '</span></div></section>
<div class="grid2"><section class="card"><h2>By status</h2>' . bars(array_combine(array_map(fn($s) => BG_STATUSES[$s] ?? $s, array_keys($byStatus)), $byStatus), false) . '</section><section class="card"><h2>Store vs landing pages</h2>' . bars($bySource) . '</section>
<section class="card"><h2>Top products (pairs)</h2>' . bars($byProduct, false) . '</section><section class="card"><h2>Sizes sold</h2>' . bars($bySize, false, 16) . '</section>
<section class="card"><h2>Traffic source</h2>' . bars($byUtm) . '</section><section class="card"><h2>Top districts</h2>' . bars($byDistrict) . '</section></div>
<p class="mut">Numbers exclude cancelled and returned orders, except "By status". Order value includes delivery charges.</p>';
page_foot();
