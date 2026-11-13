<?php
/* Meta Conversions API: sends the Purchase from the server with the same event_id the browser used, so Meta de-duplicates the two.
   Customer data is normalised and hashed here from the stored order (never trusted from the browser). */
declare(strict_types=1);

function capi_hash(string $v): string { return $v === '' ? '' : hash('sha256', $v); }
function capi_clean(string $v): string { return preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtolower(trim($v))) ?? ''; }

function capi_payload(array $order, array $tracking, string $ip, string $ua): array {
    $parts = preg_split('/\s+/', trim(mb_strtolower($order['name']))) ?: [];
    $user = array_filter([
        'ph' => capi_hash($order['phone']),                                  // digits with country code, no plus
        'em' => capi_hash(mb_strtolower(trim($order['email']))),
        'fn' => capi_hash(capi_clean($parts[0] ?? '')),
        'ln' => capi_hash(capi_clean(implode(' ', array_slice($parts, 1)))),
        'ct' => capi_hash(capi_clean($order['district'])),
        'country' => capi_hash('bd'),
        'external_id' => $tracking['external_id'] ?? '',
        'fbp' => $tracking['fbp'] ?? '', 'fbc' => $tracking['fbc'] ?? '',
        'client_ip_address' => $ip, 'client_user_agent' => $ua,
    ], fn($v) => $v !== '');
    $event = [
        'event_name' => 'Purchase', 'event_time' => time(), 'event_id' => $tracking['event_id'], 'action_source' => 'website',
        'user_data' => $user,
        'custom_data' => ['currency' => 'BDT', 'value' => (float) $order['total'], 'order_id' => $order['id'], 'content_type' => 'product',
            'content_ids' => array_values(array_unique(array_column($order['items'], 'sku'))),
            'contents' => array_map(fn($i) => ['id' => $i['sku'], 'quantity' => $i['qty'], 'item_price' => $i['price']], $order['items']),
            'num_items' => array_sum(array_column($order['items'], 'qty'))],
    ];
    if (!empty($tracking['page_url'])) $event['event_source_url'] = $tracking['page_url'];
    return $event;
}

/* Returns '' when CAPI is not configured, otherwise a short status text that is saved on the order. */
function capi_purchase(array $order, array $tracking, string $ip, string $ua): string {
    $pixel = setting('meta_pixel_id'); $token = setting('meta_capi_token');
    if ($pixel === '' || $token === '' || !function_exists('curl_init')) return '';
    $body = ['data' => [capi_payload($order, $tracking, $ip, $ua)], 'access_token' => $token];
    if (setting('meta_test_code') !== '') $body['test_event_code'] = setting('meta_test_code');
    $url = (getenv('BG_CAPI_URL') ?: 'https://graph.facebook.com') . '/' . rawurlencode(setting('meta_api_version')) . '/' . rawurlencode($pixel) . '/events';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3]);
    $resp = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    if ($resp === false) return 'failed: ' . $err;
    if ($code >= 200 && $code < 300) return 'sent ' . date('H:i');
    $j = json_decode((string) $resp, true);
    return 'error ' . $code . ': ' . ($j['error']['message'] ?? 'unknown');
}
