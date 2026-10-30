<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/Helpers/bootstrap.php';

use App\Middleware\Auth;

Auth::requireLogin();
$user = Auth::user();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store');

// Account-ID 1 is het account van Richard Luijten.
// Nooit enkel op basis van naam of rol toegang verlenen.
if ((int) ($user['id'] ?? 0) !== 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Geen toegang.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Methode niet toegestaan.']);
    exit;
}

$bounds = [
    'antwerpen' => '4.18,51.05,4.72,51.40',
    'gent' => '3.53,50.90,4.03,51.23',
];
$region = (string) ($_GET['regio'] ?? 'antwerpen');
if (!isset($bounds[$region])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Ongeldige regio.']);
    exit;
}
session_write_close();

$cache = sys_get_temp_dir() . '/att-gipod-wegenwerken-' . $region . '.json';
if (is_file($cache) && time() - (int) filemtime($cache) < 900) {
    $cached = file_get_contents($cache);
    if ($cached !== false && json_validate($cached)) {
        echo $cached;
        exit;
    }
}

$url = 'https://geo.api.vlaanderen.be/GIPOD/ogc/features/v1/'
    . 'collections/HINDER_PUNT/items?'
    . http_build_query([
        'f' => 'json',
        'bbox' => $bounds[$region],
        'limit' => 500,
    ], '', '&', PHP_QUERY_RFC3986);

if (!function_exists('curl_init')) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'De PHP cURL-extensie ontbreekt.']);
    exit;
}

$buffer = '';
$tooLarge = false;
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 18,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_HTTPHEADER => ['Accept: application/geo+json, application/json'],
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$buffer, &$tooLarge): int {
        if (strlen($buffer) + strlen($chunk) > 8 * 1024 * 1024) {
            $tooLarge = true;
            return 0;
        }
        $buffer .= $chunk;
        return strlen($chunk);
    },
]);
$success = curl_exec($ch);
$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

$payload = $success && $status === 200 && !$tooLarge
    ? json_decode($buffer, true)
    : null;
if (!is_array($payload) || !is_array($payload['features'] ?? null)) {
    http_response_code(502);
    echo json_encode([
        'ok' => false,
        'error' => 'GIPOD is tijdelijk niet bereikbaar. Probeer later opnieuw.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$asText = static function (array $props, string $key): string {
    $value = $props[$key] ?? null;
    return is_scalar($value) ? trim((string) $value) : '';
};
$items = [];
$now = time();
foreach ($payload['features'] as $feature) {
    if (!is_array($feature)) {
        continue;
    }
    $geometry = $feature['geometry'] ?? [];
    $coordinates = $geometry['coordinates'] ?? null;
    if (($geometry['type'] ?? '') !== 'Point'
        || !is_array($coordinates)
        || count($coordinates) < 2
        || !is_numeric($coordinates[0])
        || !is_numeric($coordinates[1])) {
        continue;
    }
    $lng = (float) $coordinates[0];
    $lat = (float) $coordinates[1];
    if ($lat < 49.5 || $lat > 52.5 || $lng < 2.4 || $lng > 6.5) {
        continue;
    }
    $props = is_array($feature['properties'] ?? null)
        ? $feature['properties']
        : [];
    $end = $asText($props, 'HindranceEnd');
    if ($end !== '' && strtotime($end) !== false && strtotime($end) < $now) {
        continue;
    }
    $source = $asText($props, 'HindranceURI');
    if (!str_starts_with($source, 'https://')) {
        $source = '';
    }
    $items[] = [
        'id' => $asText($props, 'HindranceGipodId'),
        'lat' => $lat,
        'lng' => $lng,
        'title' => $asText($props, 'HindranceDescription') ?: 'Verkeershinder',
        'details' => $asText($props, 'Consequences'),
        'status' => $asText($props, 'HindranceStatus'),
        'start' => $asText($props, 'HindranceStart'),
        'end' => $end,
        'severe' => ($props['SevereHindrance'] ?? false) === true,
        'source' => $source,
    ];
}
usort($items, static fn(array $a, array $b): int =>
    strcmp($a['start'], $b['start']));
$response = json_encode([
    'ok' => true,
    'source' => 'GIPOD – Digitaal Vlaanderen',
    'region' => $region,
    'updated' => date(DATE_ATOM),
    'limited' => count($payload['features']) >= 500,
    'items' => $items,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

if ($response === false) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Gegevens konden niet verwerkt worden.']);
    exit;
}
@file_put_contents($cache, $response, LOCK_EX);
echo $response;
