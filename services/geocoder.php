<?php
// Service Geocoder : convertit une adresse texte en coordonnées GPS via
// l'API Nominatim d'OpenStreetMap (gratuit, sans clé). Avec cache local
// (TTL différencié : 30 jours pour les succès, 5 min pour les échecs)
// pour limiter les appels et permettre les retries rapides.
//
// Préfère curl (plus robuste sur SSL/timeouts) avec fallback
// file_get_contents si curl absent. Loggue tous les échecs dans
// data/geocode.log pour diagnostic.
require_once __DIR__ . '/../lib/db.php';

const GEOCODE_TTL_HIT  = 86400 * 30;  // résultats positifs : 30 jours
const GEOCODE_TTL_MISS = 300;         // résultats négatifs : 5 min (retry rapide)

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

    if ($row) {
        $age = time() - (int)$row['fetched_at'];
        $is_negative = ($row['latitude'] === null);
        $ttl = $is_negative ? GEOCODE_TTL_MISS : GEOCODE_TTL_HIT;
        if ($age < $ttl) {
            if ($is_negative) return null;
            return [
                'lat' => (float)$row['latitude'],
                'lng' => (float)$row['longitude'],
                'display_name' => (string)$row['display_name'],
            ];
        }
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

    $body = null;
    $err  = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_USERAGENT      => $ua,
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Accept-Language: fr,en'],
        ]);
        $body = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($body === false) {
            $err = 'curl: ' . curl_error($ch);
            $body = null;
        } elseif ($http !== 200) {
            $err = "HTTP $http: " . substr((string)$body, 0, 200);
            $body = null;
        }
        curl_close($ch);
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => "User-Agent: $ua\r\nAccept: application/json\r\nAccept-Language: fr,en\r\n",
                'timeout'       => 15,
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            $err = 'file_get_contents=false (allow_url_fopen ? DNS ? SSL ?)';
            $body = null;
        }
    }

    if ($body === null) { geocode_log($query, $err); return null; }
    $json = json_decode((string)$body, true);
    if (!is_array($json)) {
        geocode_log($query, 'JSON parse failed: ' . substr((string)$body, 0, 200));
        return null;
    }
    if (empty($json)) {
        geocode_log($query, 'empty result from Nominatim');
        return null;
    }
    $r = $json[0];
    if (!isset($r['lat'], $r['lon'])) {
        geocode_log($query, 'missing lat/lon in first result: ' . substr((string)$body, 0, 200));
        return null;
    }
    return [
        'lat' => (float)$r['lat'],
        'lng' => (float)$r['lon'],
        'display_name' => (string)($r['display_name'] ?? $query),
    ];
}

function geocode_log(string $query, string $msg): void {
    $path = $GLOBALS['CONFIG']['db_path'] ?? '';
    if ($path === '') return;
    $dir = dirname($path);
    if (!is_dir($dir)) return;
    $line = date('c') . " | " . str_replace(["\r","\n"], ' ', $query)
          . " | " . str_replace(["\r","\n"], ' ', $msg) . "\n";
    @file_put_contents($dir . '/geocode.log', $line, FILE_APPEND | LOCK_EX);
}
