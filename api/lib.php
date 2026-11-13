<?php
/* Babyglee backend: shared helpers (config, database, settings, products). PHP 7.4+ / 8.x, MySQL (cPanel) or SQLite (local testing). */
declare(strict_types=1);
date_default_timezone_set('Asia/Dhaka');

const BG_STATUSES = ['pending' => 'Pending', 'confirmed' => 'Confirmed', 'shipped' => 'Shipped', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled', 'returned' => 'Returned'];
const BG_SIZES = [21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36];
const BG_DEFAULTS = ['whatsapp' => '8801974974973', 'delivery_dhaka' => '80', 'delivery_outside' => '130', 'gtm_id' => '', 'gtm_src' => '',
    'meta_pixel_id' => '', 'meta_capi_token' => '', 'meta_test_code' => '', 'meta_api_version' => 'v23.0',
    'biz_name' => 'Babyglee', 'biz_phone' => '+880 1974-974973', 'biz_address' => 'Pallabi, Mirpur 12, Dhaka', 'invoice_note' => 'Exchange within 72 hours of delivery. Thank you for shopping with Babyglee!'];

function cfg(): array {
    static $c = null;
    if ($c === null) {
        $f = getenv('BG_CONFIG') ?: __DIR__ . '/config.php';
        if (!is_file($f)) { http_response_code(503); header('Content-Type: text/plain'); exit('Backend is not configured yet: copy api/config.sample.php to api/config.php and fill it in.'); }
        $c = require $f;
    }
    return $c;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $d = cfg()['db'];
    try {
        if (($d['driver'] ?? 'mysql') === 'sqlite') {
            $pdo = new PDO('sqlite:' . $d['path']);
            $pdo->exec('PRAGMA busy_timeout = 5000');
        } else {
            $pdo = new PDO('mysql:host=' . ($d['host'] ?? 'localhost') . ';dbname=' . $d['name'] . ';charset=utf8mb4', $d['user'], $d['pass']);
        }
    } catch (Throwable $e) {
        error_log('Babyglee DB connect failed: ' . $e->getMessage());
        http_response_code(503); header('Content-Type: text/plain'); exit('Database connection failed. Check api/config.php.');
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    migrate($pdo, ($d['driver'] ?? 'mysql') === 'sqlite');
    return $pdo;
}

function migrate(PDO $pdo, bool $sqlite): void {
    $ai = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $eng = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    $ver = 0;
    try { $ver = (int) $pdo->query("SELECT v FROM settings WHERE k = 'schema_version'")->fetchColumn(); } catch (Throwable $e) { /* first run */ }
    if ($ver >= 5) return;
    if ($ver < 1) {
    $ai = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $eng = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (k VARCHAR(64) PRIMARY KEY, v TEXT)$eng");
    $pdo->exec("CREATE TABLE IF NOT EXISTS products (id $ai, slug VARCHAR(80) NOT NULL UNIQUE, sku VARCHAR(60) NOT NULL, name VARCHAR(150) NOT NULL, color VARCHAR(80) NOT NULL DEFAULT '',
        color_hex VARCHAR(9) NOT NULL DEFAULT '#24babc', img VARCHAR(255) NOT NULL DEFAULT '', price INT NOT NULL, list_price INT NOT NULL, price_usd INT NOT NULL DEFAULT 0,
        sizes VARCHAR(255) NOT NULL DEFAULT '', page VARCHAR(120) NOT NULL DEFAULT '', active TINYINT NOT NULL DEFAULT 1, sort INT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL)$eng");
    $pdo->exec("CREATE TABLE IF NOT EXISTS orders (id VARCHAR(20) PRIMARY KEY, created_at DATETIME NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'pending', source VARCHAR(20) NOT NULL DEFAULT 'store',
        name VARCHAR(120) NOT NULL, phone VARCHAR(20) NOT NULL, email VARCHAR(160) NOT NULL DEFAULT '', district VARCHAR(60) NOT NULL DEFAULT '', address TEXT, area VARCHAR(30) NOT NULL DEFAULT '',
        subtotal INT NOT NULL, delivery INT NOT NULL, total INT NOT NULL, payment VARCHAR(40) NOT NULL DEFAULT 'Cash on Delivery', items TEXT, tracking TEXT,
        ip VARCHAR(45) NOT NULL DEFAULT '', user_agent VARCHAR(400) NOT NULL DEFAULT '', note TEXT, capi_status VARCHAR(120) NOT NULL DEFAULT '')$eng");
    $pdo->exec("CREATE TABLE IF NOT EXISTS order_log (id $ai, order_id VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, status VARCHAR(20) NOT NULL, note TEXT)$eng");
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (id $ai, ip VARCHAR(45) NOT NULL, created_at DATETIME NOT NULL)$eng");
    foreach (['CREATE INDEX idx_orders_created ON orders (created_at)', 'CREATE INDEX idx_orders_status ON orders (status)', 'CREATE INDEX idx_orders_phone ON orders (phone)', 'CREATE INDEX idx_log_order ON order_log (order_id)'] as $q) {
        try { $pdo->exec($q); } catch (Throwable $e) { /* index already exists */ }
    }
    if (!(int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn()) {
        $seed = [['sky-blue', 'Sky Blue & Yellow', '#3fb4e8', 'sky-blue-yellow'], ['blue-beige', 'Blue & Beige', '#3d7fa5', 'blue-beige'], ['grey', 'Grey', '#8a9096', 'grey'],
            ['navy-brown', 'Navy & Brown', '#26335a', 'navy-brown'], ['black', 'Black', '#262626', 'black']];
        $st = $pdo->prepare('INSERT INTO products (slug, sku, name, color, color_hex, img, price, list_price, price_usd, sizes, page, active, sort, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,1,?,?)');
        foreach ($seed as $i => $s) {
            $st->execute(['bounce-' . $s[0], 'BSTD-01-' . strtoupper($s[0]), 'Babyglee Bounce — ' . $s[1], $s[1], $s[2], 'img/' . $s[3] . '.webp', 1290, 1800, 15, implode(',', BG_SIZES), 'product-' . $s[0] . '.html', $i, now()]);
        }
    }
    }
    /* version 2: purchase price (cost) for profit, courier details on orders */
    foreach (["ALTER TABLE products ADD COLUMN cost INT NOT NULL DEFAULT 0", "ALTER TABLE orders ADD COLUMN cost INT NOT NULL DEFAULT 0",
        "ALTER TABLE orders ADD COLUMN courier VARCHAR(60) NOT NULL DEFAULT ''", "ALTER TABLE orders ADD COLUMN tracking_code VARCHAR(80) NOT NULL DEFAULT ''"] as $q) {
        try { $pdo->exec($q); } catch (Throwable $e) { /* column already exists */ }
    }
    /* version 3: size variations with their own stock */
    $pdo->exec("CREATE TABLE IF NOT EXISTS variants (product_id INT NOT NULL, size INT NOT NULL, stock INT NOT NULL DEFAULT 0, PRIMARY KEY (product_id, size))" . ($sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'));
    foreach (["ALTER TABLE products ADD COLUMN track_stock TINYINT NOT NULL DEFAULT 0", "ALTER TABLE orders ADD COLUMN stock_held TINYINT NOT NULL DEFAULT 0"] as $q) {
        try { $pdo->exec($q); } catch (Throwable $e) { /* column already exists */ }
    }
    $has = $pdo->prepare('SELECT COUNT(*) FROM variants WHERE product_id = ?'); $ins = $pdo->prepare('INSERT INTO variants (product_id, size, stock) VALUES (?, ?, 0)');
    foreach ($pdo->query('SELECT id, sizes FROM products')->fetchAll() as $r) {
        $has->execute([$r['id']]); if ((int) $has->fetchColumn()) continue;
        foreach (array_unique(array_filter(array_map('intval', explode(',', (string) $r['sizes'])))) as $s) $ins->execute([$r['id'], $s]);
    }
    /* version 4: incomplete orders (checkout started, not finished) */
    $pdo->exec("CREATE TABLE IF NOT EXISTS leads (id $ai, token VARCHAR(40) NOT NULL UNIQUE, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, status VARCHAR(12) NOT NULL DEFAULT 'new',
        source VARCHAR(20) NOT NULL DEFAULT 'store', name VARCHAR(120) NOT NULL DEFAULT '', phone VARCHAR(20) NOT NULL, email VARCHAR(160) NOT NULL DEFAULT '', district VARCHAR(60) NOT NULL DEFAULT '',
        address TEXT, area VARCHAR(30) NOT NULL DEFAULT '', items TEXT, tracking TEXT, ip VARCHAR(45) NOT NULL DEFAULT '', note TEXT)$eng");
    try { $pdo->exec('CREATE INDEX idx_leads_phone ON leads (phone)'); } catch (Throwable $e) { /* index already exists */ }
    /* version 5: staff accounts, who did what, ad spend */
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (id $ai, username VARCHAR(40) NOT NULL UNIQUE, name VARCHAR(80) NOT NULL, pass_hash VARCHAR(255) NOT NULL, role VARCHAR(10) NOT NULL DEFAULT 'staff', active TINYINT NOT NULL DEFAULT 1, created_at DATETIME NOT NULL)$eng");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ad_spend (id $ai, day DATE NOT NULL, campaign VARCHAR(190) NOT NULL, amount INT NOT NULL, note VARCHAR(200) NOT NULL DEFAULT '')$eng");
    foreach (["ALTER TABLE order_log ADD COLUMN by_user VARCHAR(80) NOT NULL DEFAULT ''", "ALTER TABLE orders ADD COLUMN created_by VARCHAR(80) NOT NULL DEFAULT ''"] as $q) {
        try { $pdo->exec($q); } catch (Throwable $e) { /* column already exists */ }
    }
    /* the single admin password of earlier versions becomes the user "admin" */
    $old = $pdo->query("SELECT v FROM settings WHERE k = 'admin_hash'")->fetchColumn();
    if ($old && !(int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()) {
        $pdo->prepare("INSERT INTO users (username, name, pass_hash, role, active, created_at) VALUES ('admin', 'Admin', ?, 'admin', 1, ?)")->execute([$old, now()]);
        $pdo->exec("DELETE FROM settings WHERE k = 'admin_hash'");
    }
    set_setting('schema_version', '5');
}

function now(): string { return date('Y-m-d H:i:s'); }
function h($s): string { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function taka($n): string { $n = (int) round((float) $n); return ($n < 0 ? '-' : '') . '৳' . number_format(abs($n)); }

function setting(string $k, ?string $default = null): string {
    static $all = null;
    if ($k === '__reset') { $all = null; return ''; }
    if ($all === null) { $all = []; foreach (db()->query('SELECT k, v FROM settings') as $r) $all[$r['k']] = (string) $r['v']; }
    return $all[$k] ?? ($default ?? (BG_DEFAULTS[$k] ?? ''));
}
function set_setting(string $k, string $v): void {
    $pdo = db();
    $pdo->prepare('DELETE FROM settings WHERE k = ?')->execute([$k]);
    $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?)')->execute([$k, $v]);
    setting('__reset');
}

function products(bool $activeOnly = true): array {
    $rows = db()->query('SELECT * FROM products' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY sort, id')->fetchAll();
    $out = [];
    $vars = [];
    foreach (db()->query('SELECT product_id, size, stock FROM variants ORDER BY size') as $v) $vars[(int) $v['product_id']][(int) $v['size']] = (int) $v['stock'];
    foreach ($rows as $r) {
        $r['variants'] = $vars[(int) $r['id']] ?? [];                 // size => pairs in stock
        $r['track'] = (int) ($r['track_stock'] ?? 0) === 1;           // false = stock is not counted (always available)
        $r['size_list'] = array_keys($r['variants']);
        $out[$r['slug']] = $r;
    }
    return $out;
}

/* Adds (+1) or removes (-1) the pairs of an order from stock, for products that count stock. */
function stock_apply(PDO $pdo, array $items, int $dir): void {
    $find = $pdo->prepare('SELECT id, track_stock FROM products WHERE slug = ?');
    $upd = $pdo->prepare('UPDATE variants SET stock = stock + ? WHERE product_id = ? AND size = ?');
    foreach ($items as $i) {
        $find->execute([(string) ($i['slug'] ?? '')]); $p = $find->fetch();
        if ($p && (int) $p['track_stock'] === 1) $upd->execute([$dir * (int) $i['qty'], $p['id'], (int) $i['size']]);
    }
}

/* Keeps stock in step with the order status: cancelled / returned orders give their pairs back; re-opened orders take them again. */
function order_stock_sync(PDO $pdo, string $id, string $status): void {
    $st = $pdo->prepare('SELECT items, stock_held FROM orders WHERE id = ?'); $st->execute([$id]); $o = $st->fetch();
    if (!$o) return;
    $want = in_array($status, ['cancelled', 'returned'], true) ? 0 : 1;
    if ($want === (int) $o['stock_held']) return;
    stock_apply($pdo, json_decode((string) $o['items'], true) ?: [], $want ? -1 : 1);
    $pdo->prepare('UPDATE orders SET stock_held = ? WHERE id = ?')->execute([$want, $id]);
}

function client_ip(): string { return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45); }

/* Normalises a Bangladeshi mobile number to 8801XXXXXXXXX (digits only) or returns '' when invalid. */
function bd_phone(string $raw): string {
    $d = preg_replace('/\D+/', '', $raw) ?? '';
    if (strpos($d, '880') === 0) $d = substr($d, 3);
    if (strpos($d, '0') === 0) $d = substr($d, 1);
    return preg_match('/^1[3-9]\d{8}$/', $d) ? '880' . $d : '';
}

function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* Site base path, e.g. "/" or "/shop/" (the folder that contains api/ and admin/). */
function base_path(): string {
    $dir = str_replace('\\', '/', dirname(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/api/x.php'))));
    return rtrim($dir, '/') . '/';
}
