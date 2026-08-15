<?php
/**
 * NC State Billboard News Slides - shared helpers
 *
 * Used by feed.php, instagram.php and image.php.
 */

declare(strict_types=1);

/** Emit an error payload and stop. */
function fail(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Serve the last good cache if it is not too old, otherwise fail.
 *
 * On a wall, yesterday's real content beats today's error card.
 */
function serve_stale_or_fail(string $cacheFile, int $cacheAge, int $staleTtl, string $message): void
{
    if (is_readable($cacheFile) && $cacheAge < $staleTtl) {
        $stale = json_decode((string) file_get_contents($cacheFile), true);
        if (is_array($stale)) {
            $stale['stale']    = true;
            $stale['cacheAge'] = $cacheAge;
            echo json_encode($stale, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    fail(502, $message . ' No usable cache is available either.');
}

/**
 * Send everything buffered so far, then keep running with the client gone.
 *
 * Used for stale-while-revalidate: a display gets the cached feed instantly and
 * the slow refresh happens after it has already been served. Returns false when
 * the SAPI cannot detach, in which case the caller should just serve stale and
 * let the scheduled warm-up task do the refreshing.
 */
function finish_request_and_continue(): bool
{
    if (!function_exists('fastcgi_finish_request')) {
        return false;
    }
    ignore_user_abort(true);
    fastcgi_finish_request();

    return true;
}

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

/** Turn rendered HTML into clean single-line plain text. */
function plain_text(string $html, bool $tightenDashes = true): string
{
    $html = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $html) ?? $html;
    // Treat <br> and block ends as spaces, or words run together.
    $html = preg_replace('/<(?:br\s*\/?|\/p|\/div)>/i', ' ', $html) ?? $html;
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
