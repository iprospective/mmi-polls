<?php
// Service Routing : (lat,lng) → (lat,lng) → distance + durée + géométrie.
// Multi-backend : OSRM en priorité (route routière réaliste), fallback
// sur Haversine (vol d'oiseau, ligne droite) si OSRM est down ou désactivé.
//
// Cache global keyé sur le couple d'endpoints arrondi à 5 décimales (~1m
// de précision), donc deux participant·e·s avec la même adresse partagent
// l'entrée. Hits 30 jours, miss 5 minutes (pour permettre retries rapides
// après une panne d'OSRM).
//
// Tous loggués dans data/routing.log avec leur erreur respective.
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../services/geocoder.php'; // http_get + ua + log helpers

const ROUTE_TTL_HIT  = 86400 * 30;
const ROUTE_TTL_MISS = 300;

/**
 * Renvoie un trajet routier entre deux points GPS.
 *
 * @return array{distance_m:int, duration_s:int, geometry:array, backend:string}|null
 *   geometry = liste de [lng, lat] (format GeoJSON LineString)
 *   null si tout échoue (très rare : Haversine ne fail jamais avec des coords valides)
 */
function route_between(float $from_lat, float $from_lng, float $to_lat, float $to_lng): ?array {
    $key = route_endpoints_hash($from_lat, $from_lng, $to_lat, $to_lng);
    $pdo = db();

    $stmt = $pdo->prepare("SELECT * FROM route_cache WHERE endpoints_hash = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    if ($row) {
        $age = time() - (int)$row['fetched_at'];
        $is_negative = ((int)$row['distance_m'] === 0 && (string)$row['geometry'] === '');
        $ttl = $is_negative ? ROUTE_TTL_MISS : ROUTE_TTL_HIT;
        if ($age < $ttl && !$is_negative) {
            $geom = json_decode((string)$row['geometry'], true);
            return [
                'distance_m' => (int)$row['distance_m'],
                'duration_s' => (int)$row['duration_s'],
                'geometry'   => is_array($geom) ? $geom : [],
                'backend'    => (string)$row['backend'],
            ];
        }
    }

    $backends = $GLOBALS['CONFIG']['routing']['backends'] ?? ['osrm', 'haversine'];
    $result = null;
    foreach ($backends as $backend) {
        $fn = 'route_' . $backend;
        if (!function_exists($fn)) {
            routing_log("backend inconnu : $backend");
            continue;
        }
        $r = $fn($from_lat, $from_lng, $to_lat, $to_lng);
        if ($r !== null) { $result = $r; break; }
    }

    // Persiste (positif ou négatif, le négatif ayant un TTL court).
    $ins = $pdo->prepare(
        "INSERT OR REPLACE INTO route_cache
         (endpoints_hash, from_lat, from_lng, to_lat, to_lng, distance_m, duration_s, geometry, backend, fetched_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if ($result === null) {
        $ins->execute([$key, $from_lat, $from_lng, $to_lat, $to_lng, 0, 0, '', '', time()]);
    } else {
        $ins->execute([
            $key, $from_lat, $from_lng, $to_lat, $to_lng,
            $result['distance_m'], $result['duration_s'],
            json_encode($result['geometry'], JSON_UNESCAPED_UNICODE),
            $result['backend'], time(),
        ]);
    }
    return $result;
}

function route_endpoints_hash(float $a_lat, float $a_lng, float $b_lat, float $b_lng): string {
    $fmt = fn(float $v) => number_format($v, 5, '.', '');
    return hash('sha256', $fmt($a_lat) . ',' . $fmt($a_lng) . '|' . $fmt($b_lat) . ',' . $fmt($b_lng));
}

/**
 * Invalide le cache pour un couple précis d'endpoints. Utile quand l'utilisateur
 * veut forcer un re-fetch (bouton « Recalculer trajets »).
 */
function route_cache_invalidate(float $a_lat, float $a_lng, float $b_lat, float $b_lng): void {
    $key = route_endpoints_hash($a_lat, $a_lng, $b_lat, $b_lng);
    $stmt = db()->prepare("DELETE FROM route_cache WHERE endpoints_hash = ?");
    $stmt->execute([$key]);
}

/**
 * Calcule (ou récupère du cache) les 3 segments du trajet d'un·e participant·e :
 *   1. chez lui·elle → départ du sondage
 *   2. départ → arrivée
 *   3. arrivée → chez lui·elle
 *
 * Renvoie ['legs' => [...], 'total_m' => int, 'total_s' => int, 'complete' => bool]
 * complete=false si une coord manque ou un appel route_between a échoué.
 */
function participant_trip(array $poll, array $participant): array {
    $p_lat = $participant['latitude']  ?? null;
    $p_lng = $participant['longitude'] ?? null;
    $s_lat = $poll['start_lat'] ?? null;
    $s_lng = $poll['start_lng'] ?? null;
    $e_lat = $poll['end_lat']   ?? null;
    $e_lng = $poll['end_lng']   ?? null;
    if ($p_lat === null || $p_lng === null
        || $s_lat === null || $s_lng === null
        || $e_lat === null || $e_lng === null) {
        return ['legs' => [], 'total_m' => 0, 'total_s' => 0, 'complete' => false];
    }

    $segments = [
        ['home_to_start', (float)$p_lat, (float)$p_lng, (float)$s_lat, (float)$s_lng],
        ['start_to_end',  (float)$s_lat, (float)$s_lng, (float)$e_lat, (float)$e_lng],
        ['end_to_home',   (float)$e_lat, (float)$e_lng, (float)$p_lat, (float)$p_lng],
    ];

    $legs = [];
    $total_m = 0;
    $total_s = 0;
    $complete = true;
    foreach ($segments as [$name, $fa, $fb, $ta, $tb]) {
        $r = route_between($fa, $fb, $ta, $tb);
        if ($r === null) {
            $complete = false;
            continue;
        }
        $legs[] = [
            'name'       => $name,
            'distance_m' => $r['distance_m'],
            'duration_s' => $r['duration_s'],
            'geometry'   => $r['geometry'],
            'backend'    => $r['backend'],
        ];
        $total_m += $r['distance_m'];
        $total_s += $r['duration_s'];
    }
    return ['legs' => $legs, 'total_m' => $total_m, 'total_s' => $total_s, 'complete' => $complete];
}

/* ---------- Backends ---------- */

/**
 * OSRM : /route/v1/driving/{lng,lat};{lng,lat}?overview=full&geometries=geojson
 * Public demo : router.project-osrm.org (pas de SLA, rate-limit non documenté).
 * Configurable via CONFIG.routing.osrm_url.
 */
function route_osrm(float $f_lat, float $f_lng, float $t_lat, float $t_lng): ?array {
    $base = rtrim((string)($GLOBALS['CONFIG']['routing']['osrm_url'] ?? 'https://router.project-osrm.org'), '/');
    $coords = sprintf('%.6f,%.6f;%.6f,%.6f', $f_lng, $f_lat, $t_lng, $t_lat);
    $url = $base . '/route/v1/driving/' . $coords . '?' . http_build_query([
        'overview'   => 'full',
        'geometries' => 'geojson',
        'alternatives' => 'false',
        'steps'      => 'false',
    ]);
    [$body, $err] = http_get($url, geocode_user_agent(), [
        'Accept: application/json',
        'Accept-Language: fr,en',
    ]);
    if ($body === null) {
        routing_log("osrm: $err [$f_lat,$f_lng → $t_lat,$t_lng]");
        return null;
    }
    $json = json_decode($body, true);
    if (!is_array($json) || ($json['code'] ?? '') !== 'Ok' || empty($json['routes'])) {
        $code = is_array($json) ? ($json['code'] ?? '?') : 'bad JSON';
        routing_log("osrm: $code [$f_lat,$f_lng → $t_lat,$t_lng]");
        return null;
    }
    $r = $json['routes'][0];
    $coords = $r['geometry']['coordinates'] ?? [];
    if (!is_array($coords) || count($coords) < 2) {
        routing_log("osrm: empty geometry [$f_lat,$f_lng → $t_lat,$t_lng]");
        return null;
    }
    return [
        'distance_m' => (int)round((float)$r['distance']),
        'duration_s' => (int)round((float)$r['duration']),
        'geometry'   => $coords, // [[lng,lat], …]
        'backend'    => 'osrm',
    ];
}

/**
 * Fallback Haversine : ligne droite. Sous-estime fortement la distance
 * réelle, mais permet d'afficher quelque chose même quand OSRM est down.
 * Durée nulle (pas de notion de temps en vol d'oiseau).
 */
function route_haversine(float $f_lat, float $f_lng, float $t_lat, float $t_lng): ?array {
    $km = haversine_km($f_lat, $f_lng, $t_lat, $t_lng);
    return [
        'distance_m' => (int)round($km * 1000),
        'duration_s' => 0,
        'geometry'   => [[$f_lng, $f_lat], [$t_lng, $t_lat]],
        'backend'    => 'haversine',
    ];
}

function routing_log(string $msg): void {
    $path = $GLOBALS['CONFIG']['db_path'] ?? '';
    if ($path === '') return;
    $dir = dirname($path);
    if (!is_dir($dir)) return;
    $line = date('c') . ' | ' . str_replace(["\r", "\n"], ' ', $msg) . "\n";
    @file_put_contents($dir . '/routing.log', $line, FILE_APPEND | LOCK_EX);
}

/**
 * Formatte une distance en mètres pour l'UI : "1.2 km" ou "850 m".
 */
function fmt_distance(int $meters): string {
    if ($meters >= 1000) return number_format($meters / 1000, 1, ',', ' ') . ' km';
    return $meters . ' m';
}

/**
 * Formatte une durée en secondes pour l'UI : "1h12" ou "23 min" ou "—".
 */
function fmt_duration(int $seconds): string {
    if ($seconds <= 0) return '—';
    $min = (int)round($seconds / 60);
    if ($min < 60) return $min . ' min';
    $h = intdiv($min, 60);
    $m = $min % 60;
    return $h . 'h' . str_pad((string)$m, 2, '0', STR_PAD_LEFT);
}
