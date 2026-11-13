<?php
/* Receives an order from the storefront, re-checks everything on the server (prices come from the database, never from the browser) and stores it. */
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/capi.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['ok' => false, 'error' => 'POST only'], 405);
$raw = file_get_contents('php://input', false, null, 0, 60000);
$in = json_decode((string) $raw, true);
if (!is_array($in)) json_out(['ok' => false, 'error' => 'Invalid request'], 400);

$str = fn($k, $max) => mb_substr(trim((string) ($in[$k] ?? '')), 0, $max);
$name = $str('name', 120); $email = $str('email', 160); $district = $str('district', 60); $address = $str('address', 600);
$phone = bd_phone((string) ($in['phone'] ?? ''));
$areaKey = stripos((string) ($in['area'] ?? ''), 'inside') !== false ? 'dhaka' : 'outside';
$source = ($in['source'] ?? '') === 'landing' ? 'landing' : 'store';

$errors = [];
if (mb_strlen($name) < 2) $errors[] = 'name';
if ($phone === '') $errors[] = 'phone';
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'email';
if ($district === '') $errors[] = 'district';
if (mb_strlen($address) < 10) $errors[] = 'address';

$catalog = products(true);
$items = []; $subtotal = 0; $cost = 0; $stockMsg = '';
foreach ((array) ($in['items'] ?? []) as $it) {
    if (!is_array($it)) continue;
    $slug = (string) ($it['slug'] ?? ''); $size = (int) ($it['size'] ?? 0); $qty = (int) ($it['qty'] ?? 0);
    if (!isset($catalog[$slug]) || $qty < 1 || $qty > 10 || !in_array($size, $catalog[$slug]['size_list'], true)) { $errors[] = 'items'; continue; }
    $p = $catalog[$slug];
    if ($p['track'] && $p['variants'][$size] < $qty) { $left = max(0, $p['variants'][$size]); $stockMsg = $stockMsg ?: 'Sorry, ' . $p['name'] . ' in EU ' . $size . ($left ? ' has only ' . $left . ' pair' . ($left > 1 ? 's' : '') . ' left.' : ' is sold out.') . ' Please choose another size or quantity.'; continue; }
    $items[] = ['slug' => $slug, 'sku' => $p['sku'], 'product' => $p['name'], 'size' => $size, 'qty' => $qty, 'price' => (int) $p['price'], 'cost' => (int) ($p['cost'] ?? 0)];
    $subtotal += $qty * (int) $p['price']; $cost += $qty * (int) ($p['cost'] ?? 0);
}
if ($stockMsg !== '' && !$errors) json_out(['ok' => false, 'error' => $stockMsg, 'fields' => ['stock']], 409);
if (!$items || count($items) > 20) $errors[] = 'items';
if ($errors) json_out(['ok' => false, 'error' => 'Please check: ' . implode(', ', array_unique($errors)), 'fields' => array_values(array_unique($errors))], 422);

$pdo = db();
$id = (string) ($in['id'] ?? '');
if (!preg_match('/^BG[A-Z0-9]{5,12}$/', $id)) $id = 'BG' . strtoupper(substr(base_convert((string) (int) (microtime(true) * 1000), 10, 36), -7));
$ip = client_ip();

/* Same order sent twice (double tap, retry): answer OK without storing it again. */
$dup = $pdo->prepare('SELECT total FROM orders WHERE id = ?'); $dup->execute([$id]);
if (($t = $dup->fetchColumn()) !== false) json_out(['ok' => true, 'id' => $id, 'total' => (int) $t, 'duplicate' => true]);

/* Basic flood protection. Mobile networks in Bangladesh share one address between many customers, so the limit is generous (config: order_limit_per_hour). */
$limit = max(1, (int) (cfg()['order_limit_per_hour'] ?? 30));
$rl = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE ip = ? AND created_at > ?'); $rl->execute([$ip, date('Y-m-d H:i:s', time() - 3600)]);
if ((int) $rl->fetchColumn() >= $limit) json_out(['ok' => false, 'error' => 'Too many orders from this connection. Please call us to order.'], 429);

$delivery = (int) setting($areaKey === 'dhaka' ? 'delivery_dhaka' : 'delivery_outside');
$total = $subtotal + $delivery;
$area = $areaKey === 'dhaka' ? 'Inside Dhaka' : 'Outside Dhaka';
$ua = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 400);

/* Keep only the tracking fields we know, with size limits. */
$trIn = is_array($in['tracking'] ?? null) ? $in['tracking'] : [];
$tracking = [];
foreach (['event_id', 'fbp', 'fbc', 'fbclid', 'gclid', 'gbraid', 'wbraid', 'ttclid', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'first_utm_source', 'first_utm_campaign', 'ga', 'page_url', 'referrer'] as $k) {
    if (isset($trIn[$k]) && is_scalar($trIn[$k])) $tracking[$k] = mb_substr((string) $trIn[$k], 0, 500);
}
if (isset($trIn['meta_user_data']['external_id']) && preg_match('/^[0-9a-f]{64}$/', (string) $trIn['meta_user_data']['external_id'])) $tracking['external_id'] = $trIn['meta_user_data']['external_id'];
if (empty($tracking['event_id']) || !preg_match('/^[\w-]{5,80}$/', $tracking['event_id'])) $tracking['event_id'] = 'purchase_' . $id;

$pdo->beginTransaction();
/* Take the pairs out of stock first; if someone else just bought the last pair this fails and nothing is saved. */
$take = $pdo->prepare('UPDATE variants SET stock = stock - ? WHERE product_id = ? AND size = ? AND stock >= ?');
foreach ($items as $i) {
    $p = $catalog[$i['slug']];
    if (!$p['track']) continue;
    $take->execute([$i['qty'], $p['id'], $i['size'], $i['qty']]);
    if ($take->rowCount() !== 1) { $pdo->rollBack(); json_out(['ok' => false, 'error' => 'Sorry, ' . $p['name'] . ' in EU ' . $i['size'] . ' just sold out. Please choose another size.', 'fields' => ['stock']], 409); }
}
$pdo->prepare('INSERT INTO orders (id, created_at, status, source, name, phone, email, district, address, area, subtotal, delivery, total, items, tracking, ip, user_agent, note, cost, stock_held) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)')
    ->execute([$id, now(), 'pending', $source, $name, '+' . $phone, $email, $district, $address, $area, $subtotal, $delivery, $total,
        json_encode($items, JSON_UNESCAPED_UNICODE), json_encode($tracking, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $ip, $ua, '', $cost]);
$pdo->prepare('INSERT INTO order_log (order_id, created_at, status, note) VALUES (?,?,?,?)')->execute([$id, now(), 'pending', 'Order placed on website (' . $source . ')']);
$pdo->prepare('DELETE FROM leads WHERE phone = ?')->execute(['+' . $phone]);   // the order is complete: no longer an incomplete order
$pdo->commit();

/* Server-side Meta Conversions API Purchase (only when a Pixel ID and token are saved in Admin > Settings). Never blocks the order. */
try {
    $res = capi_purchase(['id' => $id, 'name' => $name, 'phone' => $phone, 'email' => $email, 'district' => $district, 'total' => $total, 'items' => $items], $tracking, $ip, $ua);
    if ($res !== '') $pdo->prepare('UPDATE orders SET capi_status = ? WHERE id = ?')->execute([mb_substr($res, 0, 120), $id]);
} catch (Throwable $e) { error_log('Babyglee CAPI: ' . $e->getMessage()); }

json_out(['ok' => true, 'id' => $id, 'subtotal' => $subtotal, 'delivery' => $delivery, 'total' => $total]);
