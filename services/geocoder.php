<?php
// Service Geocoder : adresse texte → coordonnées GPS via OSM.
// Multi-backend : essaie chaque backend listé dans CONFIG.geocoder.backends
// jusqu'à ce qu'un réponde avec un résultat. Cache local avec TTL
// différencié (30j pour les hits, 5min pour les miss pour permettre
// les retries rapides).
//
// Backends supportés :
//   nominatim : api officielle OSM, souvent 403 sur IP partagées
//   photon    : photon.komoot.io, basé sur OSM, plus permissif
//
// Tous loggués dans data/geocode.log avec leur erreur respective.
require_once __DIR__ . '/../lib/db.php';

const GEOCODE_TTL_HIT  = 86400 * 30;
const GEOCODE_TTL_MISS = 300;

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

    $backends = $GLOBALS['CONFIG']['geocoder']['backends'] ?? ['photon', 'nominatim'];
    $result = null;
    foreach ($backends as $backend) {
        $fn = 'geocode_' . $backend;
        if (!function_exists($fn)) {
            geocode_log($query, "backend inconnu : $backend");
            continue;
        }
        $r = $fn($query);
        if ($r !== null) { $result = $r; break; }
    }

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

// --- Backend : Nominatim OSM ---------------------------------------------

function geocode_nominatim(string $query): ?array {
    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
        'q'              => $query,
        'format'         => 'jsonv2',
        'limit'          => 1,
        'addressdetails' => 0,
    ]);
    [$body, $err] = http_get($url, geocode_user_agent(), [
        'Accept: application/json',
        'Accept-Language: fr,en',
        'From: ' . geocode_contact_email(),
    ]);
    if ($body === null) { geocode_log($query, "nominatim: $err"); return null; }
    $json = json_decode($body, true);
    if (!is_array($json) || empty($json)) {
        geocode_log($query, 'nominatim: ' . (is_array($json) ? 'empty' : 'bad JSON: ' . substr($body, 0, 200)));
        return null;
    }
    $r = $json[0];
    if (!isset($r['lat'], $r['lon'])) {
        geocode_log($query, 'nominatim: missing lat/lon');
        return null;
    }
    return [
        'lat' => (float)$r['lat'],
        'lng' => (float)$r['lon'],
        'display_name' => (string)($r['display_name'] ?? $query),
    ];
}

// --- Backend : Photon (komoot) -------------------------------------------

function geocode_photon(string $query): ?array {
    $url = 'https://photon.komoot.io/api/?' . http_build_query([
        'q'     => $query,
        'limit' => 1,
        'lang'  => 'fr',
    ]);
    [$body, $err] = http_get($url, geocode_user_agent(), [
        'Accept: application/json',
        'Accept-Language: fr,en',
    ]);
    if ($body === null) { geocode_log($query, "photon: $err"); return null; }
    $json = json_decode($body, true);
    if (!is_array($json) || empty($json['features'])) {
        geocode_log($query, 'photon: ' . (is_array($json) ? 'no features' : 'bad JSON'));
        return null;
    }
    $f = $json['features'][0];
    $coords = $f['geometry']['coordinates'] ?? null;
    if (!is_array($coords) || count($coords) < 2) {
        geocode_log($query, 'photon: missing coordinates');
        return null;
    }
    // Photon : coordinates = [lng, lat]
    $lng = (float)$coords[0];
    $lat = (float)$coords[1];

    // Reconstitue un display_name lisible depuis les properties.
    $p = $f['properties'] ?? [];
    $parts = array_filter([
        $p['name'] ?? null,
        $p['housenumber'] ?? null,
        $p['street'] ?? null,
        $p['postcode'] ?? null,
        $p['city'] ?? $p['locality'] ?? null,
        $p['state'] ?? null,
        $p['country'] ?? null,
    ]);
    $display = implode(', ', array_values(array_unique($parts)));
    if ($display === '') $display = $query;

    return ['lat' => $lat, 'lng' => $lng, 'display_name' => $display];
}

// --- Helpers HTTP / UA / log --------------------------------------------

function http_get(string $url, string $ua, array $headers = []): array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_USERAGENT      => $ua,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $body = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);
        if ($body === false) return [null, 'curl: ' . $cerr];
        if ($http !== 200)   return [null, "HTTP $http: " . substr((string)$body, 0, 200)];
        return [(string)$body, ''];
    }
    $hdr = "User-Agent: $ua\r\n";
    foreach ($headers as $h) $hdr .= $h . "\r\n";
    $ctx = stream_context_create([
        'http' => ['method' => 'GET', 'header' => $hdr, 'timeout' => 15, 'ignore_errors' => true],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) return [null, 'file_get_contents=false (allow_url_fopen ?)'];
    return [(string)$body, ''];
}

function geocode_user_agent(): string {
    $app = rtrim((string)($GLOBALS['CONFIG']['app_url'] ?? 'mmidate'), '/');
    $contact = geocode_contact_email();
    return "mmidate/1.0 ($app; $contact)";
}

function geocode_contact_email(): string {
    $email = trim((string)($GLOBALS['CONFIG']['admin']['email'] ?? ''));
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) return $email;
    // Fallback : noreply@<domain de app_url>
    $host = parse_url((string)($GLOBALS['CONFIG']['app_url'] ?? ''), PHP_URL_HOST) ?: 'mmidate.local';
    return 'noreply@' . $host;
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
