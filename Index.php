<?php
/**
 * Royal College of Physicians - Indoor Environment Monitor
 * Single-file Airthings for Business dashboard.
 *
 * Deploy:  copy this one file anywhere PHP is served (QNAP Web Station, IIS, Apache, nginx+php-fpm).
 *          Open it in a browser. Nothing else is required - no build step, no database,
 *          no external CSS or JS.
 *
 * Why PHP and not a static HTML page:
 *          the Airthings token endpoint and API do not send CORS headers, and a client
 *          secret must never be exposed to a browser. This file therefore acts as its own
 *          small server-side proxy: the browser only ever talks to this file.
 *
 * Endpoints served by this file:
 *          ?api=meta            locations, devices, floor map
 *          ?api=latest          latest sample for every device, grouped by floor and room
 *          ?api=history         sample history for one device  (&serial=XXXXXXXXXX&hours=48)
 *          ?api=diag            connectivity, token and rate-limit check
 *          (no api parameter)   the dashboard itself
 */

declare(strict_types=1);

/* ---------------------------------------------------------------------------
 * 1. CONFIGURATION
 * ------------------------------------------------------------------------ */

/** Airthings for Business API client (Client Credentials flow, scope read:device). */
const AT_CLIENT_ID     = 'd5b3d738-f09c-4f89-8939-ab595b334cf0';
const AT_CLIENT_SECRET = '9f705a28-a3a9-4c64-b6a2-eec50f22a3db';

/** Optional: restrict to one Airthings location id. Leave empty for every location. */
const AT_LOCATION_ID = '';

/** Branding and copy. */
const SITE_NAME  = 'The Spine';
const SITE_SUB   = 'Paddington Village, Liverpool';
const ORG_NAME   = 'Royal College of Physicians';

/** Optional page protection. Set both to require a browser login. */
const ACCESS_USER = '';
const ACCESS_PASS = '';

/** Cache. Values are seconds. Sensors report every 5 minutes, so 120s is ample. */
const CACHE_DIR   = __DIR__ . '/.airthings-cache';
const TTL_LATEST  = 120;
const TTL_META    = 900;
const TTL_HISTORY = 900;

/** History guard rails. The API returns 1000 samples per page. */
const MAX_HISTORY_SAMPLES = 6000;
const MAX_HISTORY_PAGES   = 8;

/** Browser refresh interval in seconds. */
const REFRESH_SECONDS = 120;

/** Start in display mode as soon as the page loads. Open with ?display=0 for the dashboard. */
const DISPLAY_ON_LOAD = true;

/** Display mode: seconds each space stays on screen, and whether to show floor summary slides. */
const DISPLAY_DWELL   = 14;
const DISPLAY_FLOOR_SLIDES = true;

/** Reload the display page every this many hours, to keep a long-running screen healthy. */
const DISPLAY_RELOAD_HOURS = 6;

/**
 * Royal College of Physicians web fonts, as used on rcp.ac.uk. They are served from
 * rcp.ac.uk without CORS headers, so this file proxies them on the same origin and
 * caches a copy. To run offline, drop the .woff2 files beside this script and change
 * these to relative paths, for example 'fonts/museo-slab.woff2'.
 */
const FONT_SOURCES = [
    'slab'      => 'https://www.rcp.ac.uk/dist/78e483ebe44c44594558.woff2',
    'slab-bold' => 'https://www.rcp.ac.uk/dist/9e018664d42fadf58c15.woff2',
    'sans'      => 'https://www.rcp.ac.uk/dist/e945eec15a91cb4bab25.woff2',
    'sans-bold' => 'https://www.rcp.ac.uk/dist/059c3632b509c769bf2c.woff2',
];

/** Draw a 24-hour trend line on each room card. Set false on very large estates. */
const CARD_SPARKLINES = true;

/**
 * Floor mapping. Airthings has no floor field, so the floor is read from the room name.
 * Add exact overrides here for rooms that do not follow a naming convention:
 *   'Boardroom' => ['Level 12', 12],
 */
const FLOOR_OVERRIDES = [];

/** Where floor assignments made in the browser are stored. */
const FLOOR_MAP_FILE = __DIR__ . '/.airthings-floors.json';

/** Sensor keys the dashboard understands. Anything else in a sample is ignored. */
const METRIC_KEYS = ['co2', 'temp', 'humidity', 'voc', 'pm1', 'pm25', 'pm10',
    'radonShortTermAvg', 'hourlyRadon', 'virusRisk', 'mold', 'occupants',
    'airExchangeRate', 'airflow', 'ventilationAmount', 'controlSignal', 'sla',
    'pressure', 'lux', 'light', 'battery', 'rssi'];

/** Keys that make a device an environment sensor rather than a gateway. */
const ENV_KEYS = ['co2', 'temp', 'humidity', 'voc', 'pm1', 'pm25', 'pm10',
    'radonShortTermAvg', 'hourlyRadon', 'virusRisk', 'mold', 'occupants',
    'airExchangeRate', 'airflow', 'sla', 'pressure', 'lux', 'light'];

/** Airthings API hosts. */
const AT_TOKEN_URL = 'https://accounts-api.airthings.com/v1/token';
const AT_API_BASE  = 'https://ext-api.airthings.com';

/* ---------------------------------------------------------------------------
 * 2. SMALL HELPERS
 * ------------------------------------------------------------------------ */

function guard_access(): void
{
    if (ACCESS_USER === '' || ACCESS_PASS === '') {
        return;
    }
    $u = $_SERVER['PHP_AUTH_USER'] ?? '';
    $p = $_SERVER['PHP_AUTH_PW'] ?? '';
    if (!hash_equals(ACCESS_USER, (string)$u) || !hash_equals(ACCESS_PASS, (string)$p)) {
        header('WWW-Authenticate: Basic realm="Indoor Environment Monitor"');
        http_response_code(401);
        echo 'Authentication required.';
        exit;
    }
}

function cache_dir(): string
{
    if (!is_dir(CACHE_DIR)) {
        @mkdir(CACHE_DIR, 0770, true);
        @file_put_contents(CACHE_DIR . '/.htaccess', "Require all denied\nDeny from all\n");
        @file_put_contents(CACHE_DIR . '/index.html', '');
    }
    return CACHE_DIR;
}

function cache_read(string $key, int $ttl): ?array
{
    $f = cache_dir() . '/' . $key . '.json';
    if (!is_file($f)) {
        return null;
    }
    if ($ttl > 0 && (time() - (int)filemtime($f)) > $ttl) {
        return null;
    }
    $raw = @file_get_contents($f);
    if ($raw === false) {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function cache_write(string $key, array $data): void
{
    $f = cache_dir() . '/' . $key . '.json';
    @file_put_contents($f, json_encode($data), LOCK_EX);
}

function json_out(array $payload, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/**
 * One HTTP request. Uses cURL when available, streams otherwise.
 * Returns ['status' => int, 'body' => string, 'headers' => array].
 */
function http_request(string $method, string $url, ?array $json = null, array $headers = []): array
{
    $body = $json === null ? null : json_encode($json);
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    $headers[] = 'Accept: application/json';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw    = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hsize  = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $err    = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            return ['status' => 0, 'body' => $err, 'headers' => []];
        }
        $rawStr  = (string)$raw;
        $headTxt = substr($rawStr, 0, $hsize);
        $bodyTxt = substr($rawStr, $hsize);
        return ['status' => $status, 'body' => $bodyTxt, 'headers' => parse_headers($headTxt)];
    }

    $ctx = stream_context_create([
        'http' => [
            'method'        => $method,
            'header'        => implode("\r\n", $headers),
            'content'       => $body ?? '',
            'timeout'       => 30,
            'ignore_errors' => true,
        ],
    ]);
    $bodyTxt = @file_get_contents($url, false, $ctx);
    $status  = 0;
    $hdrs    = [];
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            $status = (int)$m[1];
            continue;
        }
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) {
            $hdrs[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
    }
    return ['status' => $status, 'body' => $bodyTxt === false ? '' : $bodyTxt, 'headers' => $hdrs];
}

function parse_headers(string $text): array
{
    $out = [];
    foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) {
            $out[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
    }
    return $out;
}

/* ---------------------------------------------------------------------------
 * 3. AIRTHINGS CLIENT
 * ------------------------------------------------------------------------ */

class AirthingsError extends RuntimeException
{
}

function at_token(bool $force = false): string
{
    static $memo = null;
    if (!$force && $memo !== null) {
        return $memo;
    }
    if (!$force) {
        $cached = cache_read('token', 0);
        if ($cached && (int)($cached['expires_at'] ?? 0) > time() + 60) {
            $memo = (string)$cached['access_token'];
            return $memo;
        }
    }
    if (AT_CLIENT_ID === '' || AT_CLIENT_SECRET === '') {
        throw new AirthingsError('No API client configured. Add the client id and secret at the top of this file.');
    }

    $res = http_request('POST', AT_TOKEN_URL, [
        'grant_type'    => 'client_credentials',
        'client_id'     => AT_CLIENT_ID,
        'client_secret' => AT_CLIENT_SECRET,
        'scope'         => ['read:device'],
    ]);

    if ($res['status'] === 0) {
        throw new AirthingsError('Cannot reach accounts-api.airthings.com: ' . $res['body']);
    }
    $data = json_decode($res['body'], true);
    if ($res['status'] !== 200 || !is_array($data) || empty($data['access_token'])) {
        $msg = is_array($data) ? ($data['error_description'] ?? $data['error'] ?? $res['body']) : $res['body'];
        throw new AirthingsError('Token request failed (HTTP ' . $res['status'] . '): ' . substr((string)$msg, 0, 300));
    }

    $memo = (string)$data['access_token'];
    cache_write('token', [
        'access_token' => $memo,
        'expires_at'   => time() + (int)($data['expires_in'] ?? 3600),
    ]);
    return $memo;
}

/** Last seen rate-limit headers, surfaced to the dashboard footer. */
$GLOBALS['at_rate'] = [];

function at_get(string $path, array $query = [], bool $retry = true): array
{
    $url = AT_API_BASE . $path . ($query ? '?' . http_build_query($query) : '');
    $res = http_request('GET', $url, null, ['Authorization: Bearer ' . at_token()]);

    foreach (['x-ratelimit-limit', 'x-ratelimit-remaining', 'x-ratelimit-reset', 'x-ratelimit-retry-after'] as $h) {
        if (isset($res['headers'][$h])) {
            $GLOBALS['at_rate'][str_replace('x-ratelimit-', '', $h)] = $res['headers'][$h];
        }
    }

    if ($res['status'] === 401 && $retry) {
        at_token(true);
        return at_get($path, $query, false);
    }
    if ($res['status'] === 429) {
        throw new AirthingsError('Airthings rate limit reached. Retry in '
            . ($res['headers']['x-ratelimit-retry-after'] ?? 'a few') . ' seconds.');
    }
    if ($res['status'] === 0) {
        throw new AirthingsError('Cannot reach ext-api.airthings.com: ' . $res['body']);
    }
    if ($res['status'] < 200 || $res['status'] >= 300) {
        $data = json_decode($res['body'], true);
        $msg  = is_array($data) ? ($data['error_description'] ?? $data['error'] ?? $res['body']) : $res['body'];
        throw new AirthingsError($path . ' returned HTTP ' . $res['status'] . ': ' . substr((string)$msg, 0, 300));
    }

    $data = json_decode($res['body'], true);
    return is_array($data) ? $data : [];
}

/* ---------------------------------------------------------------------------
 * 4. FLOOR AND ROOM DERIVATION
 * ------------------------------------------------------------------------ */

/** @return array{0:string,1:int} floor label and sort rank */
function derive_floor(string $room): array
{
    $name = trim($room);
    foreach (FLOOR_OVERRIDES as $needle => $floor) {
        if ($needle !== '' && stripos($name, (string)$needle) !== false) {
            return [(string)$floor[0], (int)$floor[1]];
        }
    }
    if (preg_match('/\b(?:sub[\s-]?basement|b2)\b/i', $name)) {
        return ['Sub-basement', -3];
    }
    if (preg_match('/\b(?:basement|b1)\b/i', $name)) {
        return ['Basement', -2];
    }
    if (preg_match('/\b(?:lower[\s-]?ground|lg|lgf)\b/i', $name)) {
        return ['Lower ground', -1];
    }
    if (preg_match('/\b(?:ground(?:[\s-]?floor)?|gf|g\/f)\b/i', $name)) {
        return ['Ground floor', 0];
    }
    if (preg_match('/\b(?:roof|plant\s*room)\b/i', $name)) {
        return ['Roof and plant', 90];
    }
    if (preg_match('/\b(\d{1,2})(?:st|nd|rd|th)\s*floor\b/i', $name, $m)) {
        return ['Level ' . (int)$m[1], (int)$m[1]];
    }
    if (preg_match('/\b(?:level|floor|lvl|flr|l|f)\s*[-_. ]?(\d{1,2})\b/i', $name, $m)) {
        return ['Level ' . (int)$m[1], (int)$m[1]];
    }
    if (preg_match('/\bmezzanine\b/i', $name)) {
        return ['Mezzanine', 1];
    }
    if (preg_match('/\b(\d{1,2})[.\-_](\d{1,3})\b/', $name, $m)) {
        return ['Level ' . (int)$m[1], (int)$m[1]];
    }
    if (preg_match('/\b(\d)(\d{2})\b/', $name, $m)) {
        return ['Level ' . (int)$m[1], (int)$m[1]];
    }
    return ['Unassigned', 95];
}

function floor_map(): array
{
    if (!is_file(FLOOR_MAP_FILE)) {
        return [];
    }
    $map = json_decode((string)file_get_contents(FLOOR_MAP_FILE), true);
    return is_array($map) ? $map : [];
}

function floor_map_save(string $serial, string $label): array
{
    $map = floor_map();
    if ($label === '') {
        unset($map[$serial]);
    } else {
        $map[$serial] = $label;
    }
    @file_put_contents(FLOOR_MAP_FILE, json_encode($map, JSON_PRETTY_PRINT), LOCK_EX);
    @unlink(cache_dir() . '/latest.json');
    return $map;
}

/* ---------------------------------------------------------------------------
 * 5. DATA ASSEMBLY
 * ------------------------------------------------------------------------ */

function fetch_meta(bool $fresh = false): array
{
    $cached = $fresh ? null : cache_read('meta', TTL_META);
    if ($cached !== null) {
        return $cached;
    }

    $warnings = [];
    $accountId = '';
    try {
        $accounts = at_get('/v1/accounts');
        $first = $accounts['accounts'][0] ?? null;
        if (is_array($first)) {
            $accountId = (string)($first['id'] ?? '');
        }
    } catch (AirthingsError $e) {
        $warnings[] = 'Accounts lookup failed: ' . $e->getMessage();
    }

    $locations = [];
    try {
        $res = at_get('/v1/locations');
        foreach (($res['locations'] ?? []) as $loc) {
            if (AT_LOCATION_ID !== '' && (string)($loc['id'] ?? '') !== AT_LOCATION_ID) {
                continue;
            }
            $locations[] = ['id' => (string)($loc['id'] ?? ''), 'name' => (string)($loc['name'] ?? 'Unnamed location')];
        }
    } catch (AirthingsError $e) {
        throw new AirthingsError('Could not list locations. ' . $e->getMessage());
    }

    // Device metadata is optional. It only adds product names and sensor lists.
    $devices = [];
    try {
        $res = at_get('/v1/devices');
        foreach (($res['devices'] ?? []) as $d) {
            $serial = (string)($d['id'] ?? '');
            if ($serial === '') {
                continue;
            }
            $devices[$serial] = [
                'product'  => (string)($d['productName'] ?? ($d['deviceType'] ?? 'Airthings device')),
                'type'     => (string)($d['deviceType'] ?? ''),
                'sensors'  => array_values(array_map('strval', $d['sensors'] ?? [])),
                'room'     => (string)($d['segment']['name'] ?? ''),
                'location' => (string)($d['location']['name'] ?? ''),
            ];
        }
    } catch (AirthingsError $e) {
        $warnings[] = 'Device list unavailable (read:device may not cover /v1/devices): ' . $e->getMessage();
    }

    $meta = [
        'accountId' => $accountId,
        'locations' => $locations,
        'devices'   => $devices,
        'warnings'  => $warnings,
        'fetched'   => time(),
    ];
    cache_write('meta', $meta);
    return $meta;
}

function fetch_latest(bool $fresh = false): array
{
    $cached = $fresh ? null : cache_read('latest', TTL_LATEST);
    if ($cached !== null) {
        $cached['cached'] = true;
        return $cached;
    }

    $meta     = fetch_meta();
    $warnings = $meta['warnings'];
    $floorMap = floor_map();
    $rooms    = [];

    foreach ($meta['locations'] as $loc) {
        if ($loc['id'] === '') {
            continue;
        }
        try {
            $res = at_get('/v1/locations/' . rawurlencode($loc['id']) . '/latest-samples');
        } catch (AirthingsError $e) {
            $warnings[] = $loc['name'] . ': ' . $e->getMessage();
            continue;
        }
        foreach (($res['devices'] ?? []) as $dev) {
            $serial = (string)($dev['id'] ?? '');
            $raw    = is_array($dev['data'] ?? null) ? $dev['data'] : [];

            // Keep only recognised sensor keys, so a gateway with no sensors is obvious.
            $data = [];
            foreach (METRIC_KEYS as $key) {
                if (array_key_exists($key, $raw) && $raw[$key] !== null) {
                    $data[$key] = $raw[$key];
                }
            }
            if (isset($raw['time'])) {
                $data['time'] = (int)$raw['time'];
            }
            $isSensor = (bool)array_intersect(array_keys($data), ENV_KEYS);

            $roomName = (string)($dev['segment']['name'] ?? ($meta['devices'][$serial]['room'] ?? $serial));
            $roomName = trim($roomName) !== '' ? trim($roomName) : $serial;
            $override = $floorMap[$serial] ?? '';
            if ($override !== '') {
                $floor = (string)$override;
                $rank  = derive_floor($floor)[1];
            } else {
                [$floor, $rank] = derive_floor($roomName);
            }
            $rooms[] = [
                'serial'    => $serial,
                'room'      => $roomName,
                'floor'     => $floor,
                'floorRank' => $rank,
                'location'  => (string)($res['name'] ?? $loc['name']),
                'locationId' => $loc['id'],
                'product'   => (string)($meta['devices'][$serial]['product'] ?? 'Airthings device'),
                'segmentId' => (string)($dev['segment']['id'] ?? ''),
                'role'      => $isSensor ? 'sensor' : 'hub',
                'assigned'  => $override !== '',
                'active'    => (bool)($dev['segment']['active'] ?? true),
                'time'      => isset($data['time']) ? (int)$data['time'] : null,
                'data'      => $data,
            ];
        }
    }

    usort($rooms, static function (array $a, array $b): int {
        return [$a['floorRank'], $a['room']] <=> [$b['floorRank'], $b['room']];
    });

    $payload = [
        'ok'        => true,
        'org'       => ORG_NAME,
        'site'      => SITE_NAME,
        'siteSub'   => SITE_SUB,
        'locations' => $meta['locations'],
        'rooms'     => $rooms,
        'floorMap'  => $floorMap,
        'warnings'  => $warnings,
        'generated' => time(),
        'ttl'       => TTL_LATEST,
        'refresh'   => REFRESH_SECONDS,
        'sparklines' => CARD_SPARKLINES,
        'dwell'     => DISPLAY_DWELL,
        'floorSlides' => DISPLAY_FLOOR_SLIDES,
        'displayOnLoad' => DISPLAY_ON_LOAD,
        'reloadHours' => DISPLAY_RELOAD_HOURS,
        'rateLimit' => $GLOBALS['at_rate'],
        'cached'    => false,
    ];
    cache_write('latest', $payload);
    return $payload;
}

function fetch_history(string $serial, int $hours): array
{
    $serial = preg_replace('/[^A-Za-z0-9]/', '', $serial) ?? '';
    if ($serial === '') {
        throw new AirthingsError('A device serial number is required.');
    }
    $hours  = max(1, min(2160, $hours));
    $key    = 'hist_' . $serial . '_' . $hours;
    $cached = cache_read($key, TTL_HISTORY);
    if ($cached !== null) {
        $cached['cached'] = true;
        return $cached;
    }

    $to    = time();
    $from  = $to - ($hours * 3600);
    $path  = '/v1/devices/' . rawurlencode($serial) . '/samples';

    // The samples endpoint pages at 1000 samples. Probe the first page, work out the
    // reporting interval, and pull the most recent window that fits inside the cap.
    $first     = at_get($path, ['start' => $from, 'end' => $to]);
    $cadence   = sample_cadence($first);
    $truncated = false;
    $expected  = $cadence > 0 ? (int)(($to - $from) / $cadence) : 0;
    if ($expected > MAX_HISTORY_SAMPLES) {
        $from      = $to - (int)(MAX_HISTORY_SAMPLES * $cadence);
        $truncated = true;
        $first     = at_get($path, ['start' => $from, 'end' => $to]);
    }

    $data   = is_array($first['data'] ?? null) ? $first['data'] : [];
    $cursor = $first['cursor'] ?? null;
    $pages  = 1;
    while (!empty($cursor) && $pages < MAX_HISTORY_PAGES && count($data['time'] ?? []) < MAX_HISTORY_SAMPLES) {
        $next   = at_get($path, ['start' => $from, 'end' => $to, 'cursor' => $cursor]);
        $data   = merge_samples($data, is_array($next['data'] ?? null) ? $next['data'] : []);
        $cursor = $next['cursor'] ?? null;
        $pages++;
    }
    if (!empty($cursor)) {
        $truncated = true;
    }

    // Drop any series that does not line up with the timestamp series.
    $count = count($data['time'] ?? []);
    foreach ($data as $k => $series) {
        if ($k !== 'time' && (!is_array($series) || count($series) !== $count)) {
            unset($data[$k]);
        }
    }

    $payload = [
        'ok'        => true,
        'serial'    => $serial,
        'hours'     => $hours,
        'samples'   => $count,
        'pages'     => $pages,
        'cadence'   => $cadence,
        'truncated' => $truncated,
        'start'     => $first['start'] ?? null,
        'end'       => $first['end'] ?? null,
        'units'     => $first['measurementSystem'] ?? null,
        'data'      => $data,
        'cached'    => false,
    ];
    cache_write($key, $payload);
    return $payload;
}

/** Average interval between samples in a page, in seconds. Falls back to 150 s. */
function sample_cadence(array $page): int
{
    $times = $page['data']['time'] ?? [];
    if (!is_array($times) || count($times) < 2) {
        return 150;
    }
    $span  = (int)end($times) - (int)reset($times);
    $steps = count($times) - 1;
    if ($span <= 0 || $steps <= 0) {
        return 150;
    }
    return max(30, (int)round($span / $steps));
}

/** Append one page of column-oriented samples onto the running set. */
function merge_samples(array $into, array $page): array
{
    foreach ($page as $k => $series) {
        if (!is_array($series)) {
            continue;
        }
        $into[$k] = array_merge($into[$k] ?? [], $series);
    }
    return $into;
}

function serve_font(string $key): void
{
    $source = FONT_SOURCES[$key] ?? '';
    if ($source === '') {
        http_response_code(404);
        exit;
    }

    // A relative path means the file sits next to this script.
    if (!preg_match('#^https?://#i', $source)) {
        $local = __DIR__ . '/' . ltrim($source, '/');
        if (!is_file($local)) {
            http_response_code(404);
            exit;
        }
        header('Content-Type: font/woff2');
        header('Cache-Control: public, max-age=31536000, immutable');
        readfile($local);
        exit;
    }

    $cacheFile = cache_dir() . '/font-' . preg_replace('/[^a-z-]/', '', $key) . '.woff2';
    if (!is_file($cacheFile) || filesize($cacheFile) < 1024) {
        $res = http_request('GET', $source);
        if ($res['status'] !== 200 || strlen($res['body']) < 1024) {
            http_response_code(404);
            exit;
        }
        @file_put_contents($cacheFile, $res['body'], LOCK_EX);
    }
    header('Content-Type: font/woff2');
    header('Cache-Control: public, max-age=31536000, immutable');
    readfile($cacheFile);
    exit;
}

/* ---------------------------------------------------------------------------
 * 6. ROUTER
 * ------------------------------------------------------------------------ */

guard_access();

// Web fonts, proxied on this origin and cached on disk after the first request.
if (isset($_GET['font'])) {
    serve_font((string)$_GET['font']);
}

$api = isset($_GET['api']) ? (string)$_GET['api'] : '';
if ($api !== '') {
    $fresh = isset($_GET['fresh']) && $_GET['fresh'] === '1';
    try {
        switch ($api) {
            case 'meta':
                json_out(['ok' => true] + fetch_meta($fresh));
            case 'latest':
                json_out(fetch_latest($fresh));
            case 'history':
                json_out(fetch_history((string)($_GET['serial'] ?? ''), (int)($_GET['hours'] ?? 48)));
            case 'setfloor':
                $in = json_decode((string)file_get_contents('php://input'), true);
                $pairs = [];
                if (isset($in['map']) && is_array($in['map'])) {
                    $pairs = $in['map'];
                } elseif (isset($in['serial'])) {
                    $pairs = [(string)$in['serial'] => (string)($in['floor'] ?? '')];
                }
                if (!$pairs) {
                    json_out(['ok' => false, 'error' => 'A device serial number is required.'], 400);
                }
                $map = floor_map();
                foreach ($pairs as $serial => $label) {
                    $serial = preg_replace('/[^A-Za-z0-9]/', '', (string)$serial) ?? '';
                    if ($serial === '') {
                        continue;
                    }
                    $map = floor_map_save($serial, trim(substr((string)$label, 0, 40)));
                }
                json_out(['ok' => true, 'map' => $map]);
            case 'diag':
                $t = at_token(true);
                $acc = at_get('/v1/accounts');
                json_out([
                    'ok'        => true,
                    'token'     => 'issued, ' . strlen($t) . ' characters',
                    'accounts'  => $acc['accounts'] ?? [],
                    'rateLimit' => $GLOBALS['at_rate'],
                    'php'       => PHP_VERSION,
                    'curl'      => function_exists('curl_init'),
                    'cacheWritable' => is_writable(cache_dir()),
                ]);
            default:
                json_out(['ok' => false, 'error' => 'Unknown endpoint.'], 404);
        }
    } catch (AirthingsError $e) {
        json_out(['ok' => false, 'error' => $e->getMessage()], 502);
    } catch (Throwable $e) {
        json_out(['ok' => false, 'error' => 'Server error: ' . $e->getMessage()], 500);
    }
}

$bootTitle = ORG_NAME . ' - Indoor Environment Monitor';
?>
<!DOCTYPE html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= htmlspecialchars($bootTitle, ENT_QUOTES) ?></title>
<link rel="icon" href="data:image/png;base64,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">
<style>
/* Royal College of Physicians brand fonts, proxied by this file on the same origin. */
@font-face{font-family:"RCP Slab";src:url("?font=slab") format("woff2");font-weight:400;font-display:swap}
@font-face{font-family:"RCP Slab";src:url("?font=slab-bold") format("woff2");font-weight:700;font-display:swap}
@font-face{font-family:"RCP Sans";src:url("?font=sans") format("woff2");font-weight:300;font-display:swap}
@font-face{font-family:"RCP Sans";src:url("?font=sans-bold") format("woff2");font-weight:700;font-display:swap}

:root{
  /* rcp.ac.uk palette */
  --navy:#1b2840;
  --navy-mid:#485366;
  --green:#59d8a1;
  --green-deep:#0f7a52;
  --teal:#34898c;
  --teal-deep:#34535c;
  --red:#a22027;
  --orange:#ff9862;
  --yellow:#ffcc53;
  --amber-deep:#8a6a00;

  --ink:var(--navy);
  --muted:#66708a;
  --hairline:#dfe3ea;
  --paper:#f4f6f7;
  --card:#ffffff;
  --good:var(--green-deep);
  --fair:var(--amber-deep);
  --poor:var(--red);
  --dormant:#8c94a6;
  --good-wash:#e6f7ef;
  --fair-wash:#fdf3da;
  --poor-wash:#f7e6e7;
  --shadow:0 1px 2px rgba(27,40,64,.06),0 8px 24px rgba(27,40,64,.06);
  --serif:"RCP Slab","Museo Slab",Rockwell,"Roboto Slab",Georgia,serif;
  --sans:"RCP Sans","FS Albert","Segoe UI",Arial,Helvetica,sans-serif;
  --mono:ui-monospace,"SF Mono",SFMono-Regular,Menlo,Consolas,"Liberation Mono",monospace;
}
*{box-sizing:border-box}
html{-webkit-text-size-adjust:100%}
body{
  margin:0;background:var(--paper);color:var(--ink);
  font-family:var(--sans);font-size:15px;font-weight:300;line-height:1.5;
  -webkit-font-smoothing:antialiased;
}
h1,h2,h3{margin:0;font-family:var(--serif);font-weight:700;letter-spacing:-.005em}
button,select,input{font:inherit;color:inherit}
a{color:var(--teal-deep)}
.num{font-family:var(--mono);font-variant-numeric:tabular-nums}
.sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
:focus-visible{outline:2px solid var(--navy);outline-offset:2px}

/* ---------- masthead ---------- */
.masthead{
  background:var(--card);border-bottom:1px solid var(--hairline);
  position:sticky;top:0;z-index:40;
}
.masthead-in{
  max-width:1560px;margin:0 auto;padding:14px 24px;
  display:flex;align-items:center;gap:24px;flex-wrap:wrap;
}
.brand{display:flex;align-items:center;gap:18px;min-width:0}
.brand img{height:42px;width:auto;display:block}
.brand-rule{width:1px;height:42px;background:var(--hairline)}
.brand-txt h1{font-size:20px;line-height:1.15}
.brand-txt p{margin:2px 0 0;font-size:12.5px;color:var(--muted);letter-spacing:.06em;text-transform:uppercase}
.mast-right{margin-left:auto;display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.clock{text-align:right;line-height:1.25}
.clock strong{display:block;font-family:var(--mono);font-size:15px;letter-spacing:-.01em}
.clock span{font-size:12px;color:var(--muted)}
.pill{
  display:inline-flex;align-items:center;gap:7px;padding:7px 13px;
  border:1px solid var(--hairline);border-radius:999px;background:var(--card);
  font-size:13.5px;cursor:pointer;transition:background .15s,border-color .15s;
}
.pill:hover{background:var(--paper)}
.pill[aria-pressed="true"],.pill.is-on{background:var(--navy);border-color:var(--navy);color:#fff}
.dot{width:8px;height:8px;border-radius:50%;background:var(--good);flex:none}
.dot.stale{background:var(--fair)}
.dot.down{background:var(--poor)}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.35}}
.live .dot{animation:pulse 2.6s ease-in-out infinite}
@media(prefers-reduced-motion:reduce){.live .dot{animation:none}}

/* ---------- control bar ---------- */
.controls{background:var(--card);border-bottom:1px solid var(--hairline);position:sticky;top:76px;z-index:35}
.controls-in{max-width:1560px;margin:0 auto;padding:12px 24px;display:flex;gap:18px;align-items:center;flex-wrap:wrap}
.metric-scroll{display:flex;gap:8px;overflow-x:auto;padding-bottom:2px;scrollbar-width:thin}
.chip{
  border:1px solid var(--hairline);background:var(--card);border-radius:6px;
  padding:6px 12px;font-size:13px;cursor:pointer;white-space:nowrap;
}
.chip[aria-pressed="true"]{background:var(--navy);border-color:var(--navy);color:#fff}
.chip:disabled{opacity:.4;cursor:not-allowed}
.field{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--muted)}
.field input[type=search],.field select{
  border:1px solid var(--hairline);border-radius:6px;padding:7px 10px;background:var(--card);
  font-size:13.5px;color:var(--ink);min-width:150px;
}
.toggle{display:inline-flex;align-items:center;gap:7px;font-size:13px;color:var(--ink);cursor:pointer}

/* ---------- summary ---------- */
main{max-width:1560px;margin:0 auto;padding:26px 24px 90px}
.summary{
  display:grid;grid-template-columns:repeat(auto-fit,minmax(168px,1fr));gap:1px;
  background:var(--hairline);border:1px solid var(--hairline);border-radius:10px;overflow:hidden;margin-bottom:30px;
}
.summary div{background:var(--card);padding:16px 18px}
.summary dt{font-size:11.5px;letter-spacing:.09em;text-transform:uppercase;color:var(--muted);margin:0 0 6px}
.summary dd{margin:0;font-family:var(--mono);font-size:26px;letter-spacing:-.02em}
.summary dd small{font-family:var(--sans);font-size:12.5px;color:var(--muted);letter-spacing:0}

/* ---------- floor bands (signature) ---------- */
.floor{display:grid;grid-template-columns:104px 1fr;gap:0;margin:0 0 8px}
.plate{
  position:relative;padding:6px 20px 34px 0;text-align:right;
}
.plate::after{
  content:"";position:absolute;top:14px;right:0;bottom:0;width:1px;background:var(--hairline);
}
.plate .fl-num{
  display:block;font-family:var(--serif);font-size:44px;line-height:.9;color:var(--navy);letter-spacing:-.03em;
}
.plate .fl-lab{display:block;margin-top:6px;font-size:11.5px;letter-spacing:.09em;text-transform:uppercase;color:var(--muted)}
.plate .fl-cnt{display:block;margin-top:10px;font-family:var(--mono);font-size:12px;color:var(--muted)}
.plate.sticky-plate{position:sticky;top:140px;align-self:start}
.rooms{padding:0 0 34px 24px;display:grid;grid-template-columns:repeat(auto-fill,minmax(298px,1fr));gap:14px}

/* ---------- room card ---------- */
.room{
  background:var(--card);border:1px solid var(--hairline);border-radius:10px;
  box-shadow:var(--shadow);overflow:hidden;text-align:left;cursor:pointer;
  display:flex;flex-direction:column;padding:0;transition:transform .14s ease,box-shadow .14s ease;
}
.room:hover{transform:translateY(-1px);box-shadow:0 2px 4px rgba(27,40,64,.08),0 14px 30px rgba(27,40,64,.08)}
@media(prefers-reduced-motion:reduce){.room:hover{transform:none}}
.room-rail{height:4px;background:var(--dormant)}
.room-rail.good{background:var(--good)}.room-rail.fair{background:var(--fair)}.room-rail.poor{background:var(--poor)}
.room-head{padding:14px 16px 10px;display:flex;gap:12px;align-items:flex-start}
.room-head h3{font-size:16.5px;line-height:1.25}
.room-head p{margin:3px 0 0;font-size:12px;color:var(--muted)}
.room-hero{margin-left:auto;text-align:right;flex:none}
.room-hero b{display:block;font-family:var(--mono);font-size:24px;letter-spacing:-.02em;line-height:1}
.room-hero span{font-size:11px;color:var(--muted);letter-spacing:.05em;text-transform:uppercase}
.spark{display:block;width:100%;height:38px;background:var(--paper);border-top:1px solid var(--hairline);border-bottom:1px solid var(--hairline)}
.spark:not(.drawn){background:linear-gradient(var(--paper),var(--paper)) padding-box,repeating-linear-gradient(90deg,var(--hairline) 0 3px,transparent 3px 8px) center/100% 1px no-repeat}
.metrics{list-style:none;margin:0;padding:10px 16px 14px;display:grid;grid-template-columns:repeat(2,1fr);gap:7px 14px}
.metrics li{display:flex;align-items:baseline;gap:7px;font-size:13px;min-width:0}
.metrics .mk{color:var(--muted);flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.metrics .mv{font-family:var(--mono);font-size:13.5px}
.metrics .mv.good{color:var(--good)}.metrics .mv.fair{color:var(--fair)}.metrics .mv.poor{color:var(--poor)}
.room-foot{
  margin-top:auto;padding:9px 16px;border-top:1px solid var(--hairline);background:var(--paper);
  display:flex;gap:10px;align-items:center;font-size:11.5px;color:var(--muted);
}
.room-foot .num{font-size:11.5px}
.stale-flag{color:var(--fair)}
.badge{
  margin-left:auto;padding:2px 8px;border-radius:999px;font-size:11px;letter-spacing:.04em;
  text-transform:uppercase;background:var(--good-wash);color:var(--good);
}
.badge.dormant{background:#eceff3;color:var(--dormant)}
.badge.fair{background:var(--fair-wash);color:var(--fair)}
.badge.poor{background:var(--poor-wash);color:var(--poor)}


/* ---------- table view ---------- */
.tablewrap{background:var(--card);border:1px solid var(--hairline);border-radius:10px;box-shadow:var(--shadow);overflow:auto;max-height:calc(100vh - 260px)}
table{border-collapse:separate;border-spacing:0;width:100%;font-size:13px}
thead th{
  position:sticky;top:0;z-index:2;background:var(--navy);color:#fff;
  padding:11px 12px;text-align:right;font-weight:500;white-space:nowrap;font-size:12px;letter-spacing:.03em;
}
thead th:first-child,thead th:nth-child(2){text-align:left}
thead th button{background:none;border:0;color:inherit;cursor:pointer;font:inherit;padding:0}
tbody td{padding:9px 12px;border-bottom:1px solid var(--hairline);text-align:right;font-family:var(--mono);font-size:12.5px;white-space:nowrap}
tbody td:first-child,tbody td:nth-child(2){text-align:left;font-family:var(--sans);font-size:13px}
tbody tr:hover td{background:var(--paper)}
tbody td.good{color:var(--good)}tbody td.fair{color:var(--fair)}tbody td.poor{color:var(--poor)}
tbody th{position:sticky;left:0}
.floor-row td{background:var(--paper);font-family:var(--serif);font-size:14px;letter-spacing:.02em;text-align:left}

/* ---------- drawer ---------- */
.scrim{position:fixed;inset:0;background:rgba(27,40,64,.45);opacity:0;pointer-events:none;transition:opacity .2s;z-index:50}
.scrim.open{opacity:1;pointer-events:auto}
.drawer{
  position:fixed;top:0;right:0;bottom:0;width:min(620px,100%);background:var(--paper);
  border-left:1px solid var(--hairline);transform:translateX(100%);transition:transform .24s ease;
  z-index:60;display:flex;flex-direction:column;
}
.drawer.open{transform:none}
@media(prefers-reduced-motion:reduce){.drawer,.scrim{transition:none}}
.drawer-head{padding:20px 24px 16px;background:var(--card);border-bottom:1px solid var(--hairline)}
.drawer-head .eyebrow{font-size:11.5px;letter-spacing:.09em;text-transform:uppercase;color:var(--muted)}
.drawer-head h2{font-size:23px;margin-top:4px}
.drawer-head p{margin:6px 0 0;font-size:13px;color:var(--muted)}
.drawer-close{position:absolute;top:16px;right:18px;border:1px solid var(--hairline);background:var(--card);border-radius:6px;width:32px;height:32px;cursor:pointer;font-size:16px;line-height:1}
.drawer-body{overflow-y:auto;padding:20px 24px 60px;flex:1}
.range{display:flex;gap:8px;margin-bottom:18px}
.mchart{background:var(--card);border:1px solid var(--hairline);border-radius:10px;padding:14px 16px 8px;margin-bottom:12px}
.mchart header{display:flex;align-items:baseline;gap:10px;margin-bottom:8px}
.mchart h3{font-size:14.5px;font-family:var(--sans);font-weight:600}
.mchart .now{margin-left:auto;font-family:var(--mono);font-size:15px}
.mchart .rangetxt{font-size:11.5px;color:var(--muted);font-family:var(--mono)}
.mchart svg{display:block;width:100%;height:96px}
.hint{font-size:12.5px;color:var(--muted);margin:0 0 14px}
.assign{background:var(--card);border:1px solid var(--hairline);border-radius:10px;padding:14px 16px;margin-bottom:16px}
.assign label{display:block;font-size:11.5px;letter-spacing:.09em;text-transform:uppercase;color:var(--muted);margin-bottom:8px}
.assign .row{display:flex;gap:8px}
.assign input{flex:1;border:1px solid var(--hairline);border-radius:6px;padding:8px 10px;font-size:13.5px}
.assign p{margin:8px 0 0;font-size:12px;color:var(--muted)}
.room.hub{opacity:.92}
.room.hub .room-hero b{color:var(--dormant)}

/* ---------- states ---------- */
.notice{
  background:var(--card);border:1px solid var(--hairline);border-left:3px solid var(--fair);
  border-radius:8px;padding:14px 18px;margin-bottom:22px;font-size:13.5px;
}
.notice.error{border-left-color:var(--poor)}
.notice h3{font-family:var(--sans);font-size:14px;margin-bottom:5px}
.notice code{font-family:var(--mono);font-size:12.5px;background:var(--paper);padding:1px 5px;border-radius:4px}
.notice ul{margin:8px 0 0;padding-left:20px}
.skeleton{background:var(--card);border:1px solid var(--hairline);border-radius:10px;height:210px}
.empty{padding:60px 20px;text-align:center;color:var(--muted)}
.footer{max-width:1560px;margin:0 auto;padding:0 24px 40px;font-size:12px;color:var(--muted);display:flex;gap:18px;flex-wrap:wrap}
.footer .num{font-size:12px}


/* ---------- display mode ---------- */
.kiosk{
  position:fixed;inset:0;z-index:100;display:none;flex-direction:column;
  background:var(--navy);color:#fff;overflow:hidden;cursor:none;
  font-size:clamp(14px,1.05vw,22px);
}
body.on-display{overflow:hidden}
body.on-display .kiosk{display:flex}
.kiosk-head{
  display:flex;align-items:center;gap:2vw;padding:1.6vh 2.2vw;
  border-bottom:1px solid rgba(255,255,255,.14);flex:none;
}
.kiosk-head img{height:min(5.6vh,4.4vw);min-height:32px;width:auto}
.kiosk-head .k-title{font-family:var(--serif);font-weight:700;font-size:min(2.4vh,1.9vw);letter-spacing:-.01em}
.kiosk-head .k-sub{font-size:1.6vh;color:rgba(255,255,255,.62);letter-spacing:.1em;text-transform:uppercase}
.kiosk-head .k-right{margin-left:auto;text-align:right;display:flex;align-items:center;gap:2vw}
.kiosk-head .k-clock{font-family:var(--mono);font-size:min(3.2vh,2.6vw);line-height:1;font-variant-numeric:tabular-nums}
.kiosk-head .k-live{font-size:1.5vh;color:rgba(255,255,255,.62)}
.kiosk-head .k-live b{color:var(--green);font-weight:700}

.kiosk-stage{flex:1;position:relative;min-height:0}
.slide{position:absolute;inset:0;padding:2.6vh 2.2vw;display:flex;flex-direction:column;opacity:0;transition:opacity .5s ease}
.slide.on{opacity:1}
@media(prefers-reduced-motion:reduce){.slide{transition:none}}

.slide-head{display:flex;align-items:flex-end;gap:2vw;flex:none;margin-bottom:2.2vh}
.slide-head h2{font-family:var(--serif);font-size:min(6vh,4.4vw);line-height:1.04;font-weight:700}
.slide-head .s-meta{font-size:1.8vh;color:rgba(255,255,255,.66);margin-top:.7vh}
.slide-head .s-floor{
  font-size:1.7vh;letter-spacing:.14em;text-transform:uppercase;color:var(--green);
  border:1px solid rgba(89,216,161,.5);border-radius:999px;padding:.5vh 1.1vw;
}
.s-state{margin-left:auto;text-align:right;flex:none}
.s-state b{display:block;font-family:var(--serif);font-size:min(3.4vh,2.6vw);font-weight:700}
.s-state span{font-size:1.5vh;letter-spacing:.14em;text-transform:uppercase;color:rgba(255,255,255,.6)}
.good-t{color:var(--green)}.fair-t{color:var(--yellow)}.poor-t{color:var(--orange)}.dorm-t{color:rgba(255,255,255,.55)}

.slide-body{flex:1;display:grid;grid-template-columns:1.35fr 1fr;gap:1.6vw;min-height:0}
.hero{
  background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);border-radius:1.2vh;
  padding:2.4vh 1.8vw;display:flex;flex-direction:column;min-height:0;
}
.hero .h-label{font-size:1.8vh;letter-spacing:.14em;text-transform:uppercase;color:rgba(255,255,255,.62)}
.hero .h-value{
  font-family:var(--mono);font-size:min(13vh,9vw);line-height:1;letter-spacing:-.04em;margin:1vh 0 .8vh;
  display:flex;align-items:baseline;gap:.6vw;
}
.hero .h-unit{font-size:2.6vh;color:rgba(255,255,255,.72);font-family:var(--sans);letter-spacing:.02em}
.hero .h-stats{font-size:1.7vh;color:rgba(255,255,255,.62);font-family:var(--mono)}
.hero svg{flex:1;width:100%;min-height:0;margin-top:1.6vh}
.tiles{display:grid;grid-template-columns:repeat(3,1fr);grid-auto-rows:1fr;gap:1.1vh 1vw;min-height:0}
.tile{
  background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:1vh;
  padding:1.4vh 1vw;display:flex;flex-direction:column;justify-content:center;min-width:0;
}
.tile .t-lab{font-size:1.5vh;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.6);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tile .t-val{font-family:var(--mono);font-size:min(4.2vh,3.4vw);line-height:1.05;letter-spacing:-.02em;margin-top:.5vh;white-space:nowrap}
.tile .t-val small{font-family:var(--sans);font-size:1.7vh;color:rgba(255,255,255,.7);margin-left:.35vw}

/* floor summary slide */
.floor-grid{
  flex:1;display:grid;grid-template-columns:repeat(auto-fit,minmax(15vw,1fr));
  grid-auto-rows:minmax(0,1fr);gap:1.4vh 1.2vw;overflow:hidden;min-height:0;
}
.floor-grid .f-card{display:flex;flex-direction:column;justify-content:center}
.floor-grid.few{grid-template-columns:repeat(auto-fit,minmax(24vw,1fr));grid-auto-rows:minmax(0,34vh);align-content:center}
.floor-grid.few .f-card h3{font-size:3.2vh}
.floor-grid.few .f-val{font-size:8vh}
.floor-grid.few .f-val small{font-size:2.6vh}
.floor-grid.mid .f-card h3{font-size:2.4vh}
.floor-grid.mid .f-val{font-size:5vh}
.floor-grid.many .f-card{padding:1.1vh .9vw}
.floor-grid.many .f-card h3{font-size:1.9vh}
.floor-grid.many .f-val{font-size:3.6vh}
.floor-grid.many .f-val small{font-size:1.4vh}
.floor-grid.many .f-line{display:none}
.floor-grid.many .f-state{font-size:1.2vh;margin-top:.5vh}
.f-line{display:flex;flex-wrap:wrap;gap:.4vh 1.4vw;margin-top:1vh;font-size:1.6vh;color:rgba(255,255,255,.72)}
.f-line span{font-family:var(--mono)}
.f-line span i{font-style:normal;color:rgba(255,255,255,.5);margin-right:.3vw;font-family:var(--sans)}
.floor-grid.few .f-line{font-size:2.1vh;margin-top:1.6vh}
.f-state{font-size:1.4vh;letter-spacing:.12em;text-transform:uppercase;margin-top:.8vh}
.f-card{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-left:.5vh solid var(--green);border-radius:1vh;padding:1.4vh 1vw}
.f-card.fair{border-left-color:var(--yellow)}
.f-card.poor{border-left-color:var(--orange)}
.f-card.dorm{border-left-color:rgba(255,255,255,.3)}
.f-card h3{font-family:var(--serif);font-size:2.1vh;font-weight:700;line-height:1.15;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.f-card .f-val{font-family:var(--mono);font-size:min(3.4vh,3vw);margin-top:.6vh;white-space:nowrap}
.f-card .f-val small{font-family:var(--sans);font-size:1.5vh;color:rgba(255,255,255,.65);margin-left:.3vw}
.f-card .f-sub{font-size:1.5vh;color:rgba(255,255,255,.6);margin-top:.4vh;font-family:var(--mono)}
.floor-numeral{font-family:var(--serif);font-size:min(11vh,8vw);line-height:.85;font-weight:700;color:var(--green)}

.kiosk-foot{
  flex:none;display:flex;align-items:center;gap:1.6vw;padding:1.4vh 2.2vw;
  border-top:1px solid rgba(255,255,255,.14);font-size:1.6vh;color:rgba(255,255,255,.7);
}
.kiosk-foot .k-next{margin-left:auto;text-align:right}
.kiosk-foot .k-next b{color:#fff;font-weight:700}
.progress{position:absolute;left:0;right:0;bottom:0;height:.5vh;background:rgba(255,255,255,.12)}
.progress i{display:block;height:100%;width:0;background:var(--green)}
.kiosk-foot{position:relative}
.paused .progress i{background:var(--yellow)}
.kiosk-alert{
  position:absolute;top:0;left:0;right:0;padding:1.2vh 2.2vw;background:var(--red);color:#fff;
  font-size:1.7vh;display:none;
}
.kiosk-alert.on{display:block}

/* Portrait screens: stack the hero above the tiles. */
@media (max-aspect-ratio: 4/5){
  .slide-body{grid-template-columns:1fr;grid-template-rows:1.15fr 1fr;gap:1.6vh}
  .tiles{grid-template-columns:repeat(2,1fr)}
  .tiles>.tile:nth-child(n+7){display:none}
  .tile .t-lab{font-size:1.6vw}
  .tile .t-val{font-size:5vw}
  .tile .t-val small{font-size:1.8vw}
  .hero .h-value{font-size:14vw}
  .hero .h-label,.hero .h-stats{font-size:2vw}
  .slide-head{flex-wrap:wrap;gap:1.4vw}
  .slide-head h2{font-size:6.4vw}
  .slide-head .s-meta{font-size:2vw}
  .s-state{margin-left:0}
  .s-state b{font-size:4vw}
  .floor-grid,.floor-grid.few,.floor-grid.mid{grid-template-columns:repeat(2,1fr)}
  .floor-grid.many{grid-template-columns:repeat(2,1fr)}
  .f-card h3{font-size:2.6vw}
  .floor-grid .f-val{font-size:5vw}
  .kiosk-head img{height:7vw}
  .kiosk-head .k-title{font-size:2.6vw}
  .kiosk-head .k-sub{font-size:1.7vw}
  .kiosk-head .k-clock{font-size:4vw}
  .kiosk-foot{font-size:1.8vw}
}

@media(max-width:900px){
  .controls{top:auto;position:static}
  .masthead{position:static}
  .floor{grid-template-columns:1fr}
  .plate{text-align:left;padding:0 0 12px;display:flex;align-items:baseline;gap:12px}
  .plate::after{display:none}
  .plate .fl-num{font-size:30px}
  .plate .fl-cnt{margin-top:0}
  .plate.sticky-plate{position:static}
  .rooms{padding:0 0 26px;border-top:1px solid var(--hairline);padding-top:14px}
  .brand img{height:38px}
  main{padding:20px 16px 70px}
  .masthead-in,.controls-in{padding:12px 16px}
}
</style>
</head>
<body>

<header class="masthead">
  <div class="masthead-in">
    <div class="brand">
      <img src="data:image/png;base64,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" alt="Royal College of Physicians">
      <div class="brand-rule" aria-hidden="true"></div>
      <div class="brand-txt">
        <h1>Indoor Environment Monitor</h1>
        <p><?= htmlspecialchars(SITE_NAME, ENT_QUOTES) ?> &middot; <?= htmlspecialchars(SITE_SUB, ENT_QUOTES) ?></p>
      </div>
    </div>
    <div class="mast-right">
      <div class="clock live" id="clock">
        <strong id="clockTime">--:--</strong>
        <span id="clockNote">Connecting to Airthings</span>
      </div>
      <button class="pill" id="btnRefresh"><span class="dot" id="liveDot"></span> Refresh now</button>
      <button class="pill" id="btnView" aria-pressed="false">Data table</button>
      <button class="pill" id="btnDisplay">Display mode</button>
    </div>
  </div>
</header>

<div class="controls">
  <div class="controls-in">
    <div class="field">
      <label for="selLocation">Building</label>
      <select id="selLocation"><option value="">All</option></select>
    </div>
    <div class="field">
      <label for="txtSearch">Room</label>
      <input type="search" id="txtSearch" placeholder="Search room or serial">
    </div>
    <label class="toggle"><input type="checkbox" id="chkAlerts"> Alerts only</label>
    <label class="toggle"><input type="checkbox" id="chkHubs"> Show gateways</label>
    <div class="metric-scroll" id="metricChips" role="group" aria-label="Colour rooms by metric"></div>
  </div>
</div>

<main>
  <div id="notices"></div>
  <dl class="summary" id="summary"></dl>
  <div id="view"></div>
</main>

<div class="footer">
  <span id="footRate">Rate limit: not yet reported</span>
  <span id="footCache"></span>
  <span>Data source: Airthings for Business API</span>
  <span><?= htmlspecialchars(ORG_NAME, ENT_QUOTES) ?> &middot; internal use</span>
</div>


<section class="kiosk" id="kiosk" aria-label="Display mode">
  <div class="kiosk-alert" id="kioskAlert"></div>
  <div class="kiosk-head">
    <img src="data:image/png;base64,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" alt="Royal College of Physicians">
    <div>
      <div class="k-title">Indoor Environment Monitor</div>
      <div class="k-sub"><?= htmlspecialchars(SITE_NAME, ENT_QUOTES) ?> &middot; <?= htmlspecialchars(SITE_SUB, ENT_QUOTES) ?></div>
    </div>
    <div class="k-right">
      <div>
        <div class="k-clock" id="kClock">--:--</div>
        <div class="k-live" id="kLive">Live from Airthings</div>
      </div>
    </div>
  </div>
  <div class="kiosk-stage" id="kStage"></div>
  <div class="kiosk-foot">
    <span id="kPosition">Loading</span>
    <span id="kHint">Space pauses &middot; arrows move &middot; Esc exits</span>
    <span class="k-next" id="kNext"></span>
    <span class="progress"><i id="kProgress"></i></span>
  </div>
</section>

<div class="scrim" id="scrim"></div>
<aside class="drawer" id="drawer" aria-hidden="true" aria-label="Room detail">
  <div class="drawer-head">
    <button class="drawer-close" id="drawerClose" aria-label="Close">&times;</button>
    <div class="eyebrow" id="dEyebrow">Room</div>
    <h2 id="dTitle">Room</h2>
    <p id="dMeta"></p>
  </div>
  <div class="drawer-body" id="drawerBody"></div>
</aside>
<script>
"use strict";

/* ---------------------------------------------------------------------------
 * Metric catalogue. Order here is the order shown on cards and in the table.
 * bands  : lower is better  -> good up to bands.good, fair up to bands.fair
 * window : middle is best   -> good inside window.good, fair inside window.fair
 * floor  : higher is better -> good at or above floor.good, fair above floor.fair
 * ------------------------------------------------------------------------ */
const METRICS = [
  { k: "co2",               short: "CO\u2082", label: "CO\u2082",        unit: "ppm",      dp: 0, bands: { good: 800, fair: 1000 } },
  { k: "temp",              short: "Temp", label: "Temperature",     unit: "\u00b0C",  dp: 1, window: { good: [18, 25], fair: [16, 27] } },
  { k: "humidity",          short: "Humidity", label: "Humidity",        unit: "%",        dp: 0, window: { good: [30, 60], fair: [25, 70] } },
  { k: "voc",               short: "VOC", label: "VOC",             unit: "ppb",      dp: 0, bands: { good: 250, fair: 2000 } },
  { k: "pm25",              short: "PM2.5", label: "PM2.5",           unit: "\u00b5g/m\u00b3", dp: 1, bands: { good: 10, fair: 25 } },
  { k: "pm1",               short: "PM1", label: "PM1",             unit: "\u00b5g/m\u00b3", dp: 1, bands: { good: 10, fair: 25 } },
  { k: "pm10",              short: "PM10", label: "PM10",            unit: "\u00b5g/m\u00b3", dp: 1, bands: { good: 25, fair: 50 } },
  { k: "radonShortTermAvg", short: "Radon", label: "Radon",           unit: "Bq/m\u00b3", dp: 0, bands: { good: 100, fair: 150 } },
  { k: "hourlyRadon",       short: "Radon/h", label: "Radon, hourly",   unit: "Bq/m\u00b3", dp: 0, bands: { good: 100, fair: 150 } },
  { k: "virusRisk",         short: "Virus risk", label: "Virus risk",      unit: "",         dp: 0, bands: { good: 30, fair: 60 } },
  { k: "mold",              short: "Mould", label: "Mould risk",      unit: "",         dp: 1, bands: { good: 2, fair: 4 } },
  { k: "occupants",         short: "Occupants", label: "Occupants",       unit: "",         dp: 0 },
  { k: "airExchangeRate",   short: "Air exch.", label: "Air exchange",    unit: "ACH",      dp: 2 },
  { k: "airflow",           short: "Airflow", label: "Airflow",         unit: "l/s",      dp: 1 },
  { k: "ventilationAmount", short: "Ventilation", label: "Ventilation",     unit: "",         dp: 1 },
  { k: "controlSignal",     short: "Control", label: "Control signal",  unit: "%",        dp: 0 },
  { k: "sla",              short: "Sound", label: "Sound level",      unit: "dBA",      dp: 0 },
  { k: "pressure",          short: "Pressure", label: "Pressure",        unit: "hPa",      dp: 0 },
  { k: "lux",               short: "Light", label: "Light",           unit: "lux",      dp: 0 },
  { k: "light",             short: "Light lvl", label: "Light level",     unit: "",         dp: 0 },
  { k: "battery",           short: "Battery", label: "Battery",         unit: "%",        dp: 0, floorBand: { good: 20, fair: 10 } },
  { k: "rssi",              short: "Signal", label: "Signal",          unit: "dBm",      dp: 0 }
];
const METRIC_BY_KEY = Object.fromEntries(METRICS.map(m => [m.k, m]));
const STALE_AFTER = 20 * 60;   // a sensor quiet for 20 minutes is flagged

const state = {
  rooms: [],
  locations: [],
  metric: "co2",
  view: "rooms",
  search: "",
  location: "",
  alertsOnly: false,
  showHubs: false,
  floorMap: {},
  generated: 0,
  refresh: 120,
  sparklines: true,
  history: new Map(),          // serial+hours -> history payload
  sort: { col: null, dir: 1 },
  countdown: 0,
  dwell: 14,
  floorSlides: true,
  displayOnLoad: true,
  reloadHours: 6,
  open: null
};

const el = id => document.getElementById(id);

/* ---------------------------------------------------------------------------
 * Formatting and status
 * ------------------------------------------------------------------------ */
function fmt(value, metric) {
  if (value === null || value === undefined || Number.isNaN(value)) return "\u2013";
  const dp = metric ? metric.dp : 1;
  return Number(value).toFixed(dp);
}

function status(key, value) {
  if (value === null || value === undefined || Number.isNaN(value)) return "";
  const m = METRIC_BY_KEY[key];
  if (!m) return "";
  const v = Number(value);
  if (m.bands) return v <= m.bands.good ? "good" : (v <= m.bands.fair ? "fair" : "poor");
  if (m.window) {
    if (v >= m.window.good[0] && v <= m.window.good[1]) return "good";
    if (v >= m.window.fair[0] && v <= m.window.fair[1]) return "fair";
    return "poor";
  }
  if (m.floorBand) return v >= m.floorBand.good ? "good" : (v >= m.floorBand.fair ? "fair" : "poor");
  return "";
}

function worstStatus(room) {
  let worst = "";
  const rank = { "": 0, good: 1, fair: 2, poor: 3 };
  for (const m of METRICS) {
    if (m.k === "battery" || m.k === "rssi") continue;
    const s = status(m.k, room.data ? room.data[m.k] : null);
    if (rank[s] > rank[worst]) worst = s;
  }
  return worst;
}

function ageSeconds(room) {
  if (!room.time) return null;
  return Math.max(0, Math.floor(Date.now() / 1000) - room.time);
}

function ago(seconds) {
  if (seconds === null) return "no reading";
  if (seconds < 90) return "just now";
  if (seconds < 3600) return Math.round(seconds / 60) + " min ago";
  if (seconds < 86400) return Math.round(seconds / 3600) + " h ago";
  return Math.round(seconds / 86400) + " d ago";
}

function clockText(ts) {
  return new Date(ts * 1000).toLocaleTimeString("en-GB", { hour: "2-digit", minute: "2-digit" });
}

function esc(text) {
  return String(text == null ? "" : text).replace(/[&<>"']/g, c =>
    ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
}

/* ---------------------------------------------------------------------------
 * Data loading
 * ------------------------------------------------------------------------ */
async function api(params) {
  const q = new URLSearchParams(params).toString();
  const res = await fetch(location.pathname + "?" + q, { headers: { Accept: "application/json" } });
  let payload = null;
  try { payload = await res.json(); } catch (e) { throw new Error("The server did not return JSON. Check the PHP error log."); }
  if (!payload || payload.ok !== true) throw new Error((payload && payload.error) || ("Request failed, HTTP " + res.status));
  return payload;
}

async function loadLatest(fresh) {
  el("clockNote").textContent = "Reading sensors\u2026";
  el("liveDot").className = "dot";
  try {
    const data = await api(fresh ? { api: "latest", fresh: "1" } : { api: "latest" });
    state.rooms = data.rooms || [];
    state.floorMap = data.floorMap || {};
    state.locations = data.locations || [];
    state.generated = data.generated;
    state.refresh = data.refresh || 120;
    state.sparklines = !!data.sparklines;
    state.dwell = data.dwell || 14;
    state.floorSlides = data.floorSlides !== false;
    state.displayOnLoad = data.displayOnLoad !== false;
    state.reloadHours = data.reloadHours || 6;
    state.countdown = state.refresh;
    renderNotices(data.warnings || [], null);
    renderFooter(data);
    fillLocations();
    ensureMetric();
    render();
    kioskAlert("");
    kioskRefreshed();
  } catch (err) {
    renderNotices([], err.message);
    el("clockNote").textContent = "Connection failed";
    el("liveDot").className = "dot down";
    kioskAlert("No data from Airthings: " + err.message + " Retrying every " + (state.refresh || 120) + " seconds.");
  }
}

async function loadHistory(serial, hours) {
  const key = serial + ":" + hours;
  if (state.history.has(key)) return state.history.get(key);
  const payload = await api({ api: "history", serial: serial, hours: hours });
  state.history.set(key, payload);
  return payload;
}

/* ---------------------------------------------------------------------------
 * Filtering
 * ------------------------------------------------------------------------ */
function visibleRooms() {
  const q = state.search.trim().toLowerCase();
  return state.rooms.filter(r => {
    if (!state.showHubs && r.role === "hub") return false;
    if (state.location && r.locationId !== state.location) return false;
    if (q && !(r.room.toLowerCase().includes(q) || r.serial.toLowerCase().includes(q) || r.floor.toLowerCase().includes(q))) return false;
    if (state.alertsOnly) {
      const w = worstStatus(r);
      if (w !== "fair" && w !== "poor") return false;
    }
    return true;
  });
}

function presentMetrics(rooms) {
  return METRICS.filter(m => rooms.some(r => r.data && r.data[m.k] !== null && r.data[m.k] !== undefined));
}

function ensureMetric() {
  const present = presentMetrics(state.rooms);
  if (!present.length) return;
  if (!present.some(m => m.k === state.metric)) state.metric = present[0].k;
}

/* ---------------------------------------------------------------------------
 * Rendering: chrome
 * ------------------------------------------------------------------------ */
function renderNotices(warnings, error) {
  const parts = [];
  if (error) {
    parts.push('<div class="notice error"><h3>No data from the Airthings API</h3><p>' + esc(error) + "</p>" +
      "<ul><li>Confirm the client id and secret at the top of this file, and that the client uses the Client Credentials flow with the <code>read:device</code> scope.</li>" +
      "<li>Check the server can reach <code>accounts-api.airthings.com</code> and <code>ext-api.airthings.com</code> on port 443.</li>" +
      "<li>Open <code>?api=diag</code> in a new tab for a connectivity and token test.</li></ul></div>");
  }
  if (warnings && warnings.length) {
    parts.push('<div class="notice"><h3>Partial data</h3><ul>' +
      warnings.map(w => "<li>" + esc(w) + "</li>").join("") + "</ul></div>");
  }
  el("notices").innerHTML = parts.join("");
}

function renderFooter(data) {
  const rl = data.rateLimit || {};
  el("footRate").textContent = rl.remaining
    ? "Rate limit: " + rl.remaining + " of " + (rl.limit || "5000") + " requests left this hour"
    : "Rate limit: not yet reported";
  el("footCache").textContent = data.cached
    ? "Served from cache, refreshed at least every " + (data.ttl || 120) + " s"
    : "Live read at " + clockText(data.generated);
}

function fillLocations() {
  const sel = el("selLocation");
  if (sel.options.length - 1 === state.locations.length) return;
  sel.innerHTML = '<option value="">All buildings</option>' +
    state.locations.map(l => '<option value="' + esc(l.id) + '">' + esc(l.name) + "</option>").join("");
  sel.value = state.location;
}

function renderChips(rooms) {
  const present = presentMetrics(state.rooms);
  el("metricChips").innerHTML = present.map(m =>
    '<button class="chip" data-metric="' + m.k + '" aria-pressed="' + (m.k === state.metric) + '">' +
    esc(m.label) + "</button>").join("");
}

function renderSummary(rooms) {
  const metric = METRIC_BY_KEY[state.metric];
  const values = rooms.map(r => r.data ? r.data[state.metric] : null).filter(v => v !== null && v !== undefined).map(Number);
  const avg = values.length ? values.reduce((a, b) => a + b, 0) / values.length : null;
  const peak = values.length ? Math.max(...values) : null;
  const counts = { good: 0, fair: 0, poor: 0, none: 0 };
  rooms.forEach(r => { const w = worstStatus(r); counts[w || "none"]++; });
  const stale = rooms.filter(r => { const a = ageSeconds(r); return a === null || a > STALE_AFTER; }).length;
  const floors = new Set(rooms.map(r => r.floor)).size;

  const cell = (label, value, note) =>
    "<div><dt>" + esc(label) + "</dt><dd>" + value + (note ? " <small>" + esc(note) + "</small>" : "") + "</dd></div>";

  el("summary").innerHTML =
    cell("Rooms monitored", rooms.length, floors + (floors === 1 ? " floor" : " floors")) +
    cell("Within guidance", counts.good, counts.good + counts.fair + counts.poor ? Math.round(100 * counts.good / (counts.good + counts.fair + counts.poor)) + "%" : "") +
    cell("Watch", counts.fair, "elevated") +
    cell("Action", counts.poor, "over threshold") +
    cell("Site average " + (metric ? metric.label : ""), avg === null ? "\u2013" : fmt(avg, metric), metric ? metric.unit : "") +
    cell("Highest reading", peak === null ? "\u2013" : fmt(peak, metric), metric ? metric.unit : "") +
    cell("Sensors quiet", stale, "over 20 min") +
    cell("Gateways", state.rooms.filter(r => r.role === "hub").length, "no sensors");
}

/* ---------------------------------------------------------------------------
 * Rendering: room cards grouped by floor
 * ------------------------------------------------------------------------ */
function floorNumeral(floor) {
  const m = /(\d+)/.exec(floor);
  if (m) return m[1];
  if (/ground/i.test(floor)) return "G";
  if (/lower/i.test(floor)) return "LG";
  if (/sub/i.test(floor)) return "B2";
  if (/basement/i.test(floor)) return "B";
  if (/mezz/i.test(floor)) return "M";
  if (/roof/i.test(floor)) return "R";
  return "\u00b7";
}

function renderRooms(rooms) {
  if (!rooms.length) {
    el("view").innerHTML = '<div class="empty">No rooms match the current filters.</div>';
    return;
  }
  const groups = new Map();
  rooms.forEach(r => {
    if (!groups.has(r.floor)) groups.set(r.floor, []);
    groups.get(r.floor).push(r);
  });
  const metric = METRIC_BY_KEY[state.metric];

  let html = "";
  for (const [floor, list] of groups) {
    const flagged = list.filter(r => ["fair", "poor"].includes(worstStatus(r))).length;
    html += '<section class="floor">' +
      '<div class="plate sticky-plate"><span class="fl-num">' + esc(floorNumeral(floor)) + "</span>" +
      '<span class="fl-lab">' + esc(floor) + "</span>" +
      '<span class="fl-cnt">' + list.length + (list.length === 1 ? " room" : " rooms") + (flagged ? " / " + flagged + " flagged" : "") +
      "</span>" + (floor === "Unassigned"
        ? '<button class="pill" id="bulkFloors" style="margin-top:10px;font-size:12px;padding:5px 10px">Assign floors</button>'
        : "") + "</div>" +
      '<div class="rooms">' + list.map(r => roomCard(r, metric)).join("") + "</div></section>";
  }
  el("view").innerHTML = html;

  el("view").querySelectorAll(".room").forEach(node => {
    node.addEventListener("click", () => openDrawer(node.dataset.serial));
  });
  const bulk = document.getElementById("bulkFloors");
  if (bulk) bulk.addEventListener("click", ev => { ev.stopPropagation(); openBulkFloors(); });
  if (state.sparklines) queueSparklines();
}

function roomCard(room, metric) {
  const data = room.data || {};
  const heroVal = data[state.metric];
  const heroStatus = status(state.metric, heroVal);
  const age = ageSeconds(room);
  const isStale = age === null || age > STALE_AFTER;
  const worst = worstStatus(room);
  const rail = isStale ? "" : (heroStatus || worst);

  const rows = METRICS.filter(m => m.k !== state.metric && data[m.k] !== null && data[m.k] !== undefined)
    .map(m => '<li><span class="mk">' + esc(m.short || m.label) + '</span><span class="mv ' + status(m.k, data[m.k]) + '">' +
      fmt(data[m.k], m) + (m.unit ? " " + esc(m.unit) : "") + "</span></li>").join("");

  const badge = room.role === "hub" ? '<span class="badge dormant">Gateway</span>'
    : isStale ? '<span class="badge dormant">No recent data</span>'
    : (worst === "poor" ? '<span class="badge poor">Action</span>'
      : (worst === "fair" ? '<span class="badge fair">Watch</span>' : '<span class="badge">Within guidance</span>'));

  return '<article class="room' + (room.role === "hub" ? " hub" : "") + '" data-serial="' + esc(room.serial) + '" tabindex="0" role="button" ' +
    'aria-label="' + esc(room.room + ", " + room.floor) + '">' +
    '<div class="room-rail ' + rail + '"></div>' +
    '<div class="room-head"><div><h3>' + esc(room.room) + "</h3>" +
    "<p>" + esc(room.product) + " &middot; " + esc(room.serial) + "</p></div>" +
    '<div class="room-hero"><b class="' + (heroStatus ? "" : "") + '" style="color:' + statusColour(heroStatus) + '">' +
    fmt(heroVal, metric) + "</b><span>" + esc(metric ? metric.unit || metric.label : "") + "</span></div></div>" +
    (state.sparklines ? '<svg class="spark" data-serial="' + esc(room.serial) + '" viewBox="0 0 300 38" preserveAspectRatio="none" aria-hidden="true"></svg>' : "") +
    '<ul class="metrics">' + rows + "</ul>" +
    '<div class="room-foot"><span class="' + (isStale ? "stale-flag" : "") + '">' + esc(ago(age)) + "</span>" +
    (state.locations.length > 1 && room.location ? "<span>" + esc(room.location) + "</span>" : "") +
    badge + "</div></article>";
}

function statusColour(s) {
  return s === "good" ? "var(--good)" : s === "fair" ? "var(--fair)" : s === "poor" ? "var(--poor)" : "var(--ink)";
}

/* ---------------------------------------------------------------------------
 * Sparklines: 24 hours of the active metric, fetched only when scrolled into view
 * ------------------------------------------------------------------------ */
let sparkObserver = null;
const sparkQueue = [];
let sparkBusy = 0;

function queueSparklines() {
  if (sparkObserver) sparkObserver.disconnect();
  sparkObserver = new IntersectionObserver(entries => {
    entries.forEach(entry => {
      if (!entry.isIntersecting) return;
      sparkObserver.unobserve(entry.target);
      sparkQueue.push(entry.target);
      pumpSparklines();
    });
  }, { rootMargin: "200px" });
  document.querySelectorAll(".spark").forEach(node => sparkObserver.observe(node));
}

async function pumpSparklines() {
  while (sparkBusy < 2 && sparkQueue.length) {
    const node = sparkQueue.shift();
    sparkBusy++;
    drawSpark(node).finally(() => { sparkBusy--; pumpSparklines(); });
  }
}

async function drawSpark(node) {
  const serial = node.dataset.serial;
  try {
    const hist = await loadHistory(serial, 24);
    const series = seriesFor(hist, state.metric);
    if (!series.values.length) { node.innerHTML = ""; return; }
    node.innerHTML = sparkSvg(series.times, series.values, 300, 38, state.metric);
    node.classList.add("drawn");
  } catch (e) {
    node.innerHTML = "";
  }
}

function seriesFor(hist, key) {
  const data = hist && hist.data ? hist.data : {};
  const values = Array.isArray(data[key]) ? data[key] : [];
  const times = Array.isArray(data.time) ? data.time : [];
  return { values: values, times: times };
}

/**
 * Build an SVG path from sparse samples. Metrics do not all report on every
 * timestamp, so points are placed by time and the line breaks across real gaps.
 */
function linePath(times, values, geom) {
  const pts = [];
  for (let i = 0; i < values.length; i++) {
    const v = values[i];
    if (v === null || v === undefined) continue;
    const t = times.length === values.length ? Number(times[i]) : i;
    if (!Number.isFinite(t) || !Number.isFinite(Number(v))) continue;
    pts.push([t, Number(v)]);
  }
  if (!pts.length) return null;

  const ys = pts.map(p => p[1]);
  let vMin = Math.min.apply(null, ys), vMax = Math.max.apply(null, ys);
  if (vMax - vMin < 1e-9) { vMin -= 1; vMax += 1; }
  const tMin = pts[0][0], tMax = pts[pts.length - 1][0];
  const tSpan = tMax - tMin || 1;

  const x = t => geom.padL + ((t - tMin) / tSpan) * (geom.w - geom.padL - geom.padR);
  const y = v => geom.padT + (1 - (v - vMin) / (vMax - vMin)) * (geom.h - geom.padT - geom.padB);

  // Break the line only across real outages. Reporting intervals vary by metric,
  // so the threshold is based on the wider spacings actually present.
  const steps = [];
  for (let i = 1; i < pts.length; i++) steps.push(pts[i][0] - pts[i - 1][0]);
  steps.sort((a, b) => a - b);
  const median = steps.length ? steps[Math.floor(steps.length / 2)] : 0;
  const p90 = steps.length ? steps[Math.floor(steps.length * 0.9)] : 0;
  const gap = steps.length ? Math.max(p90 * 3, median * 6, 1800) : Infinity;

  let d = "";
  const isolated = [];
  pts.forEach((p, i) => {
    const before = i > 0 ? p[0] - pts[i - 1][0] : Infinity;
    const after = i < pts.length - 1 ? pts[i + 1][0] - p[0] : Infinity;
    if (before > gap && after > gap) { isolated.push(p); return; }
    d += (before > gap || d === "" ? "M" : "L") + x(p[0]).toFixed(1) + " " + y(p[1]).toFixed(1) + " ";
  });

  return {
    d: d.trim(), isolated: isolated, last: pts[pts.length - 1],
    vMin: vMin, vMax: vMax, x: x, y: y, count: pts.length
  };
}

/** Lone samples either side of an outage, so they are not silently dropped. */
function dots(line, colour, r) {
  return line.isolated.map(p => '<circle cx="' + line.x(p[0]).toFixed(1) + '" cy="' + line.y(p[1]).toFixed(1) +
    '" r="' + r + '" fill="' + colour + '" opacity="0.75"/>').join("");
}

function sparkSvg(times, values, w, h, key) {
  const line = linePath(times, values, { w: w, h: h, padL: 2, padR: 2, padT: 5, padB: 5 });
  if (!line) return "";
  const colour = statusColour(status(key, line.last[1]));
  return dots(line, colour, 1.4) +
    '<path d="' + line.d + '" fill="none" stroke="' + colour +
    '" stroke-width="1.6" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>' +
    '<circle cx="' + line.x(line.last[0]).toFixed(1) + '" cy="' + line.y(line.last[1]).toFixed(1) +
    '" r="2.2" fill="' + colour + '"/>';
}

/* ---------------------------------------------------------------------------
 * Rendering: data table
 * ------------------------------------------------------------------------ */
function renderTable(rooms) {
  const cols = presentMetrics(state.rooms);
  const sorted = rooms.slice();
  if (state.sort.col) {
    const c = state.sort.col;
    sorted.sort((a, b) => {
      const av = c === "room" ? a.room.toLowerCase() : c === "floor" ? a.floorRank : (a.data ? a.data[c] : null);
      const bv = c === "room" ? b.room.toLowerCase() : c === "floor" ? b.floorRank : (b.data ? b.data[c] : null);
      if (av === null || av === undefined) return 1;
      if (bv === null || bv === undefined) return -1;
      return (av > bv ? 1 : av < bv ? -1 : 0) * state.sort.dir;
    });
  }

  const head = '<tr><th><button data-sort="floor">Floor</button></th><th><button data-sort="room">Room</button></th>' +
    cols.map(m => '<th><button data-sort="' + m.k + '">' + esc(m.short || m.label) + (m.unit ? " <span>" + esc(m.unit) + "</span>" : "") + "</button></th>").join("") +
    "<th>Updated</th></tr>";

  const body = sorted.map(r => {
    const d = r.data || {};
    return "<tr data-serial=\"" + esc(r.serial) + "\">" +
      "<td>" + esc(r.floor) + "</td><td>" + esc(r.room) + "</td>" +
      cols.map(m => '<td class="' + status(m.k, d[m.k]) + '">' + fmt(d[m.k], m) + "</td>").join("") +
      "<td>" + esc(ago(ageSeconds(r))) + "</td></tr>";
  }).join("");

  el("view").innerHTML =
    '<div style="display:flex;gap:12px;align-items:center;margin-bottom:12px">' +
    '<p class="hint" style="margin:0">' + sorted.length + " rooms, " + cols.length +
    " metrics. Click a column to sort, click a row for the full history.</p>" +
    '<button class="pill" id="btnCsv" style="margin-left:auto">Download CSV</button></div>' +
    '<div class="tablewrap"><table><thead>' + head + "</thead><tbody>" + body + "</tbody></table></div>";

  el("view").querySelectorAll("thead button").forEach(btn => {
    btn.addEventListener("click", () => {
      const col = btn.dataset.sort;
      state.sort = { col: col, dir: state.sort.col === col ? -state.sort.dir : 1 };
      render();
    });
  });
  el("view").querySelectorAll("tbody tr").forEach(tr => {
    tr.addEventListener("click", () => openDrawer(tr.dataset.serial));
  });
  el("btnCsv").addEventListener("click", () => downloadCsv(sorted, cols));
}

function downloadCsv(rooms, cols) {
  const header = ["Floor", "Room", "Serial", "Device", "Building", "Reading time"]
    .concat(cols.map(m => m.label + (m.unit ? " (" + m.unit + ")" : "")));
  const lines = [header];
  rooms.forEach(r => {
    const d = r.data || {};
    lines.push([r.floor, r.room, r.serial, r.product, r.location,
      r.time ? new Date(r.time * 1000).toISOString() : ""]
      .concat(cols.map(m => (d[m.k] === null || d[m.k] === undefined ? "" : d[m.k]))));
  });
  const csv = lines.map(row => row.map(cellCsv).join(",")).join("\r\n");
  const stamp = new Date().toISOString().slice(0, 16).replace(/[:T]/g, "-");
  const a = document.createElement("a");
  a.href = URL.createObjectURL(new Blob(["\ufeff" + csv], { type: "text/csv;charset=utf-8" }));
  a.download = "indoor-environment-" + stamp + ".csv";
  a.click();
  URL.revokeObjectURL(a.href);
}

function cellCsv(v) {
  const s = String(v == null ? "" : v);
  return /[",\r\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
}

/* ---------------------------------------------------------------------------
 * Drawer: one room, every metric, full history
 * ------------------------------------------------------------------------ */
let drawerHours = 48;

function openDrawer(serial) {
  const room = state.rooms.find(r => r.serial === serial);
  if (!room) return;
  state.open = serial;
  el("dEyebrow").textContent = room.floor + " \u00b7 " + (room.location || "");
  el("dTitle").textContent = room.room;
  el("dMeta").textContent = room.product + " \u00b7 " + room.serial + " \u00b7 last reading " + ago(ageSeconds(room));
  el("drawer").classList.add("open");
  el("drawer").setAttribute("aria-hidden", "false");
  el("scrim").classList.add("open");
  el("drawerClose").focus();
  renderDrawer(room);
}

function openBulkFloors() {
  const list = state.rooms.filter(r => r.floor === "Unassigned" || state.floorMap[r.serial]);
  state.open = "__bulk__";
  el("dEyebrow").textContent = "Setup";
  el("dTitle").textContent = "Assign rooms to floors";
  el("dMeta").textContent = list.length + " rooms without a floor in their name";
  el("drawer").classList.add("open");
  el("drawer").setAttribute("aria-hidden", "false");
  el("scrim").classList.add("open");

  el("drawerBody").innerHTML =
    '<p class="hint">Type a floor for each room, for example Level 1, Ground floor or Basement. ' +
    'Rooms sharing a floor label are grouped together everywhere in the dashboard.</p>' +
    '<div class="assign"><div class="row"><input id="bulkAll" placeholder="Apply to every empty box below" maxlength="40">' +
    '<button class="pill" id="bulkFill">Fill empties</button></div></div>' +
    list.map(r => '<div class="assign"><label>' + esc(r.room) + " \u00b7 " + esc(r.serial) + "</label>" +
      '<div class="row"><input class="bulkRow" data-serial="' + esc(r.serial) + '" value="' +
      esc(state.floorMap[r.serial] || "") + '" placeholder="' + esc(r.floor) + '" maxlength="40"></div></div>').join("") +
    '<button class="pill is-on" id="bulkSave" style="margin-top:8px">Save all floors</button>';

  document.getElementById("bulkFill").addEventListener("click", () => {
    const value = document.getElementById("bulkAll").value.trim();
    if (!value) return;
    document.querySelectorAll(".bulkRow").forEach(i => { if (!i.value.trim()) i.value = value; });
  });
  document.getElementById("bulkSave").addEventListener("click", async () => {
    const btn = document.getElementById("bulkSave");
    const map = {};
    document.querySelectorAll(".bulkRow").forEach(i => { map[i.dataset.serial] = i.value.trim(); });
    btn.textContent = "Saving\u2026";
    try {
      const res = await fetch(location.pathname + "?api=setfloor", {
        method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ map: map })
      });
      const payload = await res.json();
      if (!payload || payload.ok !== true) throw new Error((payload && payload.error) || "Save failed.");
      state.floorMap = payload.map || {};
      state.history.clear();
      await loadLatest(true);
      closeDrawer();
    } catch (err) {
      btn.textContent = "Save failed";
      alert(err.message);
    }
  });
}

function closeDrawer() {
  state.open = null;
  el("drawer").classList.remove("open");
  el("drawer").setAttribute("aria-hidden", "true");
  el("scrim").classList.remove("open");
}

async function renderDrawer(room) {
  const body = el("drawerBody");
  const ranges = [24, 48, 168, 720];
  body.innerHTML = assignBlock(room) + '<div class="range">' + ranges.map(h =>
    '<button class="pill' + (h === drawerHours ? " is-on" : "") + '" data-hours="' + h + '">' +
    (h < 168 ? h + " hours" : (h / 24) + " days") + "</button>").join("") + "</div>" +
    '<p class="hint">Loading history\u2026</p>';

  wireDrawerControls(room, ranges);

  let hist;
  try {
    hist = await loadHistory(room.serial, drawerHours);
  } catch (err) {
    body.querySelector(".hint").innerHTML = '<span style="color:var(--poor)">History unavailable: ' + esc(err.message) + "</span>";
    return;
  }
  if (state.open !== room.serial) return;

  const data = hist.data || {};
  const times = Array.isArray(data.time) ? data.time : [];
  const keys = METRICS.filter(m => Array.isArray(data[m.k]) && data[m.k].some(v => v !== null && v !== undefined));

  let html = assignBlock(room);
  html += '<div class="range">' + ranges.map(h =>
    '<button class="pill' + (h === drawerHours ? " is-on" : "") + '" data-hours="' + h + '">' +
    (h < 168 ? h + " hours" : (h / 24) + " days") + "</button>").join("") + "</div>";

  html += '<p class="hint">' + times.length + " samples, one every " +
    Math.round((hist.cadence || 150) / 60) + " min, between " +
    (times.length ? new Date(times[0] * 1000).toLocaleString("en-GB") : "\u2013") + " and " +
    (times.length ? new Date(times[times.length - 1] * 1000).toLocaleString("en-GB") : "\u2013") + "." +
    (hist.truncated ? " The window was shortened to the most recent samples available in one read." : "") +
    ' <button class="pill" id="histCsv" style="margin-left:6px">Download this history</button></p>';

  keys.forEach(m => {
    const vals = data[m.k].map(v => (v === null || v === undefined ? null : Number(v)));
    const nums = vals.filter(v => v !== null);
    const last = [...vals].reverse().find(v => v !== null);
    const min = nums.length ? Math.min(...nums) : null;
    const max = nums.length ? Math.max(...nums) : null;
    const avg = nums.length ? nums.reduce((a, b) => a + b, 0) / nums.length : null;
    html += '<div class="mchart"><header><h3>' + esc(m.label) + "</h3>" +
      '<span class="rangetxt">min ' + fmt(min, m) + " \u00b7 avg " + fmt(avg, m) + " \u00b7 max " + fmt(max, m) + "</span>" +
      '<span class="now" style="color:' + statusColour(status(m.k, last)) + '">' + fmt(last, m) +
      (m.unit ? " " + esc(m.unit) : "") + "</span></header>" +
      chartSvg(vals, times, m) + "</div>";
  });

  if (!keys.length) html += '<p class="hint">This device returned no history for the selected window.</p>';

  html += historyTable(keys, data, times);
  body.innerHTML = html;
  wireDrawerControls(room, ranges);
  const csvBtn = document.getElementById("histCsv");
  if (csvBtn) csvBtn.addEventListener("click", () => downloadHistoryCsv(room, keys, data, times));
}

function assignBlock(room) {
  const current = state.floorMap[room.serial] || "";
  return '<div class="assign"><label for="floorInput">Floor for this room</label>' +
    '<div class="row"><input id="floorInput" value="' + esc(current) + '" placeholder="' + esc(room.floor) +
    '" maxlength="40"><button class="pill" id="floorSave">Save floor</button></div>' +
    "<p>Airthings has no floor field, so floors are read from room names. Anything set here overrides that, " +
    "for every view and the CSV export.</p></div>";
}

async function saveFloor(serial, label) {
  const res = await fetch(location.pathname + "?api=setfloor", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ serial: serial, floor: label })
  });
  const payload = await res.json();
  if (!payload || payload.ok !== true) throw new Error((payload && payload.error) || "Could not save the floor.");
  state.floorMap = payload.map || {};
}

function wireDrawerControls(room, ranges) {
  el("drawerBody").querySelectorAll("[data-hours]").forEach(btn => btn.addEventListener("click", () => {
    drawerHours = Number(btn.dataset.hours);
    renderDrawer(room);
  }));
  const save = document.getElementById("floorSave");
  if (save) {
    save.addEventListener("click", async () => {
      const input = document.getElementById("floorInput");
      save.textContent = "Saving\u2026";
      try {
        await saveFloor(room.serial, input.value.trim());
        state.history.clear();
        await loadLatest(true);
        save.textContent = "Saved";
        const updated = state.rooms.find(r => r.serial === room.serial);
        if (updated) { el("dEyebrow").textContent = updated.floor + " \u00b7 " + (updated.location || ""); }
      } catch (err) {
        save.textContent = "Save failed";
        alert(err.message);
      }
    });
  }
}

function chartSvg(values, times, metric) {
  const w = 560, h = 96;
  const geom = { w: w, h: h, padL: 2, padR: 2, padT: 8, padB: 15 };
  const line = linePath(times, values, geom);
  if (!line) return '<svg viewBox="0 0 ' + w + " " + h + '"></svg>';

  const colour = statusColour(status(metric.k, line.last[1]));
  let guides = "";
  if (metric.bands) {
    [metric.bands.good, metric.bands.fair].forEach(t => {
      if (t > line.vMin && t < line.vMax) {
        const gy = line.y(t).toFixed(1);
        guides += '<line x1="' + geom.padL + '" x2="' + (w - geom.padR) + '" y1="' + gy + '" y2="' + gy +
          '" stroke="#d9d7cf" stroke-dasharray="3 3"/>' +
          '<text x="' + (w - geom.padR) + '" y="' + (line.y(t) - 3).toFixed(1) +
          '" text-anchor="end" font-size="9" fill="#77817f">' + t + "</text>";
      }
    });
  }

  const stamp = t => new Date(t * 1000).toLocaleString("en-GB",
    { day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit" });
  const firstT = times.length ? times[0] : null;
  const lastT = times.length ? times[times.length - 1] : null;

  return '<svg viewBox="0 0 ' + w + " " + h + '" preserveAspectRatio="none" role="img" aria-label="' +
    esc(metric.label) + ' trend, ' + line.count + ' samples">' + guides +
    dots(line, colour, 1.6) +
    '<path d="' + line.d + '" fill="none" stroke="' + colour +
    '" stroke-width="1.4" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>' +
    '<circle cx="' + line.x(line.last[0]).toFixed(1) + '" cy="' + line.y(line.last[1]).toFixed(1) +
    '" r="2" fill="' + colour + '"/>' +
    '<text x="' + geom.padL + '" y="' + (h - 3) + '" font-size="9" fill="#77817f">' +
    (firstT ? esc(stamp(firstT)) : "") + "</text>" +
    '<text x="' + (w - geom.padR) + '" y="' + (h - 3) +
    '" font-size="9" fill="#77817f" text-anchor="end">' + (lastT ? esc(stamp(lastT)) : "") + "</text>" +
    '<text x="' + (w / 2) + '" y="' + (h - 3) + '" font-size="9" fill="#77817f" text-anchor="middle">' +
    fmt(line.vMin, metric) + " to " + fmt(line.vMax, metric) + (metric.unit ? " " + esc(metric.unit) : "") +
    "</text></svg>";
}

function historyTable(keys, data, times) {
  if (!times.length || !keys.length) return "";
  const cap = 1000;
  const from = Math.max(0, times.length - cap);
  const rows = [];
  for (let i = times.length - 1; i >= from; i--) {
    rows.push("<tr><td>" + new Date(times[i] * 1000).toLocaleString("en-GB",
      { day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit" }) + "</td>" +
      keys.map(m => {
        const v = data[m.k][i];
        return '<td class="' + status(m.k, v) + '">' + fmt(v === undefined ? null : v, m) + "</td>";
      }).join("") + "</tr>");
  }
  return '<h3 style="font-family:var(--sans);font-size:14px;margin:22px 0 8px">Every reading</h3>' +
    '<p class="hint">' + (from > 0
      ? "Showing the most recent " + cap + " of " + times.length + " samples. The download holds all of them."
      : "All " + times.length + " samples in this window.") + "</p>" +
    '<div class="tablewrap" style="max-height:340px"><table><thead><tr><th>Time</th>' +
    keys.map(m => "<th>" + esc(m.label) + (m.unit ? " <span>" + esc(m.unit) + "</span>" : "") + "</th>").join("") +
    "</tr></thead><tbody>" + rows.join("") + "</tbody></table></div>";
}

function downloadHistoryCsv(room, keys, data, times) {
  const header = ["Time (ISO 8601)", "Floor", "Room", "Serial"]
    .concat(keys.map(m => m.label + (m.unit ? " (" + m.unit + ")" : "")));
  const lines = [header];
  for (let i = 0; i < times.length; i++) {
    lines.push([new Date(times[i] * 1000).toISOString(), room.floor, room.room, room.serial]
      .concat(keys.map(m => {
        const v = data[m.k][i];
        return v === null || v === undefined ? "" : v;
      })));
  }
  const csv = lines.map(row => row.map(cellCsv).join(",")).join("\r\n");
  const slug = room.room.replace(/[^A-Za-z0-9]+/g, "-").replace(/^-|-$/g, "").toLowerCase();
  const a = document.createElement("a");
  a.href = URL.createObjectURL(new Blob(["\ufeff" + csv], { type: "text/csv;charset=utf-8" }));
  a.download = "history-" + slug + "-" + drawerHours + "h.csv";
  a.click();
  URL.revokeObjectURL(a.href);
}


/* ---------------------------------------------------------------------------
 * Display mode: full screen, one space at a time, cycling floor by floor.
 * Reuses the same data, metric definitions and plotting as the dashboard.
 * ------------------------------------------------------------------------ */
const kiosk = {
  on: false,
  slides: [],
  index: 0,
  paused: false,
  elapsed: 0,
  dwell: 14,
  floorSlides: true,
  startedAt: Date.now(),
  historyClearedAt: Date.now()
};

/** Metrics shown as tiles beside the hero, in priority order. */
const TILE_ORDER = ["temp", "humidity", "voc", "pm25", "pm1", "pm10", "sla", "virusRisk",
  "airExchangeRate", "radonShortTermAvg", "occupants", "pressure", "lux", "light", "battery", "rssi"];

function buildSlides() {
  const rooms = state.rooms.filter(r => r.role !== "hub");
  const byFloor = new Map();
  rooms.forEach(r => {
    if (!byFloor.has(r.floor)) byFloor.set(r.floor, []);
    byFloor.get(r.floor).push(r);
  });
  const perSlide = 12;
  const slides = [];
  for (const [floor, list] of byFloor) {
    if (kiosk.floorSlides && list.length > 1) {
      const pages = Math.ceil(list.length / perSlide);
      for (let p = 0; p < pages; p++) {
        slides.push({
          type: "floor", floor: floor, total: list.length,
          rooms: list.slice(p * perSlide, (p + 1) * perSlide),
          page: p + 1, pages: pages
        });
      }
    }
    list.forEach(r => slides.push({ type: "room", room: r }));
  }
  return slides;
}

function startDisplay() {
  kiosk.dwell = state.dwell || 14;
  kiosk.floorSlides = state.floorSlides !== false;
  kiosk.slides = buildSlides();
  if (!kiosk.slides.length) return;
  kiosk.on = true;
  kiosk.index = 0;
  kiosk.elapsed = 0;
  document.body.classList.add("on-display");
  el("kHint").style.display = "";
  setTimeout(() => { el("kHint").style.display = "none"; }, 30000);
  const root = document.documentElement;
  if (root.requestFullscreen) root.requestFullscreen().catch(() => {});
  showSlide(0);
}

function stopDisplay() {
  kiosk.on = false;
  document.body.classList.remove("on-display");
  if (document.fullscreenElement && document.exitFullscreen) document.exitFullscreen().catch(() => {});
  const url = new URL(location.href);
  url.searchParams.set("display", "0");
  history.replaceState(null, "", url.toString());
}

function moveSlide(step) {
  if (!kiosk.slides.length) return;
  kiosk.index = (kiosk.index + step + kiosk.slides.length) % kiosk.slides.length;
  kiosk.elapsed = 0;
  showSlide(kiosk.index);
}

function showSlide(i) {
  const slide = kiosk.slides[i];
  if (!slide) return;
  const stage = el("kStage");
  const node = document.createElement("div");
  node.className = "slide";
  node.innerHTML = slide.type === "floor" ? floorSlideHtml(slide) : roomSlideHtml(slide.room);
  stage.innerHTML = "";
  stage.appendChild(node);
  requestAnimationFrame(() => node.classList.add("on"));

  const next = kiosk.slides[(i + 1) % kiosk.slides.length];
  const roomSlides = kiosk.slides.filter(s => s.type === "room");
  const roomIndex = slide.type === "room"
    ? roomSlides.findIndex(s => s.room.serial === slide.room.serial) + 1 : 0;
  el("kPosition").textContent = (slide.type === "room"
    ? "Space " + roomIndex + " of " + roomSlides.length
    : "Floor summary \u00b7 " + slide.floor) + (kiosk.paused ? " \u00b7 paused" : "");
  el("kNext").innerHTML = "Next <b>" +
    esc(next.type === "floor" ? next.floor : next.room.room) + "</b>";

  if (slide.type === "room") {
    paintHero(slide.room);
    const following = kiosk.slides[(i + 1) % kiosk.slides.length];
    if (following.type === "room") loadHistory(following.room.serial, 24).catch(() => {});
  }
}

function roomSlideHtml(room) {
  const data = room.data || {};
  const metric = METRIC_BY_KEY[state.metric] || METRIC_BY_KEY.co2;
  const hero = data[metric.k] !== undefined ? metric : METRICS.find(m => data[m.k] !== undefined) || metric;
  const worst = worstStatus(room);
  const age = ageSeconds(room);
  const isStale = age === null || age > STALE_AFTER;
  const tone = isStale ? "dorm-t" : worst === "poor" ? "poor-t" : worst === "fair" ? "fair-t" : "good-t";
  const label = isStale ? "No recent data" : worst === "poor" ? "Action" : worst === "fair" ? "Watch" : "Within guidance";

  const tiles = TILE_ORDER.filter(k => k !== hero.k && data[k] !== undefined && data[k] !== null)
    .slice(0, 9)
    .map(k => {
      const m = METRIC_BY_KEY[k];
      const s = status(k, data[k]);
      const t = s === "poor" ? "poor-t" : s === "fair" ? "fair-t" : s === "good" ? "good-t" : "";
      return '<div class="tile"><div class="t-lab">' + esc(m.label) + "</div>" +
        '<div class="t-val ' + t + '">' + fmt(data[k], m) +
        (m.unit ? "<small>" + esc(m.unit) + "</small>" : "") + "</div></div>";
    }).join("");

  const heroTone = status(hero.k, data[hero.k]);
  const heroClass = heroTone === "poor" ? "poor-t" : heroTone === "fair" ? "fair-t" : heroTone === "good" ? "good-t" : "";

  return '<div class="slide-head">' +
    "<div><h2>" + esc(room.room) + "</h2>" +
    '<div class="s-meta">' + esc(room.product) + " \u00b7 " + esc(room.serial) +
    " \u00b7 reading " + esc(ago(age)) + "</div></div>" +
    '<div class="s-floor">' + esc(room.floor) + "</div>" +
    '<div class="s-state"><b class="' + tone + '">' + esc(label) + "</b><span>Air quality</span></div></div>" +
    '<div class="slide-body">' +
    '<div class="hero"><div class="h-label">' + esc(hero.label) + " over 24 hours</div>" +
    '<div class="h-value ' + heroClass + '">' + fmt(data[hero.k], hero) +
    (hero.unit ? '<span class="h-unit">' + esc(hero.unit) + "</span>" : "") + "</div>" +
    '<div class="h-stats" id="kHeroStats">reading history\u2026</div>' +
    '<svg id="kHeroChart" viewBox="0 0 800 220" preserveAspectRatio="none" aria-hidden="true"></svg></div>' +
    '<div class="tiles">' + tiles + "</div></div>";
}

async function paintHero(room) {
  const data = room.data || {};
  const metric = METRIC_BY_KEY[state.metric] || METRIC_BY_KEY.co2;
  const hero = data[metric.k] !== undefined ? metric : METRICS.find(m => data[m.k] !== undefined) || metric;
  const svg = document.getElementById("kHeroChart");
  const stats = document.getElementById("kHeroStats");
  if (!svg) return;
  try {
    const hist = await loadHistory(room.serial, 24);
    if (!kiosk.on) return;
    const series = seriesFor(hist, hero.k);
    const line = linePath(series.times, series.values, { w: 800, h: 220, padL: 4, padR: 4, padT: 12, padB: 8 });
    if (!line) { if (stats) stats.textContent = "No history for this sensor yet"; return; }
    const tone = status(hero.k, line.last[1]);
    const colour = tone === "poor" ? "#ff9862" : tone === "fair" ? "#ffcc53" : tone === "good" ? "#59d8a1" : "#ffffff";
    svg.innerHTML = dots(line, colour, 3) +
      '<path d="' + line.d + '" fill="none" stroke="' + colour +
      '" stroke-width="3" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>';
    if (stats) {
      stats.textContent = "low " + fmt(line.vMin, hero) + "  high " + fmt(line.vMax, hero) +
        "  " + line.count + " readings";
    }
  } catch (err) {
    if (stats) stats.textContent = "History unavailable";
  }
}

function floorSlideHtml(slide) {
  const metric = METRIC_BY_KEY[state.metric] || METRIC_BY_KEY.co2;
  const secondary = ["temp", "humidity", "voc", "pm25", "sla"];
  const cards = slide.rooms.map(r => {
    const d = r.data || {};
    const age = ageSeconds(r);
    const stale = age === null || age > STALE_AFTER;
    const worst = worstStatus(r);
    const cls = stale ? "dorm" : worst === "poor" ? "poor" : worst === "fair" ? "fair" : "good";
    const tone = stale ? "dorm-t" : worst === "poor" ? "poor-t" : worst === "fair" ? "fair-t" : "good-t";
    const label = stale ? "No recent data" : worst === "poor" ? "Action" : worst === "fair" ? "Watch" : "Within guidance";
    const line = secondary.filter(k => k !== metric.k && d[k] !== undefined && d[k] !== null)
      .map(k => {
        const m = METRIC_BY_KEY[k];
        return "<span><i>" + esc(m.short || m.label) + "</i>" + fmt(d[k], m) +
          (m.unit ? " " + esc(m.unit) : "") + "</span>";
      }).join("");
    return '<div class="f-card ' + cls + '"><h3>' + esc(r.room) + "</h3>" +
      '<div class="f-val ' + tone + '">' + fmt(d[metric.k], metric) +
      (metric.unit ? "<small>" + esc(metric.unit) + "</small>" : "") + "</div>" +
      '<div class="f-line">' + line + "</div>" +
      '<div class="f-state ' + tone + '">' + esc(label) + " \u00b7 " + esc(ago(age)) + "</div></div>";
  }).join("");

  const flagged = slide.rooms.filter(r => ["fair", "poor"].includes(worstStatus(r))).length;
  const density = slide.rooms.length <= 4 ? "few" : slide.rooms.length <= 8 ? "mid" : "many";
  const numeral = floorNumeral(slide.floor);
  const total = slide.total || slide.rooms.length;
  return '<div class="slide-head">' +
    (numeral === "\u00b7" ? "" : '<div class="floor-numeral">' + esc(numeral) + "</div>") +
    "<div><h2>" + esc(slide.floor) + "</h2>" +
    '<div class="s-meta">' + total + (total === 1 ? " space" : " spaces") +
    (flagged ? " \u00b7 " + flagged + " needing attention on this screen" : " \u00b7 all within guidance") +
    (slide.pages > 1 ? " \u00b7 part " + slide.page + " of " + slide.pages : "") + "</div></div>" +
    '<div class="s-state"><b>' + esc(metric.label) + "</b><span>shown per space</span></div></div>" +
    '<div class="floor-grid ' + density + '">' + cards + "</div>";
}

function kioskTick() {
  if (!kiosk.on) return;
  el("kClock").textContent = new Date().toLocaleTimeString("en-GB", { hour: "2-digit", minute: "2-digit" });
  const age = state.generated ? Math.floor(Date.now() / 1000) - state.generated : null;
  el("kLive").innerHTML = age === null ? "Waiting for data"
    : "Live from Airthings \u00b7 <b>" + esc(ago(age)) + "</b>";

  if (kiosk.paused) return;
  kiosk.elapsed += 0.25;
  el("kProgress").style.width = Math.min(100, (kiosk.elapsed / kiosk.dwell) * 100) + "%";
  if (kiosk.elapsed >= kiosk.dwell) moveSlide(1);

  // Charts are cached in the page, so drop them periodically to keep trends current.
  if (Date.now() - kiosk.historyClearedAt > 1800000) {
    state.history.clear();
    kiosk.historyClearedAt = Date.now();
    showSlide(kiosk.index);
  }

  // A screen left running for weeks benefits from an occasional clean start.
  const hours = (Date.now() - kiosk.startedAt) / 3600000;
  if (hours >= (state.reloadHours || 6)) location.reload();
}

function kioskRefreshed() {
  if (!kiosk.on) return;
  const current = kiosk.slides[kiosk.index];
  kiosk.slides = buildSlides();
  if (!kiosk.slides.length) return;
  // Stay on the same space across a data refresh.
  let idx = 0;
  if (current && current.type === "room") {
    idx = kiosk.slides.findIndex(s => s.type === "room" && s.room.serial === current.room.serial);
  } else if (current) {
    idx = kiosk.slides.findIndex(s => s.type === "floor" && s.floor === current.floor);
  }
  kiosk.index = idx < 0 ? 0 : idx;
  showSlide(kiosk.index);
}

function kioskAlert(message) {
  const box = el("kioskAlert");
  if (!message) { box.classList.remove("on"); box.textContent = ""; return; }
  box.textContent = message;
  box.classList.add("on");
}

el("btnDisplay").addEventListener("click", startDisplay);
document.addEventListener("keydown", ev => {
  if (!kiosk.on) return;
  if (ev.key === "Escape") { stopDisplay(); return; }
  if (ev.key === " ") {
    ev.preventDefault();
    kiosk.paused = !kiosk.paused;
    el("kiosk").classList.toggle("paused", kiosk.paused);
    showSlide(kiosk.index);
    return;
  }
  if (ev.key === "ArrowRight") moveSlide(1);
  if (ev.key === "ArrowLeft") moveSlide(-1);
  if (ev.key.toLowerCase() === "f" && document.documentElement.requestFullscreen) {
    document.documentElement.requestFullscreen().catch(() => {});
  }
});
setInterval(kioskTick, 250);

/* ---------------------------------------------------------------------------
 * Master render and events
 * ------------------------------------------------------------------------ */
function render() {
  const rooms = visibleRooms();
  renderChips(rooms);
  renderSummary(rooms);
  if (state.view === "rooms") renderRooms(rooms); else renderTable(rooms);
  el("clockTime").textContent = state.generated ? clockText(state.generated) : "--:--";
  const stale = rooms.filter(r => { const a = ageSeconds(r); return a === null || a > STALE_AFTER; }).length;
  el("liveDot").className = "dot" + (stale && stale === rooms.length ? " down" : stale ? " stale" : "");
}

function tick() {
  state.countdown--;
  if (state.countdown <= 0) { loadLatest(true); return; }
  el("clockNote").textContent = "Next refresh in " + state.countdown + " s";
}

document.addEventListener("click", ev => {
  const chip = ev.target.closest("[data-metric]");
  if (chip) {
    state.metric = chip.dataset.metric;
    render();
    if (state.view === "rooms" && state.sparklines) {
      document.querySelectorAll(".spark").forEach(n => { n.innerHTML = ""; });
      queueSparklines();
    }
  }
});

el("btnRefresh").addEventListener("click", () => { state.history.clear(); loadLatest(true); });
el("btnView").addEventListener("click", () => {
  state.view = state.view === "rooms" ? "table" : "rooms";
  el("btnView").textContent = state.view === "rooms" ? "Data table" : "Room cards";
  el("btnView").setAttribute("aria-pressed", String(state.view === "table"));
  render();
});
el("selLocation").addEventListener("change", ev => { state.location = ev.target.value; render(); });
el("txtSearch").addEventListener("input", ev => { state.search = ev.target.value; render(); });
el("chkAlerts").addEventListener("change", ev => { state.alertsOnly = ev.target.checked; render(); });
el("chkHubs").addEventListener("change", ev => { state.showHubs = ev.target.checked; render(); });
el("drawerClose").addEventListener("click", closeDrawer);
el("scrim").addEventListener("click", closeDrawer);
document.addEventListener("keydown", ev => {
  if (ev.key === "Escape") closeDrawer();
  if (ev.key === "Enter" && document.activeElement && document.activeElement.classList.contains("room")) {
    openDrawer(document.activeElement.dataset.serial);
  }
});

const params = new URLSearchParams(location.search);
if (params.has("dwell")) state.dwell = Math.max(4, Number(params.get("dwell")) || 14);

loadLatest(false).then(() => {
  // The screen this runs on should come up cycling without anyone touching it.
  const wanted = params.get("display");
  if (wanted === "1" || (wanted !== "0" && state.displayOnLoad)) startDisplay();
});
setInterval(tick, 1000);
</script>
</body>
</html>
