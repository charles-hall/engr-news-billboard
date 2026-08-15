<?php
/**
 * NC State Billboard News Slides - cached WordPress REST proxy
 *
 * GET api/feed.php?site=csc&count=5
 *
 * Returns normalized JSON:
 * {
 *   "site":      { "key", "host", "name", "url" },
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

/** Emit an error payload and stop. */
function fail(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}

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

if (!$refresh && $cacheAge < (int) $config['cache_ttl']) {
    $cached = @file_get_contents($cacheFile);
    if ($cached !== false && $cached !== '') {
        echo $cached;
        exit;
    }
}

/* ------------------------------------------------------------------ fetch */

/**
 * Fetch a URL. Uses cURL when present, falls back to the stream wrapper.
 * Returns the body string, or null on any failure.
 */
function http_get(string $url, int $timeout): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'NCState-Billboard-News/1.0 (+https://brand.ncsu.edu)',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return ($body !== false && $code >= 200 && $code < 300) ? (string) $body : null;
    }

    $ctx = stream_context_create([
        'http' => [
            'timeout' => $timeout,
            'header'  => "Accept: application/json\r\nUser-Agent: NCState-Billboard-News/1.0\r\n",
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);

    return $body !== false ? (string) $body : null;
}

/** Turn rendered WordPress HTML into clean single-line plain text. */
function plain_text(string $html, bool $tightenDashes = true): string
{
    $html = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $html) ?? $html;
    $text = strip_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace(["\xC2\xA0", "\xE2\x80\xA6"], [' ', '...'], $text);
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    // WordPress commonly appends "[...]" or "Continue reading ..." to excerpts.
    $text = preg_replace('/\s*(\[(\.\.\.|&hellip;|…)\]|Continue reading.*)$/iu', '', $text) ?? $text;

    // College of Engineering house style: no space on either side of an em or
    // en dash. Purely typographic, the wording of the source is untouched.
    if ($tightenDashes) {
        $text = preg_replace('/\s*([\x{2014}\x{2013}])\s*/u', '$1', $text) ?? $text;
    }

    return trim($text);
}

/** Trim to a character budget on a word boundary, adding an ellipsis. */
function trim_words(string $text, int $max): string
{
    if (mb_strlen($text) <= $max) {
        return $text;
    }
    $cut   = mb_substr($text, 0, $max);
    $space = mb_strrpos($cut, ' ');
    if ($space !== false && $space > (int) ($max * 0.6)) {
        $cut = mb_substr($cut, 0, $space);
    }

    return rtrim($cut, " ,;:.") . '...';
}

$base  = 'https://' . $host;
$query = [
    'per_page' => $requireImage ? min(20, $count * 3) : $count, // overfetch, then filter
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

$postsRaw = http_get($base . '/wp-json/wp/v2/posts?' . http_build_query($query), (int) $config['http_timeout']);
$posts    = $postsRaw !== null ? json_decode($postsRaw, true) : null;

if (!is_array($posts)) {
    // Upstream failed. Serve stale cache rather than an empty billboard.
    if (is_readable($cacheFile) && $cacheAge < (int) $config['stale_ttl']) {
        $stale = json_decode((string) file_get_contents($cacheFile), true);
        if (is_array($stale)) {
            $stale['stale']     = true;
            $stale['cacheAge']  = $cacheAge;
            echo json_encode($stale, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    fail(502, 'Could not reach ' . $host . ' and no usable cache is available.');
}

/* -------------------------------------------------------------- site name */

$siteName = $site['label'] ?? null;
if ($siteName === null || $siteName === '') {
    $rootRaw  = http_get($base . '/wp-json', (int) $config['http_timeout']);
    $root     = $rootRaw !== null ? json_decode($rootRaw, true) : null;
    $siteName = is_array($root) && !empty($root['name']) ? (string) $root['name'] : $host;
}

/* -------------------------------------------------------------- normalize */

$out = [];
foreach ($posts as $post) {
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

if ($out === []) {
    if (is_readable($cacheFile) && $cacheAge < (int) $config['stale_ttl']) {
        readfile($cacheFile);
        exit;
    }
    fail(404, 'No usable posts found on ' . $host . '.');
}

$payload = [
    'site' => [
        'key'  => $siteKey,
        'host' => $host,
        'name' => $siteName,
        'url'  => $base,
    ],
    'generated' => date('c'),
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

echo $json;
