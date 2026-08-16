<?php
/**
 * NC State Billboard News Slides - cached WordPress REST proxy
 *
 * GET api/feed.php?site=csc&count=5
 *
 * Returns normalized JSON:
 * {
 *   "site":      { "key", "host", "name", "url", "accent" },
 *   "generated": "2026-08-15T09:12:44-04:00",
 *   "cached":    true,
 *   "stale":     false,
 *   "posts": [ { "id","title","url","date","dateISO","excerpt","image","alt" } ]
 * }
 *
 * Why a proxy instead of fetching WordPress from the browser:
 *   - caches the feed so a hundred billboards do not hammer the department site
 *   - keeps showing the last good stories when that site is slow or down
 *   - immune to any future CORS or REST lockdown on the department site
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=60');

$config = require __DIR__ . '/../config.php';

require_once __DIR__ . '/lib.php';

/* ------------------------------------------------------------------ input */

$siteKey = isset($_GET['site']) ? strtolower(trim((string) $_GET['site'])) : $config['default_site'];
$siteKey = preg_replace('/[^a-z0-9_-]/', '', $siteKey) ?? '';

if (!isset($config['sites'][$siteKey])) {
    fail(400, 'Unknown site key. Add it to config.php first.');
}

$site  = $config['sites'][$siteKey];
$host  = strtolower($site['host']);

// Defense in depth: the allowlist already gates this, but never let anything
// that is not an ncsu.edu hostname reach the fetcher.
if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*\.ncsu\.edu$/', $host)) {
    fail(400, 'Configured host is not an ncsu.edu hostname.');
}

$count = isset($_GET['count']) ? (int) $_GET['count'] : (int) $config['default_count'];
$count = max(1, min(12, $count));

$requireImage = isset($_GET['require_image'])
    ? filter_var($_GET['require_image'], FILTER_VALIDATE_BOOLEAN)
    : (bool) $config['require_image'];

// Optional category or tag filter, by slug. Passed straight through to WP.
$category = isset($_GET['category']) ? preg_replace('/[^a-z0-9_-]/i', '', (string) $_GET['category']) : '';
$tag      = isset($_GET['tag'])      ? preg_replace('/[^a-z0-9_-]/i', '', (string) $_GET['tag'])      : '';

$refresh = isset($_GET['refresh']) && filter_var($_GET['refresh'], FILTER_VALIDATE_BOOLEAN);

$tightenDashes = (bool) ($config['tighten_dashes'] ?? true);
/*
 * A warm-up run has no display waiting on it, so it gets far longer to sit
 * through a cold origin. A request from a slide keeps the tight limits: on a
 * wall, late is the same as broken.
 */
$budget  = $refresh ? (int) ($config['warm_time_budget'] ?? 120) : (int) ($config['time_budget'] ?? 20);
$timeout = $refresh ? (int) ($config['warm_http_timeout'] ?? 45) : (int) $config['http_timeout'];

if ($refresh) {
    @set_time_limit($budget + 60);
}

upstream_user_agent((string) ($config['user_agent'] ?? ''));

/*
 * 'auto' tries REST then RSS. A site pinned to 'rss' or 'rest' skips the other,
 * which matters when one of them is known to fail: ECE answers every REST route
 * with 401, and on a cold cache those doomed attempts alone were enough to push
 * the request past the point where a display gives up waiting.
 */
$source_pref = strtolower((string) ($site['source'] ?? 'auto'));
if (!in_array($source_pref, ['auto', 'rest', 'rss'], true)) {
    $source_pref = 'auto';
}

/* ------------------------------------------------------------------ cache */

$cacheDir = (string) $config['cache_dir'];
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0775, true);
}

$cacheKey  = sha1(implode('|', [$host, $count, $requireImage ? 1 : 0, $category, $tag]));
$cacheFile = rtrim($cacheDir, '/') . '/feed-' . $siteKey . '-' . $cacheKey . '.json';

$cacheAge = (is_readable($cacheFile) && filemtime($cacheFile) !== false)
    ? time() - (int) filemtime($cacheFile)
    : PHP_INT_MAX;

$cached    = is_readable($cacheFile) ? @file_get_contents($cacheFile) : false;
$haveCache = ($cached !== false && $cached !== '');

// Fresh enough. Nothing else to do.
if (!$refresh && $haveCache && $cacheAge < (int) $config['cache_ttl']) {
    echo $cached;
    exit;
}

/*
 * Stale but usable. Serve it immediately, then refresh after the display has
 * already been answered.
 *
 * This matters more than it looks. These department sites sit behind
 * Cloudflare, and a cold response to the feed query takes close to thirty
 * seconds to build while a warm one takes under half a second. Without this, a
 * display unlucky enough to be the first request after a cache expiry would
 * hang for half a minute and show its error card.
 */
$alreadySent = false;

if (!$refresh && $haveCache && $cacheAge < (int) $config['stale_ttl']) {
    echo $cached;
    if (finish_request_and_continue()) {
        $alreadySent = true;
    } else {
        // Cannot detach from the client on this SAPI. Serve stale and let the
        // scheduled warm-up task handle refreshing; see the README.
        exit;
    }
}

/* ------------------------------------------------------------------ fetch */

/**
 * Parse a WordPress RSS feed into the same post shape the REST path produces.
 *
 * Some department sites put the REST API behind an authentication plugin or a
 * WAF rule, which answers every request with 401 or 403. Their RSS feed is
 * still public and the NC State theme puts the featured image, its alt text
 * and the excerpt right in <description>, so nothing is lost by reading it.
 *
 * Parsed with regex rather than SimpleXML: the structure is narrow and
 * predictable, the extension is not guaranteed on every Plesk PHP handler, and
 * it sidesteps XML entity expansion on a third-party document.
 */
function parse_rss(
    string $xml,
    int $count,
    bool $requireImage,
    string $timezone,
    bool $tightenDashes,
    int $excerptMax
): array {
    // These feeds carry every post the site has ever published, often several
    // megabytes. Only the leading items matter, so cut the string first.
    $chunks = explode('<item>', $xml, ($count * 4) + 2);
    array_shift($chunks); // channel header

    $out = [];
    foreach ($chunks as $chunk) {
        $item = explode('</item>', $chunk, 2)[0];

        $title = tag_text($item, 'title');
        $link  = tag_text($item, 'link');
        if ($title === '' || $link === '') {
            continue;
        }

        // The theme wraps the featured image in <div class="featured-img">.
        // Take the src attribute specifically, not the first srcset candidate,
        // which would hand back a downscaled copy.
        $image = null;
        $alt   = '';
        if (preg_match('/<img\b[^>]*>/i', $item, $imgTag)) {
            if (preg_match('/\ssrc="([^"]+)"/i', $imgTag[0], $m)) {
                $image = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            if (preg_match('/\salt="([^"]*)"/i', $imgTag[0], $m)) {
                $alt = plain_text($m[1], $tightenDashes);
            }
        }

        if ($requireImage && $image === null) {
            continue;
        }

        // Drop the image wrapper before reading the excerpt, or the alt text
        // would end up in the abstract.
        $body = preg_replace('/<div class="featured-img">.*?<\/div>/is', '', tag_raw($item, 'description') ?? '') ?? '';

        $out[] = [
            'id'      => 0,
            'title'   => plain_text($title, $tightenDashes),
            'url'     => $link,
            'dateISO' => rss_date_to_local(tag_text($item, 'pubDate'), $timezone),
            'excerpt' => trim_words(plain_text($body, $tightenDashes), $excerptMax),
            'image'   => $image,
            'alt'     => $alt,
        ];

        if (count($out) >= $count) {
            break;
        }
    }

    return $out;
}

/** Raw inner content of the first matching tag, CDATA unwrapped. */
function tag_raw(string $xml, string $tag): ?string
{
    if (!preg_match('/<' . $tag . '\b[^>]*>(.*?)<\/' . $tag . '>/is', $xml, $m)) {
        return null;
    }
    $inner = $m[1];
    if (preg_match('/<!\[CDATA\[(.*?)\]\]>/s', $inner, $c)) {
        return $c[1];
    }

    return $inner;
}

/** Decoded plain text of the first matching tag. */
function tag_text(string $xml, string $tag): string
{
    $raw = tag_raw($xml, $tag);

    return $raw === null ? '' : trim(html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

/**
 * WordPress always stamps RSS pubDate in GMT, while the REST API reports site
 * local time. Convert so both paths produce the same date for the same post.
 */
function rss_date_to_local(string $pubDate, string $timezone): string
{
    if ($pubDate === '') {
        return '';
    }
    try {
        $dt = new DateTimeImmutable($pubDate);
        $dt = $dt->setTimezone(new DateTimeZone($timezone));
    } catch (Exception $e) {
        return '';
    }

    return $dt->format('Y-m-d\TH:i:s');
}

$base  = 'https://' . $host;
$query = [
    /*
     * Overfetch, then filter. Thirty is deep enough for departments that post
     * plenty of stories without a featured image: only five of MSE's thirty
     * most recent carry one, and a shallow window left that slide nearly empty.
     *
     * Deliberately a constant rather than a function of $count. These sites sit
     * behind Cloudflare, and a cold response for this query takes close to
     * thirty seconds to build while a warm one takes under half a second.
     * Varying per_page would create a new cache key per count value and hand
     * some unlucky display the cold path.
     */
    'per_page' => $requireImage ? 30 : $count,
    'orderby'  => 'date',
    'order'    => 'desc',
    'status'   => 'publish',
    '_embed'   => 'wp:featuredmedia',
    '_fields'  => 'id,date,link,title,excerpt,featured_media,_links,_embedded',
];
if ($category !== '') {
    $query['categories_slug'] = $category; // ignored by core WP; harmless
    $query['category_name']   = $category;
}
if ($tag !== '') {
    $query['tag'] = $tag;
}

$posts = null;

if ($source_pref !== 'rss') {
    $restUrl  = $base . '/wp-json/wp/v2/posts?' . http_build_query($query);
    $postsRaw = http_get($restUrl, min($timeout, time_left($budget)));

    /*
     * A department site with a cold CDN cache can miss the first request and
     * answer the second one instantly, so one retry is worth it. But not when
     * the first attempt timed out: a host that is not answering will not answer
     * the retry either, and the second full timeout is time the RSS fallback
     * needs. ece.ncsu.edu spent 119 seconds this way, three doomed attempts
     * deep, before giving up.
     */
    if ($postsRaw === null && last_fetch_failure() !== 'timeout' && time_left($budget) > 3) {
        $postsRaw = http_get($restUrl, min($timeout, time_left($budget)));
    }

    $posts = $postsRaw !== null ? json_decode($postsRaw, true) : null;
}

/* -------------------------------------------------------------- normalize */

$source = 'rest';
$out    = [];

foreach (is_array($posts) ? $posts : [] as $post) {
    if (!is_array($post)) {
        continue;
    }

    $media = $post['_embedded']['wp:featuredmedia'][0] ?? null;
    $image = null;
    $alt   = '';

    if (is_array($media) && empty($media['code'])) {
        $sizes = $media['media_details']['sizes'] ?? [];
        // Prefer a size wide enough for a 1080px-tall panel, else the original.
        foreach (['2048x2048', '1536x1536', 'large', 'full'] as $preferred) {
            if (!empty($sizes[$preferred]['source_url'])) {
                $image = (string) $sizes[$preferred]['source_url'];
                break;
            }
        }
        if ($image === null && !empty($media['source_url'])) {
            $image = (string) $media['source_url'];
        }
        $alt = plain_text((string) ($media['alt_text'] ?? ''), $tightenDashes);
    }

    if ($requireImage && $image === null) {
        continue;
    }

    $title = plain_text((string) ($post['title']['rendered'] ?? ''), $tightenDashes);
    if ($title === '') {
        continue;
    }

    $out[] = [
        'id'      => (int) ($post['id'] ?? 0),
        'title'   => $title,
        'url'     => (string) ($post['link'] ?? ''),
        'dateISO' => (string) ($post['date'] ?? ''),
        'excerpt' => trim_words(plain_text((string) ($post['excerpt']['rendered'] ?? ''), $tightenDashes), (int) $config['excerpt_max']),
        'image'   => $image,
        'alt'     => $alt,
    ];

    if (count($out) >= $count) {
        break;
    }
}

/* ----------------------------------------------------------- RSS fallback */

$rssXml = null;

if ($out === [] && $source_pref !== 'rest') {
    $rssXml = http_get($base . '/feed/', min($timeout, time_left($budget)), 'application/rss+xml, application/xml');

    if ($rssXml !== null) {
        $out = parse_rss(
            $rssXml,
            $count,
            $requireImage,
            (string) ($config['timezone'] ?? 'America/New_York'),
            $tightenDashes,
            (int) $config['excerpt_max']
        );
        if ($out !== []) {
            $source = 'rss';
        }
    }
}

if ($out === []) {
    // Both paths failed. If stale content already went out, the display is
    // showing real stories and there is nothing more to say.
    if ($alreadySent) {
        exit;
    }
    // Otherwise stale content still beats an error card on a wall.
    serve_stale_or_fail(
        $cacheFile,
        $cacheAge,
        (int) $config['stale_ttl'],
        'Could not read posts from ' . $host . ' over REST or RSS.'
    );
}

/* -------------------------------------------------------------- site name */

$siteName = $site['label'] ?? null;

if ($siteName === null || $siteName === '') {
    if ($source === 'rest') {
        $rootRaw  = http_get($base . '/wp-json', min(5, time_left($budget)));
        $root     = $rootRaw !== null ? json_decode($rootRaw, true) : null;
        $siteName = is_array($root) && !empty($root['name']) ? (string) $root['name'] : $host;
    } else {
        // The channel title sits before the first <item>, so this is cheap.
        $channel  = explode('<item>', (string) $rssXml, 2)[0];
        $chanName = tag_text($channel, 'title');
        $siteName = $chanName !== '' ? $chanName : $host;
    }
}

$payload = [
    'site' => [
        'key'    => $siteKey,
        'host'   => $host,
        'name'   => $siteName,
        'url'    => $base,
        // Falls through to null when a department has no accent configured;
        // slide.js then leaves the CSS default (Wolfpack Red) in place.
        'accent' => $site['accent'] ?? null,
    ],
    'generated' => date('c'),
    'source'    => $source,
    'cached'    => false,
    'stale'     => false,
    'posts'     => $out,
];

$json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// Write atomically so a concurrent request never reads a half-written file.
if (is_dir($cacheDir) && is_writable($cacheDir)) {
    $tmp = $cacheFile . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $json) !== false) {
        @rename($tmp, $cacheFile);
    }
}

if (!$alreadySent) {
    echo $json;
}
