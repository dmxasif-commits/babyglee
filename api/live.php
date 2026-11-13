<?php
/* Serves assets/config.js and assets/products.js from the database (via .htaccess rewrite), so admin changes go live without rebuilding the site. */
declare(strict_types=1);
require __DIR__ . '/lib.php';

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: public, max-age=60');
header('X-Content-Type-Options: nosniff');
$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP;
$products = products(true);

if (($_GET['f'] ?? '') === 'products') {
    $out = [];
    foreach ($products as $slug => $p) {
        $out[$slug] = ['title' => $p['name'], 'name' => $p['color'] !== '' ? $p['color'] : $p['name'], 'img' => $p['img'], 'sku' => $p['sku'], 'color' => $p['color'], 'colorHex' => $p['color_hex'],
            'url' => $p['page'] !== '' ? $p['page'] : 'product.html?p=' . rawurlencode($slug), 'price' => (int) $p['price'], 'listPrice' => (int) $p['list_price'], 'priceUsd' => (int) $p['price_usd'], 'sizes' => $p['size_list']];
    }
    echo "/* live from database */\nwindow.BG_PRODUCTS = " . json_encode((object) $out, $flags) . ";\n";
    exit;
}

$prices = array_map(fn($p) => (int) $p['price'], $products);
$lists = array_map(fn($p) => (int) $p['list_price'], $products);
$usd = array_map(fn($p) => (int) $p['price_usd'], $products);
$base = base_path();
$config = [
    'LIVE' => true, 'BASE' => $base,
    'WHATSAPP' => setting('whatsapp'),
    'PRICE' => $prices ? min($prices) : 0, 'LIST_PRICE' => $lists ? max($lists) : 0, 'PRICE_USD' => $usd ? max($usd) : 0,
    'DELIVERY' => ['dhaka' => (int) setting('delivery_dhaka'), 'outside' => (int) setting('delivery_outside')],
    'ORDER_ENDPOINT' => $base . 'api/order.php',
    'GTM_ID' => setting('gtm_id'), 'GTM_SRC' => setting('gtm_src'),
];
echo "/* live from database */\nwindow.BG = " . json_encode($config, $flags) . ";\n";
