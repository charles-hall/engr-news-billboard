<?php
/**
 * NC State Billboard News Slides - upcoming events proxy
 *
 * GET api/events.php?count=6
 *
 * Reads calendar.ncsu.edu, keeps events on Centennial Campus, and returns:
 *
 * {
 *   "window": { "days": 21, "from": "...", "to": "..." },
 *   "events": [ { "id","title","url","startISO","endISO","allDay","venue","room",
 *                 "type","typeColor","image","excerpt" } ]
 * }
 *
 * image and excerpt (Localist's photo_url and description_text) feed the
 * cycling variant of this slide, events-cycle.html, which mirrors the
 * one-story-at-a-time layout of the news slide. The agenda slide (events.html)
 * ignores both fields.
 *
 * typeColor is looked up from config.php's events.type_colors by event type
 * name and used by both slides for a bit of brand-palette variety: the date
 * chip on events.html, the eyebrow/bar on events-cycle.html. null when the
 * type has no configured color, which both slides treat as Wolfpack Red.
 *
 * The calendar runs Localist, whose public API needs no key or account.
 *
 * Filtering to one campus is the interesting part. Localist has a campus
 * concept, but this calendar does not use it: campus_id is null on every event
 * and there is no campus filter set to query. Venue coordinates, on the other
 * hand, are populated on nearly every event, so the campus is defined here as a
 * bounding box in config.php. See the note there for how it was calibrated.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=300');

$config = require __DIR__ . '/../config.php';

require_once __DIR__ . '/lib.php';

$ev = $config['events'] ?? [];

/* ------------------------------------------------------------------ input */

$host = strtolower((string) ($ev['host'] ?? 'calendar.ncsu.edu'));
if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*\.ncsu\.edu$/', $host)) {
    fail(400, 'Configured events host is not an ncsu.edu hostname.');
}

$count = isset($_GET['count']) ? (int) $_GET['count'] : 6;
$count = max(1, min(12, $count));

$days = isset($_GET['days']) ? (int) $_GET['days'] : (int) ($ev['days'] ?? 21);
$days = max(1, min(120, $days));

$refresh = isset($_GET['refresh']) && filter_var($_GET['refresh'], FILTER_VALIDATE_BOOLEAN);

upstream_user_agent((string) ($config['user_agent'] ?? ''));

// A warm-up run has no display waiting on it; see the note in feed.php.
$budget  = $refresh ? (int) ($config['warm_time_budget'] ?? 120) : (int) ($config['time_budget'] ?? 20);
$timeout = $refresh ? (int) ($config['warm_http_timeout'] ?? 45) : (int) $config['http_timeout'];

if ($refresh) {
    @set_time_limit($budget + 60);
}

$tz = new DateTimeZone((string) ($config['timezone'] ?? 'America/New_York'));

/* ------------------------------------------------------------------ cache */

$cacheDir = (string) $config['cache_dir'];
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0775, true);
}

// Keyed on the window only. As with the Instagram feed, one cache holds the
// full set and is sliced at serve time, so every count shares a warm cache.
$cacheFile = rtrim($cacheDir, '/') . '/events-' . sha1($host . '|' . $days) . '.json';
$cacheAge  = (is_readable($cacheFile) && filemtime($cacheFile) !== false)
    ? time() - (int) filemtime($cacheFile)
    : PHP_INT_MAX;

/** Emit a cached payload cut down to the requested number of events. */
function serve_events(string $json, int $count, bool $stale = false, int $age = 0): void
{
    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['events'])) {
        echo $json;
        exit;
    }
    if ($stale) {
        $data['stale']    = true;
        $data['cacheAge'] = $age;
    }
    $data['events'] = array_slice($data['events'], 0, $count);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$cached    = is_readable($cacheFile) ? @file_get_contents($cacheFile) : false;
$haveCache = ($cached !== false && $cached !== '');
$ttl       = (int) ($ev['cache_ttl'] ?? 1800);

if (!$refresh && $haveCache && $cacheAge < $ttl) {
    serve_events($cached, $count);
}

/*
 * Stale but usable: answer with it now, refresh afterwards. Same reasoning as
 * the news and Instagram feeds.
 *
 * An extra wrinkle here: events go out of date on their own. A cached list is
 * re-filtered against the clock below before it is served, so an event that has
 * already started never lingers on the wall just because the refresh failed.
 */
$alreadySent = false;

if (!$refresh && $haveCache && $cacheAge < (int) $config['stale_ttl']) {
    $data = json_decode($cached, true);
    if (is_array($data) && isset($data['events'])) {
        $now = (new DateTimeImmutable('now', $tz))->format('c');
        $data['events'] = array_values(array_filter(
            $data['events'],
            static function (array $e) use ($now): bool {
                return ($e['endISO'] ?? $e['startISO'] ?? '') >= $now;
            }
        ));

        if ($data['events'] !== []) {
            $out = $data;
            $out['stale']    = true;
            $out['cacheAge'] = $cacheAge;
            $out['events']   = array_slice($out['events'], 0, $count);
            echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if (finish_request_and_continue()) {
                $alreadySent = true;
            } else {
                exit;
            }
        }
    }
}

/* ------------------------------------------------------------------ fetch */

/*
 * Localist paginates. Ask for a generous page: after dropping other campuses
 * and the excluded types, a page of 100 over three weeks yields far more than a
 * slide needs, so one request is enough and the API is left alone.
 */
$query = http_build_query([
    'days'     => $days,
    'pp'       => 100,
    'distinct' => 'true', // collapse recurring series to one entry each
]);

$raw  = http_get('https://' . $host . '/api/2/events?' . $query, min($timeout, time_left($budget)));
$data = $raw !== null ? json_decode($raw, true) : null;

if (!is_array($data) || !isset($data['events'])) {
    if ($alreadySent) {
        exit;
    }
    serve_stale_or_fail(
        $cacheFile,
        $cacheAge,
        (int) $config['stale_ttl'],
        'Could not read the events API at ' . $host . '.'
    );
}

/* ----------------------------------------------------------------- filter */

$bounds  = $ev['bounds'] ?? ['south' => -90, 'north' => 90, 'west' => -180, 'east' => 180];
$exclude = array_map('strtolower', (array) ($ev['exclude_types'] ?? []));
$now     = new DateTimeImmutable('now', $tz);

/** Is this venue inside the configured campus box? */
function within_bounds(?array $geo, array $b): bool
{
    if (!$geo || !isset($geo['latitude'], $geo['longitude'])) {
        return false;
    }
    $lat = (float) $geo['latitude'];
    $lon = (float) $geo['longitude'];

    return $lat >= $b['south'] && $lat <= $b['north']
        && $lon >= $b['west'] && $lon <= $b['east'];
}

$out = [];

foreach ($data['events'] as $wrapper) {
    $e = $wrapper['event'] ?? null;
    if (!is_array($e)) {
        continue;
    }

    if (!within_bounds($e['geo'] ?? null, $bounds)) {
        continue;
    }

    $types = array_map(
        static fn (array $t): string => strtolower((string) ($t['name'] ?? '')),
        (array) (($e['filters'] ?? [])['event_types'] ?? [])
    );
    if (array_intersect($types, $exclude) !== []) {
        continue;
    }

    /*
     * Take the next instance that has not finished, not the first one listed.
     * A weekly series returns every occurrence, and the earliest is often in
     * the past, which would put a stale date on the wall.
     */
    $instance = null;
    foreach ((array) ($e['event_instances'] ?? []) as $wrap) {
        $i = $wrap['event_instance'] ?? null;
        if (!is_array($i) || empty($i['start'])) {
            continue;
        }
        $ends = $i['end'] ?? $i['start'];
        if ($ends >= $now->format('c') && ($instance === null || $i['start'] < $instance['start'])) {
            $instance = $i;
        }
    }
    if ($instance === null) {
        continue;
    }

    $title = plain_text((string) ($e['title'] ?? ''), (bool) ($config['tighten_dashes'] ?? true));
    if ($title === '') {
        continue;
    }

    $tighten = (bool) ($config['tighten_dashes'] ?? true);

    // description_text arrives pre-stripped of markup, but still runs through
    // plain_text() for entity decoding, whitespace collapse and the same
    // dash-tightening house rule applied to news excerpts.
    $excerpt = plain_text((string) ($e['description_text'] ?? ''), $tighten);
    $excerpt = trim_words($excerpt, (int) ($config['excerpt_max'] ?? 260));

    $type = (string) ((($e['filters'] ?? [])['event_types'][0] ?? [])['name'] ?? '');
    $typeColors = $ev['type_colors'] ?? [];

    $out[] = [
        'id'        => (int) ($e['id'] ?? 0),
        'title'     => $title,
        'url'       => (string) ($e['localist_url'] ?? ''),
        'startISO'  => (string) $instance['start'],
        'endISO'    => (string) ($instance['end'] ?? $instance['start']),
        'allDay'    => (bool) ($instance['all_day'] ?? false),
        // Venue names are proper names, so the dash-tightening house rule does
        // not apply: "Partners I – NC State University" should not become
        // "Partners I–NC State University".
        'venue'     => plain_text((string) ($e['location_name'] ?? ''), false),
        'room'      => plain_text((string) ($e['room_number'] ?? ''), false),
        'type'      => $type,
        'typeColor' => $typeColors[$type] ?? null,
        // Localist's "huge" size is a large fixed-width JPEG served from a
        // stable, unsigned CDN path (localist-images.azureedge.net) -- unlike
        // Instagram's signed URLs, these do not expire, so no mirroring is
        // needed here the way api/image.php mirrors Instagram photos.
        'image'     => (string) ($e['photo_url'] ?? ''),
        'excerpt'   => $excerpt,
    ];
}

usort($out, static fn (array $a, array $b): int => strcmp($a['startISO'], $b['startISO']));
$out = array_slice($out, 0, (int) ($ev['limit'] ?? 12));

if ($out === []) {
    if ($alreadySent) {
        exit;
    }
    serve_stale_or_fail(
        $cacheFile,
        $cacheAge,
        (int) $config['stale_ttl'],
        sprintf(
            'No events matched. Read %d events from %s over %d days; none were inside the campus bounds '
            . 'after excluding %s.',
            count($data['events']),
            $host,
            $days,
            $exclude === [] ? 'nothing' : implode(' and ', (array) ($ev['exclude_types'] ?? []))
        )
    );
}

/* ---------------------------------------------------------------- respond */

$payload = [
    'source' => [
        'host' => $host,
        'url'  => 'https://' . $host . '/',
        'name' => 'NC State University',
    ],
    'window' => [
        'days' => $days,
        'from' => $now->format('c'),
        'to'   => $now->modify('+' . $days . ' days')->format('c'),
    ],
    'generated' => date('c'),
    'cached'    => false,
    'stale'     => false,
    'events'    => $out,
];

$json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

if (is_dir($cacheDir) && is_writable($cacheDir)) {
    $tmp = $cacheFile . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $json) !== false) {
        @rename($tmp, $cacheFile);
    }
}

if (!$alreadySent) {
    $payload['events'] = array_slice($payload['events'], 0, $count);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
