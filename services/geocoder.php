<?php
// Service Geocoder : convertit une adresse texte en coordonnées GPS via
// l'API Nominatim d'OpenStreetMap (gratuit, sans clé). Avec cache local
// (table geocode_cache, TTL 30 jours) pour limiter les appels réseaux
// et respecter la politique d'usage Nominatim (≤ 1 req/s, User-Agent
// identifiable obligatoire).
require_once __DIR__ . '/../lib/db.php';

const GEOCODE_TTL = 86400 * 30;  // 30 jours

/**
 * Géocode une adresse libre. Renvoie ['lat', 'lng', 'display_name']
 * ou null si non trouvée / erreur.
 */
function geocode(string $query): ?array {
    $query = trim($query);
    if ($query === '') return null;
    $hash = hash('sha256', mb_strtolower($query));

    $pdo = db();
    $stmt = $pdo->prepare("SELECT latitude, longitude, display_name, fetched_at FROM geocode_cache WHERE query_hash = ?");
    $stmt->execute([$hash]);
    $row = $stmt->fetch();

    if ($row && (time() - (int)$row['fetched_at']) < GEOCODE_TTL) {
        if ($row['latitude'] === null) return null;  // négatif caché
        return [
            'lat' => (float)$row['latitude'],
            'lng' => (float)$row['longitude'],
            'display_name' => (string)$row['display_name'],
        ];
    }

    $result = geocode_nominatim($query);
    $ins = $pdo->prepare("INSERT OR REPLACE INTO geocode_cache
                          (query_hash, query_text, latitude, longitude, display_name, fetched_at)
                          VALUES (?, ?, ?, ?, ?, ?)");
    if ($result === null) {
        $ins->execute([$hash, $query, null, null, '', time()]);
    } else {
        $ins->execute([$hash, $query, $result['lat'], $result['lng'], $result['display_name'], time()]);
    }
    return $result;
}

function geocode_nominatim(string $query): ?array {
    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
        'q'              => $query,
        'format'         => 'jsonv2',
        'limit'          => 1,
        'addressdetails' => 0,
    ]);
    $contact = trim((string)($GLOBALS['CONFIG']['admin']['email'] ?? '')) ?: 'contact@example.com';
    $ua = 'mmidate/1.0 (' . rtrim($GLOBALS['CONFIG']['app_url'] ?? 'mmidate', '/') . '; ' . $contact . ')';
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'header'  => "User-Agent: $ua\r\nAccept: application/json\r\nAccept-Language: fr,en\r\n",
            'timeout' => 8,
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) return null;
    $json = json_decode($body, true);
    if (!is_array($json) || empty($json)) return null;
    $r = $json[0];
    if (!isset($r['lat'], $r['lon'])) return null;
    return [
        'lat' => (float)$r['lat'],
        'lng' => (float)$r['lon'],
        'display_name' => (string)($r['display_name'] ?? $query),
    ];
}
