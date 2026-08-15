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
$budget = (int) ($config['time_budget'] ?? 20);

/* ------------------------------------------------------------------ cache */

$cacheDir = (string) $config['cache_dir'];
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0775, true);
}

$cacheFile = rtrim($cacheDir, '/') . '/ig-' . $siteKey . '-' . sha1($path . '|' . $count) . '.json';
$cacheAge  = (is_readable($cacheFile) && filemtime($cacheFile) !== false)
    ? time() - (int) filemtime($cacheFile)
    : PHP_INT_MAX;

if (!$refresh && $cacheAge < (int) $config['instagram_cache_ttl']) {
    $cached = @file_get_contents($cacheFile);
    if ($cached !== false && $cached !== '') {
        echo $cached;
        exit;
    }
}

/* ------------------------------------------------------------------ fetch */

$htmlDoc = http_get($base . $path, min((int) $config['http_timeout'], time_left($budget)), 'text/html');

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
    $count,
    (bool) $config['clean_captions'],
    (int) $config['caption_max'],
    (bool) ($config['tighten_dashes'] ?? true)
);

/*
 * When this fails it fails on someone else's server, where none of the usual
 * tools are to hand, so the error carries what was actually seen: how much
 * markup came back and which Smash Balloon markers were in it. "No posts found"
 * on its own cannot distinguish a blocked fetch from a markup change.
 */
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

// ?debug=1 reports what the parser saw without changing what it does.
if (isset($_GET['debug'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'host'           => $host,
        'path'           => $path,
        'fetched_bytes'  => strlen($htmlDoc),
        'sbi_item'       => substr_count($htmlDoc, 'class="sbi_item'),
        'data_full_res'  => substr_count($htmlDoc, 'data-full-res="'),
        'plugin_present' => strpos($htmlDoc, 'plugins/instagram-feed') !== false,
        'posts_parsed'   => count($posts),
        'titles'         => array_map(static fn ($p) => mb_substr($p['caption'], 0, 60), $posts),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

/* --------------------------------------------------------- mirror images */

/*
 * Instagram CDN URLs are signed and expire. Copy each image onto this server
 * and hand the slide a stable local URL, so a lapsed signature can never put
 * broken images on a wall.
 */
if ((bool) $config['instagram_mirror_images']) {
    $imageDir = rtrim($cacheDir, '/') . '/ig-images';
    if (!is_dir($imageDir)) {
        @mkdir($imageDir, 0775, true);
    }

    foreach ($posts as &$post) {
        $key  = sha1($post['id']);
        $file = $imageDir . '/' . $key . '.jpg';
        $age  = is_readable($file) ? time() - (int) filemtime($file) : PHP_INT_MAX;

        if ($age > (int) $config['instagram_image_ttl']) {
            // Stop mirroring rather than overrun the budget; anything not
            // copied this pass keeps its previous local file or gets picked up
            // on the next refresh.
            $bytes = time_left($budget) > 2
                ? http_get($post['image'], min((int) $config['http_timeout'], time_left($budget)), 'image/*')
                : null;
            // Sanity check the magic bytes before writing anything to disk.
            if ($bytes !== null && strlen($bytes) > 1024 && str_starts_with($bytes, "\xFF\xD8\xFF")) {
                $tmp = $file . '.' . getmypid() . '.tmp';
                if (@file_put_contents($tmp, $bytes) !== false) {
                    @rename($tmp, $file);
                }
            }
        }

        if (is_readable($file)) {
            // Relative to the slide page at the web root, not to this script.
            $post['image'] = 'api/image.php?id=' . rawurlencode($post['id']);
        }
    }
    unset($post);
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

$json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

if (is_dir($cacheDir) && is_writable($cacheDir)) {
    $tmp = $cacheFile . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $json) !== false) {
        @rename($tmp, $cacheFile);
    }
}

echo $json;
