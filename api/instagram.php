<?php
/**
 * NC State Billboard News Slides - Instagram feed proxy
 *
 * GET api/instagram.php?site=csc&count=4
 *
 * Reads the Instagram feed the Smash Balloon plugin already renders on the
 * department's own WordPress site and normalizes it to JSON:
 *
 * {
 *   "site":   { "key", "host", "handle", "profile" },
 *   "posts":  [ { "id","url","caption","dateISO","image","width" } ]
 * }
 *
 * Why read the department's own page instead of calling Meta:
 *   - the plugin already holds the credentials and refreshes its own token.
 *     A direct integration would need a Meta app, App Review, and a 60-day
 *     token refresh that has to keep working unattended for years. A billboard
 *     that goes blank two months after launch is worse than no billboard.
 *   - nothing new to maintain when the department rotates staff or accounts
 *
 * The tradeoff is that this parses Smash Balloon's markup, so a major plugin
 * update could change it. That failure is soft: the proxy serves its last good
 * cache, and the news slide is unaffected.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=120');

$config = require __DIR__ . '/../config.php';

require_once __DIR__ . '/lib.php';

/* ------------------------------------------------------------------ input */

$siteKey = isset($_GET['site']) ? strtolower(trim((string) $_GET['site'])) : $config['default_site'];
$siteKey = preg_replace('/[^a-z0-9_-]/', '', $siteKey) ?? '';

if (!isset($config['sites'][$siteKey])) {
    fail(400, 'Unknown site key. Add it to config.php first.');
}
if (!isset($config['instagram'][$siteKey])) {
    fail(400, 'No Instagram settings for "' . $siteKey . '". Add it to the instagram array in config.php.');
}

$site   = $config['sites'][$siteKey];
$ig     = $config['instagram'][$siteKey];
$host   = strtolower($site['host']);
$handle = preg_replace('/[^A-Za-z0-9._]/', '', (string) $ig['handle']) ?? '';

if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*\.ncsu\.edu$/', $host)) {
    fail(400, 'Configured host is not an ncsu.edu hostname.');
}

// Only a path, never a full URL: the source page is always on the allowlisted
// department host.
$path = '/' . ltrim((string) ($ig['path'] ?? '/'), '/');
if (preg_match('#[^A-Za-z0-9/._~-]#', $path)) {
    fail(400, 'Configured Instagram path contains unexpected characters.');
}

$count   = isset($_GET['count']) ? (int) $_GET['count'] : 4;
$count   = max(1, min(12, $count));
$refresh = isset($_GET['refresh']) && filter_var($_GET['refresh'], FILTER_VALIDATE_BOOLEAN);

$base = 'https://' . $host;

upstream_user_agent((string) ($config['user_agent'] ?? ''));
// A warm-up run has no display waiting on it; see the note in feed.php.
$budget  = $refresh ? (int) ($config['warm_time_budget'] ?? 120) : (int) ($config['time_budget'] ?? 20);
$timeout = $refresh ? (int) ($config['warm_http_timeout'] ?? 45) : (int) $config['http_timeout'];

if ($refresh) {
    @set_time_limit($budget + 60);
}

/* ------------------------------------------------------------------ cache */

$cacheDir = (string) $config['cache_dir'];
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0775, true);
}

/*
 * Deliberately keyed on the source page alone, not on $count.
 *
 * Including the count gave every count value its own cache. The scheduled
 * warm-up warms one of them, and a slide asking for any other count silently
 * fell through to the slow path: fetching a half-megabyte page and four
 * Instagram images while a display waited. The full set is cached once and
 * sliced at serve time instead.
 */
$cacheFile = rtrim($cacheDir, '/') . '/ig-' . $siteKey . '-' . sha1($path) . '.json';

// Always parse the full set so one cache serves every count.
$fetchCount = 12;
$cacheAge  = (is_readable($cacheFile) && filemtime($cacheFile) !== false)
    ? time() - (int) filemtime($cacheFile)
    : PHP_INT_MAX;

/** Emit a cached payload cut down to the requested number of posts. */
function serve_sliced(string $json, int $count): void
{
    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['posts'])) {
        echo $json; // unexpected shape; better to pass it through than to drop it
        exit;
    }
    $data['posts'] = array_slice($data['posts'], 0, $count);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$cached    = is_readable($cacheFile) ? @file_get_contents($cacheFile) : false;
$haveCache = ($cached !== false && $cached !== '');

// Fresh enough.
if (!$refresh && $haveCache && $cacheAge < (int) $config['instagram_cache_ttl']) {
    serve_sliced($cached, $count);
}

/*
 * Stale but usable: answer with it now, refresh afterwards.
 *
 * ece.ncsu.edu is intermittently slow to serve its homepage, taking twelve
 * seconds and timing out where it usually takes one. Without this, whichever
 * display happened to be first after a cache expiry would wear that wait and
 * show its error card.
 */
$alreadySent = false;

if (!$refresh && $haveCache && $cacheAge < (int) $config['stale_ttl']) {
    $data = json_decode($cached, true);
    if (is_array($data) && isset($data['posts'])) {
        $data['stale']    = true;
        $data['cacheAge'] = $cacheAge;
        $out = $data;
        $out['posts'] = array_slice($out['posts'], 0, $count);
        echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (finish_request_and_continue()) {
            $alreadySent = true;
        } else {
            exit; // cannot detach; the scheduled warm-up will refresh it
        }
    }
}

/* ------------------------------------------------------------------ fetch */

$htmlDoc = http_get($base . $path, min($timeout, time_left($budget)), 'text/html');

if ($htmlDoc === null && $alreadySent) {
    exit; // the display already has real posts; nothing more to say
}

if ($htmlDoc === null && isset($_GET['debug'])) {
    // Same reasoning as below: answer 200 so the diagnosis is readable.
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'host'          => $host,
        'path'          => $path,
        'fetched_url'   => $base . $path,
        'fetched_bytes' => 0,
        'result'        => 'fetch failed: this server could not load that page',
        'hint'          => 'Check outbound HTTPS from this server, and whether the site treats it '
                         . 'differently from a browser. Compare with: curl -sI ' . $base . $path,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($htmlDoc === null) {
    serve_stale_or_fail(
        $cacheFile,
        $cacheAge,
        (int) $config['stale_ttl'],
        'Could not load https://' . $host . $path . ' from this server. It may be blocked, slow, or refusing this host.'
    );
}

/* ------------------------------------------------------------------ parse */

/**
 * Strip trailing hashtag blocks and "link in bio" tails from a caption.
 *
 * Instagram captions usually end with a wall of hashtags that reads as noise
 * on a wall. Hashtags inside a sentence are left alone, because removing
 * "#NCStateCS" from "Congrats to #NCStateCS student ..." breaks the sentence.
 */
function clean_caption(string $text): string
{
    // Remove a run of hashtags (and any joining whitespace) at the very end.
    $text = preg_replace('/(?:\s*#[\p{L}\p{N}_]+)+\s*$/u', '', $text) ?? $text;
    // Remove a trailing call to action that only makes sense inside the app.
    $text = preg_replace('/\s*(?:link in bio|🔗\s*in bio)\.?\s*$/iu', '', $text) ?? $text;

    return trim($text, " \t\n\r\0\x0B-–—:;,");
}

/**
 * Pull posts out of the markup Smash Balloon renders.
 *
 * Blocks are split with explode() rather than one big lazy regex across the
 * whole document. These department homepages run to half a megabyte, and a
 * pattern like `(.*?)` spanning that much text is at the mercy of whatever
 * pcre.backtrack_limit the host happens to set: it returns false rather than
 * no-match, which looks identical to "this site has no Instagram feed."
 *
 * If the expected wrapper is missing entirely, the fallback scan below still
 * recovers the images, so a plugin markup change degrades instead of failing.
 */
function parse_smashballoon(string $html, int $count, bool $cleanCaptions, int $captionMax, bool $tightenDashes): array
{
    $blocks = explode('class="sbi_item', $html);
    array_shift($blocks); // everything before the first post

    $out = [];
    foreach ($blocks as $block) {
        // Trim to this post: whichever end marker comes first.
        foreach (['class="sbi_item', '<div id="sbi_load'] as $stop) {
            $at = strpos($block, $stop);
            if ($at !== false) {
                $block = substr($block, 0, $at);
            }
        }

        $post = post_from_block($block, $cleanCaptions, $captionMax, $tightenDashes);
        if ($post !== null) {
            $out[] = $post;
        }
        if (count($out) >= $count) {
            return $out;
        }
    }

    if ($out !== []) {
        return $out;
    }

    /*
     * Fallback: the wrapper markup is not what we expect, but the images are
     * still in the page. Recover what we can rather than showing nothing.
     */
    if (preg_match_all('/data-full-res="([^"]+)"/i', $html, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[1] as $i => $hit) {
            // Look at the markup immediately around each image for its id,
            // date, permalink and caption.
            $from  = max(0, $hit[1] - 4000);
            $slice = substr($html, $from, 9000);

            $post = post_from_block($slice, $cleanCaptions, $captionMax, $tightenDashes);
            if ($post !== null) {
                $post['image'] = html_entity_decode($hit[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $out[] = $post;
            }
            if (count($out) >= $count) {
                break;
            }
        }
    }

    return $out;
}

/** Read one post out of a chunk of markup, or null if there is no image in it. */
function post_from_block(string $block, bool $cleanCaptions, int $captionMax, bool $tightenDashes): ?array
{
    // The rendered <img> is a lazy-load placeholder; the real image lives in
    // data-full-res on the anchor.
    if (!preg_match('/data-full-res="([^"]+)"/i', $block, $m)) {
        return null;
    }
    $image = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Only ever mirror images from Instagram's own CDN.
    if (!preg_match('#^https://[a-z0-9.-]+\.(?:cdninstagram\.com|fbcdn\.net)/#i', $image)) {
        return null;
    }

    $id = preg_match('/id="sbi_([0-9]+)"/', $block, $m) ? $m[1] : sha1($image);
    $url = preg_match('#href="(https://www\.instagram\.com/(?:p|reel)/[^"]+)"#', $block, $m)
        ? html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')
        : '';
    $ts = preg_match('/data-date="([0-9]+)"/', $block, $m) ? (int) $m[1] : 0;

    $caption = '';
    if (preg_match('/class="sbi_caption"[^>]*>(.*?)<\/span>/s', $block, $m)) {
        $caption = plain_text($m[1], $tightenDashes);
        if ($cleanCaptions) {
            $caption = clean_caption($caption);
        }
        $caption = trim_words($caption, $captionMax);
    }

    return [
        'id'      => $id,
        'url'     => $url,
        'caption' => $caption,
        'dateISO' => $ts > 0 ? gmdate('Y-m-d\TH:i:s\Z', $ts) : '',
        'image'   => $image,
    ];
}

$posts = parse_smashballoon(
    $htmlDoc,
    $fetchCount,
    (bool) $config['clean_captions'],
    (int) $config['caption_max'],
    (bool) ($config['tighten_dashes'] ?? true)
);

/*
 * ?debug=1 reports what the parser saw, and reports it BEFORE the failure check
 * so it still answers when the thing being debugged is the failure. It returns
 * 200 deliberately: a 502 body is unreadable to most tooling, which is exactly
 * the position this is meant to get you out of.
 */
if (isset($_GET['debug'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'host'           => $host,
        'path'           => $path,
        'fetched_url'    => $base . $path,
        'fetched_bytes'  => strlen($htmlDoc),
        'sbi_item'       => substr_count($htmlDoc, 'class="sbi_item'),
        'data_full_res'  => substr_count($htmlDoc, 'data-full-res="'),
        'plugin_present' => strpos($htmlDoc, 'plugins/instagram-feed') !== false,
        'posts_parsed'   => count($posts),
        'captions'       => array_map(static fn ($p) => mb_substr($p['caption'], 0, 60), $posts),
        // The page's own <title> and opening text, so a WAF challenge or a
        // redirect notice is visible rather than merely implied by a byte
        // count. Scripts are stripped first or this is all inline jQuery.
        'page_title'     => preg_match('/<title[^>]*>(.*?)<\/title>/is', $htmlDoc, $t)
            ? trim(html_entity_decode(strip_tags($t[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'))
            : '(none)',
        'head'           => mb_substr(plain_text(
            preg_replace('/<(script|style|noscript)\b[^>]*>.*?<\/\1>/is', ' ', mb_substr($htmlDoc, 0, 60000)) ?? ''
        ), 0, 300),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

/*
 * When this fails it fails on someone else's server, where none of the usual
 * tools are to hand, so the error carries what was actually seen: how much
 * markup came back and which Smash Balloon markers were in it. "No posts found"
 * on its own cannot distinguish a blocked fetch from a markup change.
 */
if ($posts === [] && $alreadySent) {
    exit;
}

if ($posts === []) {
    $diag = sprintf(
        'Fetched %s bytes from %s%s. Markers seen: sbi_item=%d, data-full-res=%d, instagram-feed plugin=%s.',
        number_format(strlen($htmlDoc)),
        $host,
        $path,
        substr_count($htmlDoc, 'class="sbi_item'),
        substr_count($htmlDoc, 'data-full-res="'),
        strpos($htmlDoc, 'plugins/instagram-feed') !== false ? 'yes' : 'no'
    );

    serve_stale_or_fail(
        $cacheFile,
        $cacheAge,
        (int) $config['stale_ttl'],
        'Found no Instagram posts. ' . $diag
    );
}


/* ---------------------------------------------------------------- respond */

$payload = [
    'site' => [
        'key'     => $siteKey,
        'host'    => $host,
        'label'   => $site['label'] ?? null,
        'handle'  => $handle,
        'profile' => 'https://www.instagram.com/' . $handle . '/',
    ],
    'generated' => date('c'),
    'cached'    => false,
    'stale'     => false,
    'posts'     => $posts,
];

/** Write the payload to the cache, atomically. */
function write_cache(string $cacheFile, array $payload): string
{
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $dir  = dirname($cacheFile);
    if (is_dir($dir) && is_writable($dir)) {
        $tmp = $cacheFile . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $json) !== false) {
            @rename($tmp, $cacheFile);
        }
    }

    return $json;
}

$json = write_cache($cacheFile, $payload);

if (!$alreadySent) {
    $sliced = $payload;
    $sliced['posts'] = array_slice($sliced['posts'], 0, $count);
    echo json_encode($sliced, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/* --------------------------------------------------------- mirror images */

/*
 * Everything below runs after the response has gone out.
 *
 * Instagram CDN URLs are signed and expire, so images are copied onto this
 * server and served through api/image.php. But four images can be several
 * megabytes, and making a display wait for that download before it sees
 * anything is how a slide ends up timing out. The first request after a cache
 * expiry therefore answers with CDN URLs, which work fine for hours, and the
 * mirrored copies are picked up from the rewritten cache on the next request.
 */
if (!(bool) $config['instagram_mirror_images']) {
    exit;
}

/*
 * Mirroring must not block a display. Two ways that is guaranteed:
 * the response has already been flushed and the client released, or this is
 * the scheduled warm-up calling with refresh=1, where nothing is waiting.
 *
 * The second case matters on hosts without fastcgi_finish_request, where
 * detaching is impossible. Without it, those hosts would never mirror at all
 * and would be left pointing at Instagram CDN URLs that expire.
 */
if (!$alreadySent && !$refresh && !finish_request_and_continue()) {
    exit;
}

$imageDir = rtrim($cacheDir, '/') . '/ig-images';
if (!is_dir($imageDir)) {
    @mkdir($imageDir, 0775, true);
}

$mirrored = false;

foreach ($payload['posts'] as &$post) {
    $file = $imageDir . '/' . sha1($post['id']) . '.jpg';
    $age  = is_readable($file) ? time() - (int) filemtime($file) : PHP_INT_MAX;

    if ($age > (int) $config['instagram_image_ttl']) {
        $bytes = http_get($post['image'], min($timeout, time_left($budget)), 'image/*');
        // Check the magic bytes before writing anything to disk.
        if ($bytes !== null && strlen($bytes) > 1024 && strncmp($bytes, "\xFF\xD8\xFF", 3) === 0) {
            $tmp = $file . '.' . getmypid() . '.tmp';
            if (@file_put_contents($tmp, $bytes) !== false) {
                @rename($tmp, $file);
            }
        }
    }

    if (is_readable($file)) {
        // Relative to the slide page at the web root, not to this script.
        $post['image'] = 'api/image.php?id=' . rawurlencode($post['id']);
        $mirrored = true;
    }
}
unset($post);

// Rewrite the cache so the next request serves local images.
if ($mirrored) {
    write_cache($cacheFile, $payload);
}
