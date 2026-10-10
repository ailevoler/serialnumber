<?php
declare(strict_types=1);

/**
 * URide place search that works without Google: Admin landmarks (uride_places), approved merchants with a
 * map pin, and the customer's own recent pick-up / drop-off places. Google Places suggestions are added
 * on the page when the key allows it.
 */
const URIDE_PLACE_RADIUS_KM = 40;

function uride_places_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready === null) {
        try { $pdo->query('SELECT 1 FROM uride_places LIMIT 0'); $ready = true; } catch (Throwable) { $ready = false; }
    }
    return $ready;
}

/** Normalised text for matching: lower case, no accents or punctuation ("D'Mall" -> "dmall"). */
function uride_place_norm(string $s): string
{
    $s = mb_strtolower(trim($s));
    $s = strtr($s, ['ñ' => 'n', 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
    return preg_replace('/[^a-z0-9]+/', '', $s) ?? '';
}

/** 0 = no match; higher = better. Every typed word must match the name or keywords. */
function uride_place_score(string $query, string $name, string $extra = ''): int
{
    $words = array_values(array_filter(preg_split('/\s+/u', mb_strtolower(trim($query))) ?: [], static fn($w) => uride_place_norm($w) !== ''));
    if (!$words) return 1;
    $hayName = uride_place_norm($name);
    $hay = $hayName . ' ' . uride_place_norm($extra) . ' ' . preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($name . ' ' . $extra));
    $score = 0;
    foreach ($words as $w) {
        $n = uride_place_norm($w);
        if (!str_contains($hay, $n)) return 0;
        $score += str_starts_with($hayName, $n) ? 30 : (preg_match('/(^|\s)' . preg_quote($n, '/') . '/', $hay) ? 20 : 10);
    }
    if (uride_place_norm($query) === $hayName) $score += 50;
    return $score;
}

/**
 * Suggestions for the pick-up / drop-off fields. Empty query: recent places, then landmarks.
 * Each item: name, sub, lat, lng, kind (recent | landmark | merchant).
 */
function uride_place_search(PDO $pdo, int $userId, string $query, int $limit = 8): array
{
    $query = mb_substr(trim($query), 0, 80);
    $items = [];
    // One entry per spot and per name (a recent ride to a landmark is not listed twice); the best score wins.
    $add = static function (array $item, int $score) use (&$items): void {
        $keys = [round($item['lat'], 4) . ',' . round($item['lng'], 4), 'n:' . uride_place_norm($item['name'])];
        foreach ($keys as $k) if (isset($items[$k]) && $items[$k]['score'] >= $score) return;
        foreach ($keys as $k) {
            if (isset($items[$k])) { $old = $items[$k]; foreach ([round($old['lat'], 4) . ',' . round($old['lng'], 4), 'n:' . uride_place_norm($old['name'])] as $ok) unset($items[$ok]); }
        }
        foreach ($keys as $k) $items[$k] = $item + ['score' => $score];
    };
    // The customer's own recent places (most useful when the field is empty).
    $stmt = $pdo->prepare('SELECT pickup_address, pickup_lat, pickup_lng, dropoff_address, dropoff_lat, dropoff_lng FROM uride_requests WHERE user_id = ? ORDER BY id DESC LIMIT 15');
    $stmt->execute([$userId]);
    $seen = 0;
    foreach ($stmt->fetchAll() as $r) {
        foreach (['pickup', 'dropoff'] as $k) {
            $name = (string) $r[$k . '_address'];
            if ($name === '' || str_starts_with($name, 'Pinned location') || $r[$k . '_lat'] === null) continue;
            $s = uride_place_score($query, $name);
            if ($s > 0) $add(['name' => $name, 'sub' => 'Your recent place', 'lat' => (float) $r[$k . '_lat'], 'lng' => (float) $r[$k . '_lng'], 'kind' => 'recent'], $s + ($query === '' ? 100 - $seen++ : 5));
        }
    }
    if (uride_places_ready($pdo)) {
        foreach ($pdo->query('SELECT name, area, keywords, latitude, longitude, sort_order FROM uride_places WHERE active = 1 ORDER BY sort_order, name') as $p) {
            $s = uride_place_score($query, (string) $p['name'], ($p['area'] ?? '') . ' ' . ($p['keywords'] ?? ''));
            if ($s > 0) $add(['name' => $p['name'], 'sub' => $p['area'] ?: 'Landmark', 'lat' => (float) $p['latitude'], 'lng' => (float) $p['longitude'], 'kind' => 'landmark'], $s + ($query === '' ? 50 - min(49, intdiv((int) $p['sort_order'], 10)) : 8));
        }
    }
    if ($query !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], mb_substr($query, 0, 40)) . '%';
        $stmt = $pdo->prepare("SELECT business_name, barangay, address, latitude, longitude FROM merchants WHERE status = 'approved' AND latitude IS NOT NULL AND longitude IS NOT NULL
            AND (business_name LIKE ? OR address LIKE ? OR barangay LIKE ?) LIMIT 40");
        $stmt->execute([$like, $like, $like]);
        foreach ($stmt->fetchAll() as $m) {
            $s = uride_place_score($query, (string) $m['business_name'], ($m['barangay'] ?? '') . ' ' . ($m['address'] ?? ''));
            if ($s > 0) $add(['name' => $m['business_name'], 'sub' => trim(($m['address'] ?: '') . ($m['barangay'] ? ', ' . $m['barangay'] : ''), ', ') ?: 'Merchant', 'lat' => (float) $m['latitude'], 'lng' => (float) $m['longitude'], 'kind' => 'merchant'], $s);
        }
    }
    // Only places in the service area: within URIDE_PLACE_RADIUS_KM of the default map centre (Admin › Settings › Maps).
    // This also hides merchants whose map pin was placed far away by mistake.
    $g = google_maps_settings();
    $items = array_values(array_unique($items, SORT_REGULAR));
    $items = array_values(array_filter($items, static fn($i) => uride_distance($g['lat'], $g['lng'], $i['lat'], $i['lng']) <= URIDE_PLACE_RADIUS_KM));
    usort($items, static fn($a, $b) => [$b['score'], $a['name']] <=> [$a['score'], $b['name']]);
    return array_map(static function ($i) { unset($i['score']); return $i; }, array_slice($items, 0, $limit));
}

/** Nearest known place to a point (for labelling a GPS or map pin when reverse geocoding is not available). */
function uride_place_nearest(PDO $pdo, float $lat, float $lng, float $withinKm = 0.4): ?array
{
    if (!uride_places_ready($pdo)) return null;
    $best = null;
    foreach ($pdo->query('SELECT name, area, latitude, longitude FROM uride_places WHERE active = 1') as $p) {
        $d = uride_distance($lat, $lng, (float) $p['latitude'], (float) $p['longitude']);
        if ($d <= $withinKm && (!$best || $d < $best['km'])) $best = ['name' => $p['name'], 'km' => $d];
    }
    return $best;
}
