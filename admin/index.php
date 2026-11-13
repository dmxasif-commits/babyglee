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

/* ---------------- first-time setup, login, roles ---------------- */
$error = '';
$validUser = fn($u) => (bool) preg_match('/^[a-z0-9_.-]{3,40}$/', $u);
if (!(int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()) {
    if ($isPost) {
        $pw = (string) ($_POST['password'] ?? ''); $un = strtolower(trim((string) ($_POST['username'] ?? 'admin')));
        if (!hash_equals((string) (cfg()['setup_key'] ?? ''), (string) ($_POST['setup_key'] ?? '')) || strpos((string) cfg()['setup_key'], 'CHANGE-THIS') === 0) $error = 'Setup key is wrong (or still the sample value in api/config.php).';
        elseif (!$validUser($un)) $error = 'Username: 3-40 characters, only small letters, numbers, dot, dash or underscore.';
        elseif (strlen($pw) < 10) $error = 'Password must be at least 10 characters.';
        elseif ($pw !== ($_POST['password2'] ?? '')) $error = 'The two passwords do not match.';
        else {
            $pdo->prepare("INSERT INTO users (username, name, pass_hash, role, active, created_at) VALUES (?, ?, ?, 'admin', 1, ?)")->execute([$un, mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 80) ?: 'Admin', password_hash($pw, PASSWORD_DEFAULT), now()]);
            session_regenerate_id(true); $_SESSION['uid'] = (int) $pdo->lastInsertId(); flash('Admin account created. Welcome!'); go();
        }
    }
    page_head('Set up admin', false);
    echo '<form method="post" class="card auth"><h1>Set up admin</h1><p class="mut">First visit: create the owner account. The setup key is the one you wrote in <code>api/config.php</code>.</p>' . ($error ? '<p class="err">' . h($error) . '</p>' : '') . $csrf .
        '<label>Setup key<input name="setup_key" required autocomplete="off"></label><label>Username<input name="username" required value="admin" autocapitalize="none" autocomplete="username"></label><label>Your name<input name="name" value="Admin" maxlength="80"></label>
        <label>New password (10+ characters)<input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
        <label>Repeat password<input type="password" name="password2" required autocomplete="new-password"></label><button class="btn">Create admin</button></form>';
    page_foot(); exit;
}
$me = null;
if (!empty($_SESSION['uid'])) { $u = $pdo->prepare('SELECT * FROM users WHERE id = ? AND active = 1'); $u->execute([(int) $_SESSION['uid']]); $me = $u->fetch() ?: null; }
if (!$me) {
    unset($_SESSION['uid']);
    if ($isPost) {
        $ip = client_ip();
        $c = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND created_at > ?'); $c->execute([$ip, date('Y-m-d H:i:s', time() - 900)]);
        $u = $pdo->prepare('SELECT * FROM users WHERE username = ? AND active = 1'); $u->execute([strtolower(trim((string) ($_POST['username'] ?? '')))]); $cand = $u->fetch();
        if ((int) $c->fetchColumn() >= 8) $error = 'Too many attempts. Wait 15 minutes and try again.';
        elseif ($cand && password_verify((string) ($_POST['password'] ?? ''), $cand['pass_hash'])) { session_regenerate_id(true); $_SESSION['uid'] = (int) $cand['id']; $_SESSION['csrf'] = bin2hex(random_bytes(16)); go(); }
        else { $pdo->prepare('INSERT INTO login_attempts (ip, created_at) VALUES (?, ?)')->execute([$ip, now()]); $error = 'Wrong username or password.'; }
    }
    page_head('Log in', false);
    echo '<form method="post" class="card auth"><h1>Babyglee admin</h1>' . ($error ? '<p class="err">' . h($error) . '</p>' : '') . $csrf .
        '<label>Username<input name="username" required autofocus autocapitalize="none" autocomplete="username" value="' . h($_POST['username'] ?? '') . '"></label><label>Password<input type="password" name="password" required autocomplete="current-password"></label><button class="btn">Log in</button></form>';
    page_foot(); exit;
}
$isAdmin = $me['role'] === 'admin';
if ($page === 'logout' && $isPost) { $_SESSION = []; session_destroy(); go(); }
/* Staff can work on orders only. Products, prices, profit, ads, settings, exports and accounts are for admins. */
$staffPages = ['dashboard', 'orders', 'order', 'leads', 'lead', 'print', 'neworder', 'account'];
$staffActs = ['status', 'ship', 'bulk', 'lead_cancel', 'lead_reopen', 'lead_delete', 'lead_convert', 'note', 'neworder', 'password'];
if (!$isAdmin && (!in_array($page, $staffPages, true) || ($isPost && !in_array((string) ($_POST['act'] ?? ''), $staffActs, true)))) { flash('That page is only for admins.'); go(); }

/* Creates an order from the admin panel (manual order or a confirmed incomplete order). Prices, delivery and stock are handled here. */
function make_order(PDO $pdo, array $in, string $source, string $status, array $tr, string $email, string $ip, string $note, string $logNote, string $by): array {
    $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 120); $phone = bd_phone((string) ($in['phone'] ?? '')); $district = mb_substr(trim((string) ($in['district'] ?? '')), 0, 60); $address = mb_substr(trim((string) ($in['address'] ?? '')), 0, 600);
    $areaKey = ($in['area'] ?? '') === 'dhaka' ? 'dhaka' : 'outside'; $cat = products(true); $items = []; $sub = 0; $cost = 0;
    foreach ((array) ($in['it_slug'] ?? []) as $n => $slug) {
        $size = (int) ($in['it_size'][$n] ?? 0); $qty = max(1, min(10, (int) ($in['it_qty'][$n] ?? 1))); $p = $cat[(string) $slug] ?? null;
        if (!$p || !isset($p['variants'][$size])) return ['err' => 'Choose a size that exists for each product.'];
        if ($p['track'] && $p['variants'][$size] < $qty) return ['err' => $p['name'] . ' EU ' . $size . ': only ' . max(0, $p['variants'][$size]) . ' in stock.'];
        $items[] = ['slug' => (string) $slug, 'sku' => $p['sku'], 'product' => $p['name'], 'size' => $size, 'qty' => $qty, 'price' => (int) $p['price'], 'cost' => (int) $p['cost']];
        $sub += $qty * (int) $p['price']; $cost += $qty * (int) $p['cost'];
    }
    if (mb_strlen($name) < 2 || $phone === '' || $district === '' || mb_strlen($address) < 5 || !$items) return ['err' => 'Name, a valid 11-digit mobile number, district, address and at least one product are required.'];
    $oid = 'BG' . strtoupper(substr(base_convert((string) (int) (microtime(true) * 1000), 10, 36), -7));
    $delivery = (int) setting($areaKey === 'dhaka' ? 'delivery_dhaka' : 'delivery_outside'); $tr['event_id'] = 'purchase_' . $oid;
    $pdo->beginTransaction();
    $take = $pdo->prepare('UPDATE variants SET stock = stock - ? WHERE product_id = ? AND size = ? AND stock >= ?');
    foreach ($items as $i) { $p = $cat[$i['slug']]; if (!$p['track']) continue; $take->execute([$i['qty'], $p['id'], $i['size'], $i['qty']]); if ($take->rowCount() !== 1) { $pdo->rollBack(); return ['err' => $p['name'] . ' EU ' . $i['size'] . ' just sold out.']; } }
    $pdo->prepare('INSERT INTO orders (id, created_at, status, source, name, phone, email, district, address, area, subtotal, delivery, total, items, tracking, ip, user_agent, note, cost, stock_held, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?)')
        ->execute([$oid, now(), $status, $source, $name, '+' . $phone, $email, $district, $address, $areaKey === 'dhaka' ? 'Inside Dhaka' : 'Outside Dhaka', $sub, $delivery, $sub + $delivery,
            json_encode($items, JSON_UNESCAPED_UNICODE), json_encode($tr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $ip, '', $note, $cost, $by]);
    $pdo->prepare('INSERT INTO order_log (order_id, created_at, status, note, by_user) VALUES (?,?,?,?,?)')->execute([$oid, now(), $status, $logNote, $by]);
    $pdo->prepare('DELETE FROM leads WHERE phone = ?')->execute(['+' . $phone]);
    $pdo->commit();
    return ['id' => $oid];
}

/* ---------------- actions (POST) ---------------- */
if ($isPost) {
    $act = (string) ($_POST['act'] ?? '');
    if ($act === 'status') {
        $id = (string) $_POST['id']; $st = (string) $_POST['status']; $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 500);
        if (isset(BG_STATUSES[$st])) {
            $cur = $pdo->prepare('SELECT status FROM orders WHERE id = ?'); $cur->execute([$id]); $was = $cur->fetchColumn();
            if ($was !== false && ($was !== $st || $note !== '')) {
                $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([$st, $id]);
                $pdo->prepare('INSERT INTO order_log (order_id, created_at, status, note, by_user) VALUES (?,?,?,?,' . $pdo->quote($me['name']) . ')')->execute([$id, now(), $st, $note]);
                order_stock_sync($pdo, $id, $st);
                flash('Order #' . $id . ' is now ' . BG_STATUSES[$st] . '.');
            }
        }
        go((string) ($_POST['back'] ?? '') === 'list' ? 'p=orders&' . http_build_query(['status' => $_POST['f_status'] ?? '', 'q' => $_POST['f_q'] ?? '']) : 'p=order&id=' . rawurlencode($id));
    }
    if ($act === 'ship') {
        $pdo->prepare('UPDATE orders SET courier = ?, tracking_code = ? WHERE id = ?')->execute([mb_substr(trim((string) $_POST['courier']), 0, 60), mb_substr(trim((string) $_POST['tracking_code']), 0, 80), (string) $_POST['id']]);
        flash('Courier details saved.'); go('p=order&id=' . rawurlencode((string) $_POST['id']));
    }
    if ($act === 'bulk') {
        $ids = array_slice(array_values(array_filter(array_map('strval', (array) ($_POST['ids'] ?? [])), fn($x) => (bool) preg_match('/^[A-Z0-9]{5,20}$/', $x))), 0, 100);
        $do = (string) ($_POST['do'] ?? '');
        if (!$ids) { flash('Tick at least one order first.'); go('p=orders'); }
        if ($do === 'invoice' || $do === 'sticker') go('p=print&type=' . $do . '&ids=' . implode(',', $ids));
        if (strpos($do, 'status_') === 0 && isset(BG_STATUSES[$s = substr($do, 7)])) {
            $n = 0; $cur = $pdo->prepare('SELECT status FROM orders WHERE id = ?');
            foreach ($ids as $id) {
                $cur->execute([$id]); $was = $cur->fetchColumn();
                if ($was === false || $was === $s) continue;
                $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([$s, $id]);
                $pdo->prepare('INSERT INTO order_log (order_id, created_at, status, note, by_user) VALUES (?,?,?,?,' . $pdo->quote($me['name']) . ')')->execute([$id, now(), $s, 'Bulk update']); order_stock_sync($pdo, $id, $s); $n++;
            }
            flash($n . ' order' . ($n === 1 ? '' : 's') . ' marked as ' . BG_STATUSES[$s] . '.');
        }
        go('p=orders');
    }
    if (in_array($act, ['lead_cancel', 'lead_reopen', 'lead_delete'], true)) {
        $lid = (int) $_POST['id'];
        if ($act === 'lead_delete') { $pdo->prepare('DELETE FROM leads WHERE id = ?')->execute([$lid]); flash('Incomplete order deleted.'); go('p=leads'); }
        $pdo->prepare('UPDATE leads SET status = ?, note = ? WHERE id = ?')->execute([$act === 'lead_cancel' ? 'cancelled' : 'new', mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 300), $lid]);
        flash($act === 'lead_cancel' ? 'Marked as cancelled. It stays in Incomplete > Cancelled.' : 'Moved back to follow up.'); go('p=leads');
    }
    if ($act === 'lead_convert') {
        $lid = (int) $_POST['id']; $ls = $pdo->prepare('SELECT * FROM leads WHERE id = ?'); $ls->execute([$lid]); $lead = $ls->fetch();
        if (!$lead) { flash('This incomplete order no longer exists.'); go('p=leads'); }
        $res = make_order($pdo, $_POST, (string) $lead['source'], 'confirmed', json_decode((string) $lead['tracking'], true) ?: [], (string) $lead['email'], (string) $lead['ip'], 'Recovered from an incomplete order', 'Created from an incomplete order (confirmed by phone)', $me['name']);
        if (isset($res['err'])) { flash('Not confirmed: ' . $res['err']); go('p=lead&id=' . $lid); }
        $pdo->prepare('DELETE FROM leads WHERE id = ?')->execute([$lid]);
        flash('Order #' . $res['id'] . ' created and confirmed.'); go('p=order&id=' . $res['id']);
    }
    if ($act === 'neworder') {
        $channels = ['phone' => 'phone call', 'messenger' => 'facebook inbox', 'whatsapp' => 'whatsapp', 'instagram' => 'instagram inbox', 'shop' => 'walk-in / other'];
        $ch = $channels[$_POST['channel'] ?? ''] ?? 'phone call'; $status = ($_POST['status'] ?? '') === 'pending' ? 'pending' : 'confirmed';
        $res = make_order($pdo, $_POST, 'manual', $status, ['utm_source' => $ch, 'utm_medium' => 'manual'], '', '', mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 2000), 'Order entered by staff (' . $ch . ')', $me['name']);
        if (isset($res['err'])) { $_SESSION['neworder'] = $_POST; flash('Not saved: ' . $res['err']); go('p=neworder'); }
        unset($_SESSION['neworder']); flash('Order #' . $res['id'] . ' created.'); go('p=order&id=' . $res['id']);
    }
    if ($act === 'spend_add') {
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['day'] ?? '')) ? (string) $_POST['day'] : date('Y-m-d'); $camp = mb_substr(trim((string) ($_POST['campaign'] ?? '')), 0, 190); $amt = (int) ($_POST['amount'] ?? 0);
        if ($camp === '' || $amt < 1) flash('Not saved: write the campaign name and an amount.');
        else { $pdo->prepare('INSERT INTO ad_spend (day, campaign, amount, note) VALUES (?,?,?,?)')->execute([$day, $camp, $amt, mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 200)]); flash('Ad spend saved.'); }
        go('p=utm');
    }
    if ($act === 'spend_delete') { $pdo->prepare('DELETE FROM ad_spend WHERE id = ?')->execute([(int) $_POST['id']]); flash('Ad spend entry deleted.'); go('p=utm'); }
    if ($act === 'user_add') {
        $un = strtolower(trim((string) ($_POST['username'] ?? ''))); $pw = (string) ($_POST['password'] ?? ''); $nm = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 80);
        $ex = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?'); $ex->execute([$un]);
        if (!$validUser($un)) flash('Not added: username needs 3-40 small letters, numbers, dot, dash or underscore.');
        elseif ((int) $ex->fetchColumn()) flash('Not added: that username already exists.');
        elseif ($nm === '' || strlen($pw) < 10) flash('Not added: give a name and a password of 10+ characters.');
        else { $pdo->prepare('INSERT INTO users (username, name, pass_hash, role, active, created_at) VALUES (?,?,?,?,1,?)')->execute([$un, $nm, password_hash($pw, PASSWORD_DEFAULT), ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'staff', now()]); flash('Account "' . $un . '" added.'); }
        go('p=settings');
    }
    if ($act === 'user_toggle' || $act === 'user_pass') {
        $uid = (int) $_POST['id'];
        if ($act === 'user_toggle') { if ($uid === (int) $me['id']) flash('You cannot switch off your own account.'); else { $pdo->prepare('UPDATE users SET active = 1 - active WHERE id = ?')->execute([$uid]); flash('Account updated.'); } }
        else { $pw = (string) ($_POST['password'] ?? ''); if (strlen($pw) < 10) flash('Password must be 10+ characters.'); else { $pdo->prepare('UPDATE users SET pass_hash = ? WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $uid]); flash('Password reset.'); } }
        go('p=settings');
    }
    if ($act === 'note') {
        $pdo->prepare('UPDATE orders SET note = ? WHERE id = ?')->execute([mb_substr(trim((string) $_POST['note']), 0, 2000), (string) $_POST['id']]);
        flash('Note saved.'); go('p=order&id=' . rawurlencode((string) $_POST['id']));
    }
    if ($act === 'product') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = mb_substr(trim((string) $_POST['name']), 0, 150); $color = mb_substr(trim((string) $_POST['color']), 0, 80);
        $sku = mb_substr(preg_replace('/[^A-Za-z0-9._-]/', '', (string) $_POST['sku']) ?? '', 0, 60);
        $price = max(0, (int) $_POST['price']); $list = max($price, (int) $_POST['list_price']); $usd = max(0, (int) $_POST['price_usd']); $cost = max(0, (int) ($_POST['cost'] ?? 0));
        $hex = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $_POST['color_hex']) ? (string) $_POST['color_hex'] : '#24babc';
        $variants = []; $vs = (array) ($_POST['var_size'] ?? []); $vq = (array) ($_POST['var_stock'] ?? []);
        foreach ($vs as $i => $sz) { $sz = (int) $sz; if ($sz >= 10 && $sz <= 60) $variants[$sz] = max(0, (int) ($vq[$i] ?? 0)); }
        ksort($variants); $sizes = implode(',', array_keys($variants)); $track = empty($_POST['track_stock']) ? 0 : 1;
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
        $pid = $id ?: (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE products SET cost = ?, track_stock = ? WHERE id = ?')->execute([$cost, $track, $pid]);
        $pdo->prepare('DELETE FROM variants WHERE product_id = ?')->execute([$pid]);
        $iv = $pdo->prepare('INSERT INTO variants (product_id, size, stock) VALUES (?, ?, ?)');
        foreach ($variants as $sz => $q) $iv->execute([$pid, $sz, $q]);
        go('p=products');
    }
    if ($act === 'product_delete') {
        $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([(int) $_POST['id']]);
        $pdo->prepare('DELETE FROM variants WHERE product_id = ?')->execute([(int) $_POST['id']]);
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
            'biz_name' => mb_substr(trim((string) $_POST['biz_name']), 0, 80) ?: BG_DEFAULTS['biz_name'], 'biz_phone' => mb_substr(trim((string) $_POST['biz_phone']), 0, 40),
            'biz_address' => mb_substr(trim((string) $_POST['biz_address']), 0, 200), 'invoice_note' => mb_substr(trim((string) $_POST['invoice_note']), 0, 300),
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
        if (!password_verify((string) $_POST['current'], $me['pass_hash'])) flash('Current password is wrong.');
        elseif (strlen($pw) < 10 || $pw !== $_POST['password2']) flash('New password must be 10+ characters and typed the same twice.');
        else { $pdo->prepare('UPDATE users SET pass_hash = ? WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $me['id']]); flash('Password changed.'); }
        go($isAdmin ? 'p=settings' : 'p=account');
    }
    go();
}

/* ---------------- downloads ---------------- */
if ($page === 'export') {
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="babyglee-orders-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Order ID', 'Date', 'Status', 'Source', 'Name', 'Phone', 'Email', 'District', 'Address', 'Area', 'Items', 'Subtotal', 'Delivery', 'Total', 'UTM source', 'UTM campaign', 'UTM ad set', 'UTM ad', 'Courier', 'Tracking code', 'Purchase cost', 'Note']);
    $safe = fn($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : $v;   // stop spreadsheet formula injection
    foreach ($pdo->query('SELECT * FROM orders ORDER BY created_at DESC') as $o) {
        $items = implode(' | ', array_map(fn($i) => $i['product'] . ' EU ' . $i['size'] . ' x' . $i['qty'], json_decode((string) $o['items'], true) ?: []));
        $t = json_decode((string) $o['tracking'], true) ?: [];
        fputcsv($out, array_map($safe, [$o['id'], $o['created_at'], $o['status'], $o['source'], $o['name'], $o['phone'], $o['email'], $o['district'], $o['address'], $o['area'], $items, $o['subtotal'], $o['delivery'], $o['total'], $t['utm_source'] ?? '', $t['utm_campaign'] ?? '', $t['utm_term'] ?? '', $t['utm_content'] ?? '', $o['courier'], $o['tracking_code'], $o['cost'], $o['note']]));
    }
    exit;
}
if ($page === 'backup') {
    header('Content-Type: application/json; charset=utf-8'); header('Content-Disposition: attachment; filename="babyglee-backup-' . date('Y-m-d-His') . '.json"');
    $settings = []; foreach ($pdo->query('SELECT k, v FROM settings') as $r) if (!in_array($r['k'], ['admin_hash', 'meta_capi_token'], true)) $settings[$r['k']] = $r['v'];
    echo json_encode(['created_at' => now(), 'note' => 'Babyglee backup. Passwords and API tokens are not included.', 'settings' => $settings, 'variants' => $pdo->query('SELECT * FROM variants')->fetchAll(), 'leads' => $pdo->query('SELECT * FROM leads')->fetchAll(), 'ad_spend' => $pdo->query('SELECT * FROM ad_spend')->fetchAll(),
        'products' => $pdo->query('SELECT * FROM products')->fetchAll(), 'orders' => $pdo->query('SELECT * FROM orders')->fetchAll(), 'order_log' => $pdo->query('SELECT * FROM order_log')->fetchAll()], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/* ---------------- pages ---------------- */
function page_head(string $title, bool $nav = true): void {
    global $page, $csrf, $me, $isAdmin;
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><title>' . h($title) . ' — Babyglee admin</title><link rel="stylesheet" href="admin.css"></head><body>';
    if ($nav) {
        $links = ['dashboard' => 'Dashboard', 'orders' => 'Orders', 'leads' => 'Incomplete'] + ($isAdmin ? ['utm' => 'Ads', 'products' => 'Products', 'settings' => 'Settings'] : []);
        echo '<header class="top"><a class="logo" href="index.php">Babyglee <span>admin</span></a><nav>';
        foreach ($links as $k => $l) echo '<a href="index.php?p=' . $k . '"' . (($page === $k || ($k === 'orders' && ($page === 'order' || $page === 'neworder')) || ($k === 'products' && $page === 'product') || ($k === 'leads' && $page === 'lead')) ? ' class="on"' : '') . '>' . $l . '</a>';
        echo '</nav><form method="post" action="index.php?p=logout"><a class="who" href="index.php?p=' . ($isAdmin ? 'settings' : 'account') . '">' . h($me['name']) . '</a>' . $csrf . '<button class="link">Log out</button></form></header>';
        if ($f = flash()) echo '<p class="flash" role="status">' . h($f) . '</p>';
    }
    echo '<main class="wrap">';
}
function page_foot(): void { echo '</main></body></html>'; }


/* ---------------- print: A4 invoice or 3 x 4 inch courier sticker (one or many orders) ---------------- */
if ($page === 'print') {
    $type = ($_GET['type'] ?? '') === 'sticker' ? 'sticker' : 'invoice';
    $ids = array_slice(array_values(array_filter(explode(',', (string) ($_GET['ids'] ?? '')), fn($x) => (bool) preg_match('/^[A-Z0-9]{5,20}$/', $x))), 0, 100);
    $orders = [];
    if ($ids) { $st = $pdo->prepare('SELECT * FROM orders WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY created_at'); $st->execute($ids); $orders = $st->fetchAll(); }
    if (!$orders) { page_head('Print'); echo '<p class="card">No orders selected. <a href="index.php?p=orders">Back to orders</a></p>'; page_foot(); exit; }
    $biz = ['name' => setting('biz_name'), 'phone' => setting('biz_phone'), 'address' => setting('biz_address')];
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><title>' . ($type === 'sticker' ? 'Stickers' : 'Invoices') . ' (' . count($orders) . ') — Babyglee</title><link rel="stylesheet" href="print.css"></head><body class="' . $type . '">
    <div class="bar noprint"><a href="index.php?p=orders">← Orders</a><b>' . count($orders) . ' ' . ($type === 'sticker' ? 'sticker' : 'invoice') . (count($orders) > 1 ? 's' : '') . '</b><span>' . ($type === 'sticker' ? 'Paper: 3 × 4 inch label. In the print window set margins to None and scale to 100%.' : 'Paper: A4.') . '</span><button onclick="window.print()">Print</button></div>';
    foreach ($orders as $o) {
        $items = json_decode((string) $o['items'], true) ?: [];
        $pairs = array_sum(array_column($items, 'qty'));
        if ($type === 'sticker') {
            echo '<section class="label"><header><b>' . h($biz['name']) . '</b><span>#' . h($o['id']) . '</span></header>
              <div class="to"><small>DELIVER TO</small><strong>' . h($o['name']) . '</strong><em>' . h($o['phone']) . '</em><p>' . nl2br(h($o['address'])) . '<br>' . h($o['district']) . ' · ' . h($o['area']) . '</p></div>
              <ul class="its">';
            foreach ($items as $i) echo '<li>' . h(preg_replace('/^Babyglee\s+/u', '', (string) $i['product'])) . ' · EU ' . h($i['size']) . ' × ' . (int) $i['qty'] . '</li>';
            echo '</ul><div class="cod"><small>CASH ON DELIVERY</small><b>' . taka($o['total']) . '</b></div>
              <footer>' . ($o['courier'] !== '' ? '<span>' . h($o['courier']) . ($o['tracking_code'] !== '' ? ' · ' . h($o['tracking_code']) : '') . '</span>' : '<span>' . $pairs . ' pair' . ($pairs > 1 ? 's' : '') . ' · ' . h(date('d M Y', strtotime($o['created_at']))) . '</span>') . '<span>From: ' . h($biz['name']) . ', ' . h($biz['phone']) . '</span></footer></section>';
            continue;
        }
        echo '<section class="inv"><header><div><img src="../img/logo.webp" alt=""><p><b>' . h($biz['name']) . '</b><br>' . h($biz['address']) . '<br>' . h($biz['phone']) . '</p></div>
            <div class="meta"><h1>INVOICE</h1><table><tr><td>Order</td><td><b>#' . h($o['id']) . '</b></td></tr><tr><td>Date</td><td>' . h(date('d M Y, h:i A', strtotime($o['created_at']))) . '</td></tr><tr><td>Payment</td><td>' . h($o['payment']) . '</td></tr>'
            . ($o['courier'] !== '' ? '<tr><td>Courier</td><td>' . h($o['courier']) . ($o['tracking_code'] !== '' ? '<br>' . h($o['tracking_code']) : '') . '</td></tr>' : '') . '</table></div></header>
          <div class="ship"><small>DELIVER TO</small><strong>' . h($o['name']) . '</strong><span>' . h($o['phone']) . ($o['email'] !== '' ? ' · ' . h($o['email']) : '') . '</span><p>' . nl2br(h($o['address'])) . '<br>' . h($o['district']) . ' · ' . h($o['area']) . ', Bangladesh</p></div>
          <table class="lines"><thead><tr><th>#</th><th>Product</th><th>SKU</th><th>Size</th><th class="r">Qty</th><th class="r">Unit price</th><th class="r">Amount</th></tr></thead><tbody>';
        foreach ($items as $n => $i) echo '<tr><td>' . ($n + 1) . '</td><td>' . h($i['product']) . '</td><td>' . h($i['sku'] ?? '') . '</td><td>EU ' . h($i['size']) . '</td><td class="r">' . (int) $i['qty'] . '</td><td class="r">' . taka($i['price']) . '</td><td class="r">' . taka($i['qty'] * $i['price']) . '</td></tr>';
        echo '</tbody></table>
          <div class="sum"><div class="codbox"><small>CASH ON DELIVERY · COLLECT FROM CUSTOMER</small><b>' . taka($o['total']) . '</b></div>
            <table><tr><td>Subtotal</td><td class="r">' . taka($o['subtotal']) . '</td></tr><tr><td>Delivery charge</td><td class="r">' . taka($o['delivery']) . '</td></tr><tr class="tot"><td>Total</td><td class="r">' . taka($o['total']) . '</td></tr></table></div>
          <footer><p>' . h(setting('invoice_note')) . '</p><p>' . h($biz['name']) . ' · ' . h($biz['phone']) . '</p></footer></section>';
    }
    echo '</body></html>'; exit;
}

/* ---------------- ads / UTM analytics: which campaign, ad set and ad bring good orders, and which bring cancellations ---------------- */
if ($page === 'utm') {
    $days = (int) ($_GET['days'] ?? 30); if (!in_array($days, [7, 30, 90], true)) $days = 30;
    $dims = ['campaign' => ['utm_campaign', 'Campaign'], 'content' => ['utm_content', 'Ad / creative'], 'term' => ['utm_term', 'Ad set'], 'source' => ['utm_source', 'Source']];
    $by = isset($dims[$_GET['by'] ?? '']) ? (string) $_GET['by'] : 'campaign';
    $st = $pdo->prepare('SELECT status, total, subtotal, cost, tracking FROM orders WHERE created_at >= ?'); $st->execute([date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'))]);
    $g = []; $tot = ['n' => 0, 'pending' => 0, 'confirmed' => 0, 'delivered' => 0, 'bad' => 0, 'value' => 0, 'profit' => 0];
    foreach ($st as $o) {
        $t = json_decode((string) $o['tracking'], true) ?: [];
        $label = trim((string) ($t[$dims[$by][0]] ?? ''));
        if ($label === '') $label = $by === 'source' ? (!empty($t['fbclid']) ? 'facebook (click id, no UTM)' : (!empty($t['gclid']) ? 'google (click id, no UTM)' : 'direct / unknown')) : '(no ' . strtolower($dims[$by][1]) . ' tag)';
        $g[$label] = $g[$label] ?? ['n' => 0, 'pending' => 0, 'confirmed' => 0, 'delivered' => 0, 'bad' => 0, 'value' => 0, 'profit' => 0];
        $bucket = $o['status'] === 'pending' ? 'pending' : ($o['status'] === 'delivered' ? 'delivered' : (in_array($o['status'], ['cancelled', 'returned'], true) ? 'bad' : 'confirmed'));
        foreach ([&$g[$label], &$tot] as &$x) {
            $x['n']++; $x[$bucket]++;
            if ($bucket === 'delivered') $x['value'] += (int) $o['total'];
            if ($bucket !== 'bad') $x['profit'] += (int) $o['subtotal'] - (int) $o['cost'];
        }
        unset($x);
    }
    $since = date('Y-m-d', strtotime('-' . ($days - 1) . ' days')); $blank = ['n' => 0, 'pending' => 0, 'confirmed' => 0, 'delivered' => 0, 'bad' => 0, 'value' => 0, 'profit' => 0];
    $spendRows = []; $tot['spend'] = 0;
    if ($by === 'campaign') {
        $sp = $pdo->prepare('SELECT * FROM ad_spend WHERE day >= ? ORDER BY day DESC, id DESC'); $sp->execute([$since]); $spendRows = $sp->fetchAll();
        foreach ($spendRows as $r) { $g[$r['campaign']] = $g[$r['campaign']] ?? $blank; $g[$r['campaign']]['spend'] = ($g[$r['campaign']]['spend'] ?? 0) + (int) $r['amount']; $tot['spend'] += (int) $r['amount']; }
    }
    $withSpend = $by === 'campaign';
    uasort($g, fn($a, $b) => $b['n'] <=> $a['n']);
    $row = function (string $label, array $x, bool $foot = false) use ($withSpend): string {
        $sp = (int) ($x['spend'] ?? 0); $extra = '';
        if ($withSpend) $extra = '<td>' . ($sp ? taka($sp) : '—') . '</td><td>' . ($sp && $x['n'] ? taka($sp / $x['n']) : '—') . '</td><td>' . ($sp ? number_format($x['value'] / $sp, 2) . '×' : '—') . '</td><td' . ($sp ? ($x['profit'] - $sp < 0 ? ' class="hot"' : ' class="good"') : '') . '>' . ($sp ? taka($x['profit'] - $sp) : '—') . '</td>';
        $rate = $x['n'] ? round($x['bad'] / $x['n'] * 100) : 0;
        $cls = $x['n'] >= 5 ? ($rate >= 40 ? ' class="hot"' : ($rate <= 15 ? ' class="good"' : '')) : '';
        return '<tr' . ($foot ? ' class="tot"' : '') . '><td class="nm">' . h($label) . '</td><td>' . $x['n'] . '</td><td>' . $x['pending'] . '</td><td>' . $x['confirmed'] . '</td><td>' . $x['delivered'] . '</td><td>' . $x['bad'] . '</td><td' . $cls . '>' . $rate . '%</td><td>' . taka($x['value']) . '</td><td>' . taka($x['profit']) . '</td>' . $extra . '</tr>';
    };
    page_head('Ads');
    echo '<div class="row"><h1>Ads &amp; UTM</h1><div class="tabs sm">';
    foreach ([7, 30, 90] as $d) echo '<a href="index.php?' . h(http_build_query(['p' => 'utm', 'by' => $by, 'days' => $d])) . '"' . ($days === $d ? ' class="on"' : '') . '>' . $d . ' days</a>';
    echo '</div></div><div class="tabs">';
    foreach ($dims as $k => $d) echo '<a href="index.php?' . h(http_build_query(['p' => 'utm', 'by' => $k, 'days' => $days])) . '"' . ($by === $k ? ' class="on"' : '') . '>' . $d[1] . '</a>';
    echo '</div><section class="card"><div class="scroll"><table class="tbl utm"><thead><tr><th>' . $dims[$by][1] . '</th><th>Orders</th><th>Pending</th><th>Confirmed</th><th>Delivered</th><th>Cancelled</th><th>Cancel %</th><th>Delivered ৳</th><th>Est. profit</th>' . ($withSpend ? '<th>Ad spend</th><th>Cost / order</th><th>ROAS</th><th>Net after ads</th>' : '') . '</tr></thead><tbody>';
    if (!$g) echo '<tr><td colspan="9" class="mut">No orders in this period.</td></tr>';
    foreach ($g as $label => $x) echo $row((string) $label, $x);
    if ($g) echo $row('Total', $tot, true);
    echo '</tbody></table></div><p class="mut">Confirmed = confirmed or shipped. Cancelled = cancelled or returned. Cancel % is coloured when there are 5+ orders: red at 40% or more (many fake orders), green at 15% or less. Est. profit = product price minus purchase price for orders that are not cancelled; delivery charge and ad spend are not included.</p></section>
' . ($withSpend ? '<section class="card"><h2>Ad spend</h2><p class="mut">Type what you spent on each campaign (in taka), so the table shows cost per order, ROAS (delivered value ÷ ad spend) and what is left after ads. Use exactly the same campaign name as in the table.</p>
      <form method="post" class="spend">' . $csrf . '<input type="hidden" name="act" value="spend_add"><label>Date<input type="date" name="day" value="' . date('Y-m-d') . '" required></label><label>Campaign<input name="campaign" list="camps" required maxlength="190"></label><label>Amount (৳)<input type="number" name="amount" min="1" inputmode="numeric" required></label><button class="btn sm">Add</button>
      <datalist id="camps">' . implode('', array_map(fn($c) => '<option value="' . h($c) . '">', array_filter(array_keys($g), fn($c) => $c[0] !== '('))) . '</datalist></form>'
      . ($spendRows ? '<table class="tbl small">' . implode('', array_map(fn($r) => '<tr><td>' . h(date('d M', strtotime($r['day']))) . '</td><td>' . h($r['campaign']) . '</td><td class="r">' . taka($r['amount']) . '</td><td class="r"><form method="post">' . $csrf . '<input type="hidden" name="act" value="spend_delete"><input type="hidden" name="id" value="' . (int) $r['id'] . '"><button class="link">Delete</button></form></td></tr>', $spendRows)) . '</table>' : '') . '</section>' : '') . '
    <section class="card"><h2>How to tag your ads</h2><p class="mut">In Meta Ads Manager, open the ad → <b>Tracking</b> → <b>URL parameters</b> and paste this. Meta fills in the names automatically, so every order shows its campaign, ad set and ad here.</p>
    <p><code class="blk">utm_source=facebook&amp;utm_medium=paid&amp;utm_campaign={{campaign.name}}&amp;utm_term={{adset.name}}&amp;utm_content={{ad.name}}</code></p></section>';
    page_foot(); exit;
}


if ($page === 'account') {
    page_head('My account');
    echo '<h1>My account</h1><p class="mut">Signed in as <b>' . h($me['username']) . '</b> (' . h($me['role']) . ').</p><form method="post" class="card form">' . $csrf . '<input type="hidden" name="act" value="password"><h2>Change password</h2><label>Current password<input type="password" name="current" required autocomplete="current-password"></label><label>New password (10+ characters)<input type="password" name="password" required minlength="10" autocomplete="new-password"></label><label>Repeat new password<input type="password" name="password2" required autocomplete="new-password"></label><button class="btn">Change password</button></form>';
    page_foot(); exit;
}

/* ---------------- manual order: for orders taken by phone, inbox or WhatsApp ---------------- */
if ($page === 'neworder') {
    $v = $_SESSION['neworder'] ?? []; unset($_SESSION['neworder']); $cat = products(true);
    $prow = function ($slug, $size, $qty) use ($cat) {
        $o = '<div class="vrow"><label>Product<select name="it_slug[]">';
        foreach ($cat as $sl => $p) $o .= '<option value="' . h($sl) . '"' . ($sl === $slug ? ' selected' : '') . '>' . h(preg_replace('/^Babyglee\s+/u', '', $p['name'])) . ' · ' . taka($p['price']) . '</option>';
        return $o . '</select></label><label>Size (EU)<input type="number" name="it_size[]" min="10" max="60" inputmode="numeric" required value="' . h($size) . '"></label><label>Qty<input type="number" name="it_qty[]" min="1" max="10" inputmode="numeric" value="' . h($qty) . '"></label></div>';
    };
    $rows = ''; foreach ((array) ($v['it_slug'] ?? [(string) array_key_first($cat)]) as $n => $sl) $rows .= $prow((string) $sl, $v['it_size'][$n] ?? '', $v['it_qty'][$n] ?? 1);
    page_head('New order');
    echo '<p><a href="index.php?p=orders">← Orders</a></p><h1>New order</h1><p class="mut">For orders taken by phone, Facebook inbox or WhatsApp. Price and delivery charge are calculated automatically; stock is updated.</p>
      <form method="post" class="card form">' . $csrf . '<input type="hidden" name="act" value="neworder">
      <div class="f2"><label>Customer name<input name="name" required maxlength="120" value="' . h($v['name'] ?? '') . '"></label><label>Mobile number (11 digits)<input name="phone" required inputmode="numeric" maxlength="11" placeholder="01XXXXXXXXX" value="' . h($v['phone'] ?? '') . '"></label></div>
      <div class="f2"><label>District<input name="district" required maxlength="60" value="' . h($v['district'] ?? '') . '"></label><label>Delivery area<select name="area"><option value="dhaka"' . (($v['area'] ?? '') === 'dhaka' ? ' selected' : '') . '>Inside Dhaka · ' . taka(setting('delivery_dhaka')) . '</option><option value="outside"' . (($v['area'] ?? 'outside') === 'outside' ? ' selected' : '') . '>Outside Dhaka · ' . taka(setting('delivery_outside')) . '</option></select></label></div>
      <label>Full address<textarea name="address" rows="2" required maxlength="600">' . h($v['address'] ?? '') . '</textarea></label>
      <fieldset><legend>Products</legend><div id="prows">' . $rows . '</div><template id="ptpl">' . $prow('', '', 1) . '</template><button type="button" class="btn sm ghost" onclick="document.getElementById(\'prows\').appendChild(document.getElementById(\'ptpl\').content.cloneNode(true))">+ Add another product</button></fieldset>
      <div class="f2"><label>Order came from<select name="channel">' . implode('', array_map(fn($k, $l) => '<option value="' . $k . '"' . (($v['channel'] ?? 'phone') === $k ? ' selected' : '') . '>' . $l . '</option>', ['phone', 'messenger', 'whatsapp', 'instagram', 'shop'], ['Phone call', 'Facebook inbox', 'WhatsApp', 'Instagram inbox', 'Walk-in / other'])) . '</select></label>
      <label>Save as<select name="status"><option value="confirmed">Confirmed</option><option value="pending"' . (($v['status'] ?? '') === 'pending' ? ' selected' : '') . '>Pending</option></select></label></div>
      <label>Note (optional)<input name="note" maxlength="500" value="' . h($v['note'] ?? '') . '"></label><button class="btn">Create order</button></form>';
    page_foot(); exit;
}


/* ---------------- incomplete orders: checkout started (valid mobile number typed) but not finished ---------------- */
if ($page === 'leads') {
    $tab = ($_GET['status'] ?? '') === 'cancelled' ? 'cancelled' : 'new';
    $cnt = ['new' => 0, 'cancelled' => 0]; foreach ($pdo->query('SELECT status, COUNT(*) c FROM leads GROUP BY status') as $r) $cnt[$r['status']] = (int) $r['c'];
    $st = $pdo->prepare('SELECT * FROM leads WHERE status = ? ORDER BY updated_at DESC LIMIT 200'); $st->execute([$tab]); $rows = $st->fetchAll();
    $cat = products(false);
    page_head('Incomplete orders');
    echo '<h1>Incomplete orders</h1><p class="mut">People who typed their mobile number at checkout but did not finish the order. Call them, then confirm (it becomes a normal order) or cancel (it stays here).</p>
      <div class="tabs"><a href="index.php?p=leads"' . ($tab === 'new' ? ' class="on"' : '') . '>To follow up <b>' . $cnt['new'] . '</b></a><a href="index.php?p=leads&status=cancelled"' . ($tab === 'cancelled' ? ' class="on"' : '') . '>Cancelled <b>' . $cnt['cancelled'] . '</b></a></div>';
    if (!$rows) echo '<p class="card mut">Nothing here.</p>';
    foreach ($rows as $l) {
        $items = json_decode((string) $l['items'], true) ?: [];
        $txt = implode(', ', array_map(fn($i) => preg_replace('/^Babyglee\s+/u', '', (string) ($cat[$i['slug']]['name'] ?? $i['slug'])) . ((int) $i['size'] ? ' (EU ' . (int) $i['size'] . ')' : ' (size not chosen)') . ' × ' . (int) $i['qty'], $items));
        echo '<article class="card order"><div class="o-top"><a class="oid" href="index.php?p=lead&id=' . (int) $l['id'] . '">' . h($l['phone']) . '</a><span class="st st-' . ($tab === 'new' ? 'pending' : 'cancelled') . '">' . ($tab === 'new' ? 'Incomplete' : 'Cancelled') . '</span><span class="src">' . h($l['source']) . '</span><time>' . h(date('d M, h:i A', strtotime($l['updated_at']))) . '</time></div>
          <div class="o-mid"><div><b>' . h($l['name'] !== '' ? $l['name'] : 'Name not typed') . '</b><br><span class="mut">' . h($l['district'] !== '' ? $l['district'] : 'No address yet') . '</span></div></div><p class="o-items">' . h($txt ?: 'No product') . '</p>
          <div class="acts"><a class="btn sm" href="tel:' . h($l['phone']) . '">Call</a><a class="btn sm wa" href="https://wa.me/' . h(preg_replace('/\D/', '', $l['phone'])) . '" target="_blank" rel="noopener">WhatsApp</a><a class="btn sm ghost" href="index.php?p=lead&id=' . (int) $l['id'] . '">Open</a></div></article>';
    }
    page_foot(); exit;
}
if ($page === 'lead') {
    $st = $pdo->prepare('SELECT * FROM leads WHERE id = ?'); $st->execute([(int) ($_GET['id'] ?? 0)]); $l = $st->fetch();
    page_head('Incomplete order');
    if (!$l) { echo '<p class="card">Not found (the customer may have completed the order). <a href="index.php?p=leads">Back</a></p>'; page_foot(); exit; }
    $items = json_decode((string) $l['items'], true) ?: []; $t = json_decode((string) $l['tracking'], true) ?: []; $cat = products(true);
    if (!$items) $items = [['slug' => (string) array_key_first($cat), 'size' => 0, 'qty' => 1]];
    $local = '0' . substr(preg_replace('/\D/', '', $l['phone']), 3);
    echo '<p><a href="index.php?p=leads">← Incomplete orders</a></p><div class="row"><h1>' . h($l['phone']) . '</h1><span class="st st-' . ($l['status'] === 'new' ? 'pending">Incomplete' : 'cancelled">Cancelled') . '</span></div>
      <p class="mut">Started ' . h(date('d M Y, h:i A', strtotime($l['created_at']))) . ' on the ' . h($l['source']) . ' page' . (!empty($t['utm_campaign']) ? ' · campaign: ' . h($t['utm_campaign']) : '') . (!empty($t['utm_content']) ? ' · ad: ' . h($t['utm_content']) : '') . '</p>
      <div class="acts top-acts"><a class="btn sm" href="tel:' . h($l['phone']) . '">Call</a><a class="btn sm wa" href="https://wa.me/' . h(preg_replace('/\D/', '', $l['phone'])) . '" target="_blank" rel="noopener">WhatsApp</a></div>
      <form method="post" class="card form">' . $csrf . '<input type="hidden" name="act" value="lead_convert"><input type="hidden" name="id" value="' . (int) $l['id'] . '"><h2>Confirm as an order</h2><p class="mut">Fill in what the customer tells you on the phone. Prices and delivery charge are calculated automatically.</p>
      <div class="f2"><label>Name<input name="name" required maxlength="120" value="' . h($l['name']) . '"></label><label>Mobile number (11 digits)<input name="phone" required inputmode="numeric" maxlength="11" value="' . h($local) . '"></label></div>
      <div class="f2"><label>District<input name="district" required maxlength="60" value="' . h($l['district']) . '"></label><label>Delivery area<select name="area"><option value="dhaka"' . ($l['area'] === 'Inside Dhaka' ? ' selected' : '') . '>Inside Dhaka</option><option value="outside"' . ($l['area'] !== 'Inside Dhaka' ? ' selected' : '') . '>Outside Dhaka</option></select></label></div>
      <label>Full address<textarea name="address" rows="2" required maxlength="600">' . h($l['address']) . '</textarea></label><fieldset><legend>Products</legend>';
    foreach ($items as $i) {
        echo '<div class="vrow"><label>Product<select name="it_slug[]">';
        foreach ($cat as $slug => $p) echo '<option value="' . h($slug) . '"' . ($slug === $i['slug'] ? ' selected' : '') . '>' . h(preg_replace('/^Babyglee\s+/u', '', $p['name'])) . '</option>';
        echo '</select></label><label>Size (EU)<input type="number" name="it_size[]" min="10" max="60" inputmode="numeric" required value="' . ((int) $i['size'] ?: '') . '"></label><label>Qty<input type="number" name="it_qty[]" min="1" max="10" inputmode="numeric" value="' . (int) $i['qty'] . '"></label></div>';
    }
    echo '</fieldset><button class="btn">Confirm and create order</button></form>
      <div class="grid2"><form method="post" class="card">' . $csrf . '<input type="hidden" name="id" value="' . (int) $l['id'] . '"><h2>' . ($l['status'] === 'new' ? 'Not interested?' : 'Cancelled') . '</h2><label>Note<input name="note" maxlength="300" value="' . h($l['note']) . '" placeholder="e.g. did not pick up, wrong number"></label>
        <div class="acts">' . ($l['status'] === 'new' ? '<button class="btn ghost" name="act" value="lead_cancel">Cancel (keep in this list)</button>' : '<button class="btn ghost" name="act" value="lead_reopen">Move back to follow up</button>') . '<button class="btn red" name="act" value="lead_delete" onclick="return confirm(\'Delete this incomplete order for good?\')">Delete</button></div></form></div>';
    page_foot(); exit;
}

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
    echo '<div class="row"><h1>Orders</h1><div class="acts"><a class="btn" href="index.php?p=neworder">+ New order</a>' . ($isAdmin ? '<a class="btn ghost" href="index.php?p=export">Download CSV</a>' : '') . '</div></div><div class="tabs"><a href="index.php?p=orders"' . ($status === '' ? ' class="on"' : '') . '>All <b>' . array_sum($counts) . '</b></a>';
    foreach (BG_STATUSES as $k => $l) echo '<a href="index.php?p=orders&status=' . $k . '"' . ($status === $k ? ' class="on"' : '') . '>' . $l . ' <b>' . ($counts[$k] ?? 0) . '</b></a>';
    echo '</div><form class="search" method="get"><input type="hidden" name="p" value="orders"><input type="hidden" name="status" value="' . h($status) . '"><input name="q" value="' . h($q) . '" placeholder="Search order ID, phone or name" inputmode="search"><button class="btn">Search</button></form>';
    $hist = [];
    if ($rows) {
        $ph = array_values(array_unique(array_column($rows, 'phone')));
        $hq = $pdo->prepare("SELECT phone, COUNT(*) c, SUM(CASE WHEN status IN ('cancelled','returned') THEN 1 ELSE 0 END) x FROM orders WHERE phone IN (" . implode(',', array_fill(0, count($ph), '?')) . ') GROUP BY phone'); $hq->execute($ph);
        foreach ($hq as $r) { if ((int) $r['x'] > 0 && (int) $r['c'] > 1) $hist[$r['phone']] = '<span class="flag bad">' . (int) $r['x'] . ' cancelled before</span>'; elseif ((int) $r['c'] > 1) $hist[$r['phone']] = '<span class="flag">Repeat ×' . (int) $r['c'] . '</span>'; }
        echo '<form id="bulk" method="post" class="bulk">' . $GLOBALS['csrf'] . '<input type="hidden" name="act" value="bulk"><label class="all"><input type="checkbox" onclick="document.querySelectorAll(\'.pick\').forEach(c=>c.checked=this.checked)"> All</label>
          <select name="do" aria-label="Action for selected orders"><option value="invoice">Print invoices (A4)</option><option value="sticker">Print stickers (3×4 in)</option>';
        foreach (BG_STATUSES as $k => $l) echo '<option value="status_' . $k . '">Mark as ' . $l . '</option>';
        echo '</select><button class="btn sm">Apply to selected</button></form>';
    }
    if (!$rows) echo '<p class="card mut">No orders found.</p>';
    foreach ($rows as $o) {
        $items = json_decode((string) $o['items'], true) ?: [];
        echo '<article class="card order"><div class="o-top"><input type="checkbox" class="pick" name="ids[]" value="' . h($o['id']) . '" form="bulk" aria-label="Select order ' . h($o['id']) . '"><a class="oid" href="index.php?p=order&id=' . rawurlencode($o['id']) . '">#' . h($o['id']) . '</a>' . badge($o['status']) . ($hist[$o['phone']] ?? '') . '<span class="src">' . h($o['source']) . '</span><time>' . h(date('d M, h:i A', strtotime($o['created_at']))) . '</time></div>
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
    $hs = $pdo->prepare("SELECT COUNT(*) c, SUM(CASE WHEN status IN ('cancelled','returned') THEN 1 ELSE 0 END) x, SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) d FROM orders WHERE phone = ? AND id <> ?"); $hs->execute([$o['phone'], $o['id']]); $hx = $hs->fetch();
    $histLine = (int) $hx['c'] ? '<p class="hist' . ((int) $hx['x'] ? ' bad' : '') . '">Earlier orders from this number: <b>' . (int) $hx['c'] . '</b> (' . (int) $hx['d'] . ' delivered, ' . (int) $hx['x'] . ' cancelled / returned) · <a href="index.php?p=orders&q=' . rawurlencode($o['phone']) . '">see all</a></p>' : '<p class="hist">First order from this number.</p>';
    $profitLine = (int) $o['cost'] > 0 ? '<p class="mut">Purchase cost ' . taka($o['cost']) . ' · estimated profit <b>' . taka((int) $o['subtotal'] - (int) $o['cost']) . '</b> (before delivery and ad cost)</p>' : '<p class="mut">Add a purchase price to the products to see profit here.</p>';
    if (!$isAdmin) $profitLine = '';
    $wa = 'https://wa.me/' . preg_replace('/\D/', '', $o['phone']) . '?text=' . rawurlencode('Hello ' . $o['name'] . ', this is Babyglee about your order #' . $o['id'] . '.');
    echo '<p><a href="index.php?p=orders">← Orders</a></p><div class="row"><h1>Order #' . h($o['id']) . '</h1>' . badge($o['status']) . '</div><div class="acts top-acts"><a class="btn sm" href="index.php?p=print&type=invoice&ids=' . h($o['id']) . '" target="_blank" rel="noopener">Print invoice (A4)</a><a class="btn sm ghost" href="index.php?p=print&type=sticker&ids=' . h($o['id']) . '" target="_blank" rel="noopener">Print sticker (3×4)</a></div><p class="mut">' . h(date('d M Y, h:i A', strtotime($o['created_at']))) . ' · from ' . h($o['source']) . ' page · ' . h($o['payment']) . '</p>
    <div class="grid2"><section class="card"><h2>Customer</h2><p><b>' . h($o['name']) . '</b><br>' . h($o['phone']) . ($o['email'] ? '<br>' . h($o['email']) : '') . '</p><p>' . nl2br(h($o['address'])) . '<br>' . h($o['district']) . ' · ' . h($o['area']) . '</p>
      <div class="acts"><a class="btn sm" href="tel:' . h($o['phone']) . '">Call</a><a class="btn sm wa" href="' . h($wa) . '" target="_blank" rel="noopener">WhatsApp</a></div>' . $histLine . '</section>
    <section class="card"><h2>Items</h2><table class="tbl">';
    foreach ($items as $i) echo '<tr><td>' . h($i['product']) . '<br><span class="mut">' . h($i['sku'] ?? '') . ' · EU ' . h($i['size']) . '</span></td><td>× ' . (int) $i['qty'] . '</td><td class="r">' . taka($i['qty'] * $i['price']) . '</td></tr>';
    echo '<tr><td colspan="2">Subtotal</td><td class="r">' . taka($o['subtotal']) . '</td></tr><tr><td colspan="2">Delivery</td><td class="r">' . taka($o['delivery']) . '</td></tr><tr class="tot"><td colspan="2">Total to collect (COD)</td><td class="r">' . taka($o['total']) . '</td></tr></table>' . $profitLine . '</section></div>
    <div class="grid2"><section class="card"><h2>Change status</h2><form method="post">' . $csrf . '<input type="hidden" name="act" value="status"><input type="hidden" name="id" value="' . h($o['id']) . '"><label>Status<select name="status">';
    foreach (BG_STATUSES as $k => $l) echo '<option value="' . $k . '"' . ($o['status'] === $k ? ' selected' : '') . '>' . $l . '</option>';
    echo '</select></label><label>Comment (optional)<input name="note" maxlength="500" placeholder="e.g. confirmed by phone, size 28"></label><button class="btn">Update status</button></form>
      <h3>History</h3><ul class="log">';
    foreach ($log as $l) echo '<li>' . badge($l['status']) . ' <time>' . h(date('d M, h:i A', strtotime($l['created_at']))) . '</time>' . (($l['by_user'] ?? '') !== '' ? ' <span class="mut">by ' . h($l['by_user']) . '</span>' : '') . ($l['note'] ? '<br><span class="mut">' . h($l['note']) . '</span>' : '') . '</li>';
    echo '</ul></section><section class="card"><h2>Internal note</h2><form method="post">' . $csrf . '<input type="hidden" name="act" value="note"><input type="hidden" name="id" value="' . h($o['id']) . '"><textarea name="note" rows="4" maxlength="2000">' . h($o['note']) . '</textarea><button class="btn">Save note</button></form>
      <h3>Courier</h3><form method="post">' . $csrf . '<input type="hidden" name="act" value="ship"><input type="hidden" name="id" value="' . h($o['id']) . '"><div class="f2"><label>Courier<input name="courier" maxlength="60" list="couriers" value="' . h($o['courier']) . '" placeholder="e.g. Pathao"></label><label>Tracking / consignment ID<input name="tracking_code" maxlength="80" value="' . h($o['tracking_code']) . '"></label></div><datalist id="couriers"><option value="Pathao"><option value="Steadfast"><option value="RedX"><option value="Sundarban"><option value="eCourier"><option value="Paperfly"></datalist><button class="btn sm">Save courier</button></form>
      <h3>Where it came from</h3><table class="tbl small">';
    foreach (['utm_source' => 'UTM source', 'utm_medium' => 'UTM medium', 'utm_campaign' => 'UTM campaign', 'utm_term' => 'Ad set (utm_term)', 'utm_content' => 'Ad / creative (utm_content)', 'fbclid' => 'Meta click ID', 'gclid' => 'Google click ID', 'referrer' => 'Referrer', 'page_url' => 'Order page', 'event_id' => 'Event ID'] as $k => $l) if (!empty($t[$k])) echo '<tr><td>' . $l . '</td><td class="brk">' . h($t[$k]) . '</td></tr>';
    echo '<tr><td>Meta CAPI</td><td>' . h($o['capi_status'] ?: 'not configured') . '</td></tr><tr><td>IP</td><td>' . h($o['ip']) . '</td></tr></table></section></div>';
    page_foot(); exit;
}

if ($page === 'products') {
    $rows = products(false);
    page_head('Products');
    echo '<div class="row"><h1>Products</h1><a class="btn" href="index.php?p=product">Add product</a></div><div class="pgrid">';
    foreach ($rows as $p) echo '<a class="card prod' . ($p['active'] ? '' : ' off') . '" href="index.php?p=product&id=' . (int) $p['id'] . '"><img src="../' . h($p['img']) . '" alt="" loading="lazy"><b>' . h($p['name']) . '</b><span class="mut">' . h($p['sku']) . '</span><span><s>' . taka($p['list_price']) . '</s> <b class="pr">' . taka($p['price']) . '</b></span>' . ($p['active'] ? '' : '<span class="st st-cancelled">Hidden</span>') . ($p['track'] ? '<span class="stk' . (array_sum($p['variants']) <= 0 ? ' zero' : (min($p['variants'] ?: [0]) <= 0 ? ' low' : '')) . '">' . array_sum($p['variants']) . ' in stock · ' . count(array_filter($p['variants'], fn($q) => $q <= 0)) . ' of ' . count($p['variants']) . ' sizes sold out</span>' : '<span class="stk off">' . count($p['variants']) . ' sizes · stock not counted</span>') . '</a>';
    echo '</div>'; page_foot(); exit;
}

if ($page === 'product') {
    $id = (int) ($_GET['id'] ?? 0); $p = null;
    if ($id) { $st = $pdo->prepare('SELECT * FROM products WHERE id = ?'); $st->execute([$id]); $p = $st->fetch() ?: null; }
    $v = $p ?: ['name' => 'Babyglee Bounce — ', 'color' => '', 'sku' => 'BSTD-01-', 'price' => 1290, 'list_price' => 1800, 'price_usd' => 15, 'cost' => 0, 'color_hex' => '#24babc', 'sizes' => implode(',', BG_SIZES), 'active' => 1, 'sort' => 10, 'img' => ''];
    $have = array_map('intval', explode(',', (string) $v['sizes']));
    $vars = [];
    if ($p) { $vq = $pdo->prepare('SELECT size, stock FROM variants WHERE product_id = ? ORDER BY size'); $vq->execute([$id]); foreach ($vq as $r) $vars[(int) $r['size']] = (int) $r['stock']; }
    else foreach (BG_SIZES as $sz) $vars[$sz] = 0;
    $vrow = fn($sz, $q) => '<div class="vrow"><label>Size (EU)<input type="number" name="var_size[]" min="10" max="60" inputmode="numeric" value="' . h($sz) . '" required></label><label>Stock (pairs)<input type="number" name="var_stock[]" min="0" inputmode="numeric" value="' . h($q) . '"></label><button type="button" class="btn sm ghost" onclick="this.closest(\'.vrow\').remove()">Remove</button></div>';
    $track = !empty($v['track_stock']);
    $varHtml = '<fieldset><legend>Sizes (variations) and stock</legend><p class="mut">One row per size you sell. Add or remove sizes as you like.</p><div id="vrows">' . implode('', array_map($vrow, array_keys($vars), $vars)) . '</div>
      <template id="vtpl">' . $vrow('', 0) . '</template><button type="button" class="btn sm ghost" onclick="document.getElementById(\'vrows\').appendChild(document.getElementById(\'vtpl\').content.cloneNode(true))">+ Add a size</button>
      <label class="chk big trk"><input type="checkbox" name="track_stock" value="1"' . ($track ? ' checked' : '') . '><span>Count stock for this product</span></label>
      <p class="mut">When ticked: each order takes pairs out of stock, a size with 0 pairs shows as sold out on the website, and cancelled or returned orders put the pairs back. When not ticked, every size listed here can always be ordered and the stock numbers are ignored.' . ($track ? ' <b>In stock now: ' . array_sum($vars) . ' pairs.</b>' : '') . '</p></fieldset>';
    page_head($p ? 'Edit product' : 'Add product');
    echo '<p><a href="index.php?p=products">← Products</a></p><h1>' . ($p ? 'Edit product' : 'Add product') . '</h1><form method="post" enctype="multipart/form-data" class="card form">' . $csrf . '<input type="hidden" name="act" value="product"><input type="hidden" name="id" value="' . $id . '">
      <label>Product name<input name="name" required maxlength="150" value="' . h($v['name']) . '"></label>
      <div class="f2"><label>Colour name<input name="color" maxlength="80" value="' . h($v['color']) . '" placeholder="e.g. Grey"></label><label>Colour dot<input type="color" name="color_hex" value="' . h($v['color_hex']) . '"></label></div>
      <label>SKU (item ID sent to GA4 / Meta)<input name="sku" required maxlength="60" value="' . h($v['sku']) . '"></label>
      <div class="f3"><label>Price (৳)<input type="number" name="price" min="1" required inputmode="numeric" value="' . (int) $v['price'] . '"></label><label>Old price (৳)<input type="number" name="list_price" min="0" inputmode="numeric" value="' . (int) $v['list_price'] . '"></label><label>USD price<input type="number" name="price_usd" min="0" inputmode="numeric" value="' . (int) $v['price_usd'] . '"></label></div>
      <label>Purchase price / your cost per pair (৳) — private, never shown on the website<input type="number" name="cost" min="0" inputmode="numeric" value="' . (int) ($v['cost'] ?? 0) . '"></label>' . ((int) ($v['cost'] ?? 0) > 0 ? '<p class="mut">Margin: <b>' . taka((int) $v['price'] - (int) $v['cost']) . '</b> per pair (' . round(((int) $v['price'] - (int) $v['cost']) / max(1, (int) $v['price']) * 100) . '%), before delivery and ad cost.</p>' : '') . '
      ' . $varHtml . '
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
      <h2>Invoice &amp; sticker details</h2><div class="f2"><label>Business name<input name="biz_name" maxlength="80" value="' . $s('biz_name') . '"></label><label>Phone on invoice<input name="biz_phone" maxlength="40" value="' . $s('biz_phone') . '"></label></div>
      <label>Address on invoice<input name="biz_address" maxlength="200" value="' . $s('biz_address') . '"></label><label>Note at the bottom of the invoice<input name="invoice_note" maxlength="300" value="' . $s('invoice_note') . '"></label>
      <h2>Meta Conversions API (server-side Purchase)</h2><p class="mut">Optional. When both fields are filled, every order is also sent to Meta from the server with the same event ID as the browser event, so Meta counts it once. Leave empty if you send Purchase from a server-side GTM container instead (do not do both).</p>
      <div class="f2"><label>Pixel (dataset) ID<input name="meta_pixel_id" value="' . $s('meta_pixel_id') . '" inputmode="numeric"></label><label>Graph API version<input name="meta_api_version" value="' . $s('meta_api_version') . '"></label></div>
      <label>Access token ' . (setting('meta_capi_token') !== '' ? '(saved; leave empty to keep it, type REMOVE to delete it)' : '(not set)') . '<input name="meta_capi_token" value="" autocomplete="off" placeholder="' . (setting('meta_capi_token') !== '' ? '••••••••' : 'EAAG…') . '"></label>
      <label>Test event code (only while testing in Events Manager)<input name="meta_test_code" value="' . $s('meta_test_code') . '" placeholder="TEST12345"></label>
      <button class="btn">Save settings</button></form>
    <div class="grid2"><form method="post" class="card form">' . $csrf . '<input type="hidden" name="act" value="password"><h2>Change password</h2><label>Current password<input type="password" name="current" required autocomplete="current-password"></label><label>New password (10+ characters)<input type="password" name="password" required minlength="10" autocomplete="new-password"></label><label>Repeat new password<input type="password" name="password2" required autocomplete="new-password"></label><button class="btn">Change password</button></form>
    <section class="card"><h2>Staff accounts</h2><p class="mut">Each person gets their own login. <b>Staff</b> can only work on orders and incomplete orders. <b>Admin</b> can also see products, prices, profit, ads and settings. Every status change records who did it.</p><table class="tbl small">'
      . implode('', array_map(fn($u) => '<tr><td><b>' . h($u['name']) . '</b><br><span class="mut">' . h($u['username']) . ' · ' . h($u['role']) . ($u['active'] ? '' : ' · switched off') . '</span></td><td class="r"><form method="post" class="inl">' . $csrf . '<input type="hidden" name="id" value="' . (int) $u['id'] . '"><input type="password" name="password" placeholder="new password" minlength="10" autocomplete="new-password" aria-label="New password for ' . h($u['username']) . '"><button class="btn sm ghost" name="act" value="user_pass">Reset</button>' . ((int) $u['id'] === (int) $me['id'] ? '' : '<button class="btn sm ghost" name="act" value="user_toggle" formnovalidate>' . ($u['active'] ? 'Switch off' : 'Switch on') . '</button>') . '</form></td></tr>', $pdo->query('SELECT * FROM users ORDER BY id')->fetchAll())) . '</table>
      <form method="post" class="form">' . $csrf . '<input type="hidden" name="act" value="user_add"><h3>Add an account</h3><div class="f2"><label>Name<input name="name" required maxlength="80"></label><label>Username<input name="username" required autocapitalize="none" maxlength="40"></label></div>
      <div class="f2"><label>Password (10+ characters)<input type="password" name="password" required minlength="10" autocomplete="new-password"></label><label>Role<select name="role"><option value="staff">Staff (orders only)</option><option value="admin">Admin (everything)</option></select></label></div><button class="btn sm">Add account</button></form></section>
    <section class="card"><h2>Backup</h2><p class="mut">Download a copy of all orders, products and settings. Do this regularly and keep the file safe: it contains customer details. Passwords and API tokens are not included.</p><div class="acts"><a class="btn" href="index.php?p=backup">Download backup (JSON)</a><a class="btn ghost" href="index.php?p=export">Orders as CSV</a></div></section></div>';
    page_foot(); exit;
}

/* ---------------- dashboard / analytics ---------------- */
$days = (int) ($_GET['days'] ?? 30); if (!in_array($days, [7, 30, 90], true)) $days = 30;
$since = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));
$st = $pdo->prepare('SELECT id, created_at, status, source, district, total, subtotal, cost, items, tracking FROM orders WHERE created_at >= ? ORDER BY created_at'); $st->execute([$since]); $rows = $st->fetchAll();
$dead = ['cancelled', 'returned'];
$k = ['orders' => 0, 'value' => 0, 'delivered' => 0, 'delivered_value' => 0, 'pending' => 0, 'cancelled' => 0, 'pairs' => 0, 'today' => 0, 'today_value' => 0, 'profit' => 0, 'nocost' => 0];
$byDay = []; for ($i = $days - 1; $i >= 0; $i--) $byDay[date('Y-m-d', strtotime("-$i days"))] = ['n' => 0, 'v' => 0];
$byStatus = array_fill_keys(array_keys(BG_STATUSES), 0); $byProduct = []; $bySize = []; $bySource = []; $byDistrict = []; $byUtm = [];
foreach ($rows as $o) {
    $byStatus[$o['status']] = ($byStatus[$o['status']] ?? 0) + 1;
    $live = !in_array($o['status'], $dead, true); $day = substr($o['created_at'], 0, 10);
    $k['orders']++; if ($o['status'] === 'pending') $k['pending']++; if (!$live) $k['cancelled']++;
    if ($o['status'] === 'delivered') { $k['delivered']++; $k['delivered_value'] += (int) $o['total']; }
    if (!$live) continue;
    $k['value'] += (int) $o['total']; $k['profit'] += (int) $o['subtotal'] - (int) $o['cost']; if ((int) $o['cost'] === 0) $k['nocost']++; if (isset($byDay[$day])) { $byDay[$day]['n']++; $byDay[$day]['v'] += (int) $o['total']; }
    if ($day === date('Y-m-d')) { $k['today']++; $k['today_value'] += (int) $o['total']; }
    foreach (json_decode((string) $o['items'], true) ?: [] as $i) {
        $k['pairs'] += (int) $i['qty'];
        $pn = preg_replace('/^Babyglee\s+/u', '', (string) $i['product']); $byProduct[$pn] = ($byProduct[$pn] ?? 0) + (int) $i['qty'];
        $bySize['EU ' . $i['size']] = ($bySize['EU ' . $i['size']] ?? 0) + (int) $i['qty'];
    }
    $t = json_decode((string) $o['tracking'], true) ?: [];
    $src = $t['utm_source'] ?? (!empty($t['fbclid']) ? 'facebook (click id)' : (!empty($t['gclid']) ? 'google (click id)' : 'direct / unknown'));
    foreach ([[&$bySource, ['landing' => 'Landing pages', 'manual' => 'Manual (phone / inbox)'][$o['source']] ?? 'Store'], [&$byDistrict, $o['district'] ?: 'Unknown'], [&$byUtm, $src]] as $pair) {
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
$staffCard = '';
if ($isAdmin) {
    $sq = $pdo->prepare("SELECT by_user, status, COUNT(*) c FROM order_log WHERE created_at >= ? AND by_user <> '' AND status IN ('confirmed','cancelled') GROUP BY by_user, status"); $sq->execute([$since]);
    $conf = []; $canc = []; foreach ($sq as $r) { if ($r['status'] === 'confirmed') $conf[$r['by_user']] = (int) $r['c']; else $canc[$r['by_user']] = (int) $r['c']; }
    if ($conf || $canc) $staffCard = '<div class="grid2"><section class="card"><h2>Confirmed by staff</h2>' . bars($conf, false) . '</section><section class="card"><h2>Cancelled by staff</h2>' . bars($canc, false) . '</section></div>';
}
page_head('Dashboard');
echo '<div class="row"><h1>Dashboard</h1><div class="tabs sm">';
foreach ([7, 30, 90] as $d) echo '<a href="index.php?days=' . $d . '"' . ($days === $d ? ' class="on"' : '') . '>' . $d . ' days</a>';
echo '</div></div>';
$lowStock = [];
foreach (products(true) as $lp) { if (!$lp['track']) continue; $z = array_keys(array_filter($lp['variants'], fn($q) => $q <= 3)); if ($z) $lowStock[] = preg_replace('/^Babyglee\s+/u', '', $lp['name']) . ' (EU ' . implode(', ', $z) . ')'; }
$openLeads = (int) $pdo->query("SELECT COUNT(*) FROM leads WHERE status = 'new'")->fetchColumn();
if ($openLeads) echo '<a class="alert soft" href="index.php?p=leads"><b>' . $openLeads . '</b> incomplete order' . ($openLeads > 1 ? 's' : '') . ' to follow up →</a>';
if ($lowStock) echo '<a class="alert warn" href="index.php?p=products"><b>Low stock (3 pairs or fewer):</b> ' . h(implode(' · ', array_slice($lowStock, 0, 6))) . ' →</a>';
if ($k['pending']) echo '<a class="alert" href="index.php?p=orders&status=pending"><b>' . $k['pending'] . '</b> order' . ($k['pending'] > 1 ? 's' : '') . ' waiting to be confirmed →</a>';
if (setting('gtm_id') === '') echo '<a class="alert soft" href="index.php?p=settings">Tracking is off: add your GTM container ID in Settings →</a>';
echo '<div class="kpis"><div class="card kpi"><span>Today</span><b>' . $k['today'] . '</b><small>' . taka($k['today_value']) . '</small></div>
  <div class="card kpi"><span>Orders (' . $days . 'd)</span><b>' . $valid . '</b><small>' . $k['cancelled'] . ' cancelled / returned</small></div>
  <div class="card kpi"><span>Order value</span><b>' . taka($k['value']) . '</b><small>avg ' . taka($valid ? $k['value'] / $valid : 0) . '</small></div>
  <div class="card kpi"><span>Delivered</span><b>' . $k['delivered'] . '</b><small>' . taka($k['delivered_value']) . ' collected</small></div>
  <div class="card kpi"><span>Pairs sold</span><b>' . $k['pairs'] . '</b><small>' . ($k['orders'] ? round($k['cancelled'] / $k['orders'] * 100) : 0) . '% cancel rate</small></div>' . (!$isAdmin ? '' : '
  <div class="card kpi"><span>Est. profit</span><b>' . ($k['nocost'] === $valid && $valid ? '—' : taka($k['profit'])) . '</b><small>' . ($k['nocost'] ? '<a href="index.php?p=products">add purchase prices</a>' : 'before delivery &amp; ads') . '</small></div>') . '</div>';
$maxDay = max(array_map(fn($d) => $d['n'], $byDay)) ?: 1;
echo '<section class="card"><h2>Orders per day</h2><div class="chart" role="img" aria-label="Orders per day for the last ' . $days . ' days">';
foreach ($byDay as $d => $x) echo '<div class="col" title="' . h(date('d M', strtotime($d))) . ': ' . $x['n'] . ' orders, ' . taka($x['v']) . '"><i style="height:' . max(2, round($x['n'] / $maxDay * 100)) . '%"></i></div>';
echo '</div><div class="axis"><span>' . h(date('d M', strtotime(array_key_first($byDay)))) . '</span><span>busiest day: ' . $maxDay . ' orders</span><span>' . h(date('d M', strtotime(array_key_last($byDay)))) . '</span></div></section>
<div class="grid2"><section class="card"><h2>By status</h2>' . bars(array_combine(array_map(fn($s) => BG_STATUSES[$s] ?? $s, array_keys($byStatus)), $byStatus), false) . '</section><section class="card"><h2>Store vs landing pages</h2>' . bars($bySource) . '</section>
<section class="card"><h2>Top products (pairs)</h2>' . bars($byProduct, false) . '</section><section class="card"><h2>Sizes sold</h2>' . bars($bySize, false, 16) . '</section>
<section class="card"><h2>Traffic source</h2>' . bars($byUtm) . '</section><section class="card"><h2>Top districts</h2>' . bars($byDistrict) . '</section></div>
' . $staffCard . '<p class="mut">Numbers exclude cancelled and returned orders, except "By status". Order value includes delivery charges.</p>';
page_foot();
