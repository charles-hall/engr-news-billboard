<?php
/**
 * NC State Billboard News Slides - mirrored Instagram image
 *
 * GET api/image.php?id=<instagram media id>
 *
 * Serves an image that instagram.php already copied into the cache directory.
 * Instagram CDN URLs are signed and expire, so the slide never links to them
 * directly.
 *
 * This endpoint only ever reads a file whose name is a hash of the requested
 * id, so a crafted id cannot escape the cache directory or name another file.
 * It never fetches anything itself: if instagram.php has not mirrored the
 * image yet, this returns 404 rather than becoming a general image proxy.
 */

declare(strict_types=1);

$config = require __DIR__ . '/../config.php';

$id = isset($_GET['id']) ? (string) $_GET['id'] : '';
if ($id === '') {
    http_response_code(400);
    exit;
}

$file = rtrim((string) $config['cache_dir'], '/') . '/ig-images/' . sha1($id) . '.jpg';

if (!is_readable($file)) {
    http_response_code(404);
    exit;
}

$mtime = (int) filemtime($file);
$etag  = '"' . sha1($id . $mtime) . '"';

header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($file));
header('Cache-Control: public, max-age=86400');
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');

// A billboard reloads the same slide all day. Honour conditional requests so
// it re-downloads these only when they actually change.
$since = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
if (trim($since) === $etag) {
    http_response_code(304);
    exit;
}

readfile($file);
