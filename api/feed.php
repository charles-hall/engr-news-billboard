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
function http_get(string $url, int $timeout, string $accept = 'application/json'): ?string
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
            CURLOPT_ENCODING       => '', // accept gzip; RSS feeds run to several MB
            CURLOPT_USERAGENT      => 'NCState-Billboard-News/1.0 (+https://brand.ncsu.edu)',
            CURLOPT_HTTPHEADER     => ['Accept: ' . $accept],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return ($body !== false && $code >= 200 && $code < 300) ? (string) $body : null;
    }

    $ctx = stream_context_create([
        'http' => [
            'timeout' => $timeout,
            'header'  => "Accept: " . $accept . "\r\nUser-Agent: NCState-Billboard-News/1.0\r\n",
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

if ($out === []) {
    $rssXml = http_get($base . '/feed/', (int) $config['http_timeout'], 'application/rss+xml, application/xml');

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
    // Both paths failed. Stale content beats an error card on a wall.
    if (is_readable($cacheFile) && $cacheAge < (int) $config['stale_ttl']) {
        $stale = json_decode((string) file_get_contents($cacheFile), true);
        if (is_array($stale)) {
            $stale['stale']    = true;
            $stale['cacheAge'] = $cacheAge;
            echo json_encode($stale, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    fail(502, 'Could not read posts from ' . $host . ' over REST or RSS, and no usable cache is available.');
}

/* -------------------------------------------------------------- site name */

$siteName = $site['label'] ?? null;

if ($siteName === null || $siteName === '') {
    if ($source === 'rest') {
        $rootRaw  = http_get($base . '/wp-json', (int) $config['http_timeout']);
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
        'key'  => $siteKey,
        'host' => $host,
        'name' => $siteName,
        'url'  => $base,
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

echo $json;
