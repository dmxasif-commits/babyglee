<?php
/* Incomplete orders: saves what a visitor typed in the checkout form as soon as the mobile number is valid.
   If the order is completed, api/order.php removes the lead. Otherwise it shows in Admin > Incomplete for follow-up. */
declare(strict_types=1);
require __DIR__ . '/lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['ok' => false], 405);
$in = json_decode((string) file_get_contents('php://input', false, null, 0, 20000), true);
if (!is_array($in)) json_out(['ok' => false], 400);

$token = (string) ($in['token'] ?? '');
$phone = bd_phone((string) ($in['phone'] ?? ''));
if (!preg_match('/^[a-z0-9]{12,40}$/', $token) || $phone === '') json_out(['ok' => false], 422);

$pdo = db();
$str = fn($k, $max) => mb_substr(trim((string) ($in[$k] ?? '')), 0, $max);
$catalog = products(true);
$items = [];
foreach (array_slice((array) ($in['items'] ?? []), 0, 20) as $it) {
    if (!is_array($it) || !isset($catalog[(string) ($it['slug'] ?? '')])) continue;
    $items[] = ['slug' => (string) $it['slug'], 'size' => max(0, min(60, (int) ($it['size'] ?? 0))), 'qty' => max(1, min(10, (int) ($it['qty'] ?? 1)))];
}
$trIn = is_array($in['tracking'] ?? null) ? $in['tracking'] : [];
$tracking = [];
foreach (['fbp', 'fbc', 'fbclid', 'gclid', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'page_url', 'referrer'] as $k) {
    if (isset($trIn[$k]) && is_scalar($trIn[$k])) $tracking[$k] = mb_substr((string) $trIn[$k], 0, 500);
}
$area = stripos((string) ($in['area'] ?? ''), 'inside') !== false ? 'Inside Dhaka' : (($in['area'] ?? '') !== '' ? 'Outside Dhaka' : '');
$source = ($in['source'] ?? '') === 'landing' ? 'landing' : 'store';
$row = [$source, $str('name', 120), '+' . $phone, $str('email', 160), $str('district', 60), $str('address', 600), $area,
    json_encode($items, JSON_UNESCAPED_UNICODE), json_encode($tracking, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), now()];

$ex = $pdo->prepare('SELECT id FROM leads WHERE token = ?'); $ex->execute([$token]);
if ($id = $ex->fetchColumn()) {
    $pdo->prepare('UPDATE leads SET source=?, name=?, phone=?, email=?, district=?, address=?, area=?, items=?, tracking=?, updated_at=? WHERE id=?')->execute(array_merge($row, [$id]));
    json_out(['ok' => true]);
}
/* A number that ordered in the last 30 minutes is not an incomplete order. */
$done = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE phone = ? AND created_at > ?'); $done->execute(['+' . $phone, date('Y-m-d H:i:s', time() - 1800)]);
if ((int) $done->fetchColumn()) json_out(['ok' => true]);
/* Flood protection: at most 60 new incomplete orders per hour from one address. */
$rl = $pdo->prepare('SELECT COUNT(*) FROM leads WHERE ip = ? AND created_at > ?'); $rl->execute([client_ip(), date('Y-m-d H:i:s', time() - 3600)]);
if ((int) $rl->fetchColumn() >= 60) json_out(['ok' => false], 429);
/* One open lead per phone number: a new visit from the same number replaces the old open one. */
$pdo->prepare("DELETE FROM leads WHERE phone = ? AND status = 'new'")->execute(['+' . $phone]);
$pdo->prepare("INSERT INTO leads (source, name, phone, email, district, address, area, items, tracking, updated_at, token, created_at, status, ip, note) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'new',?,'')")
    ->execute(array_merge($row, [$token, now(), client_ip()]));
json_out(['ok' => true]);
