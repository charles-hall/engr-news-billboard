<?php
/**
 * NC State Billboard News Slides - deployment diagnostic
 *
 * Open https://YOUR-SERVER/billboard/tools/diagnose.php in a browser.
 *
 * Tests every configured department from THIS server and reports what works,
 * what does not, and how long each path takes. Timings from your own server are
 * the only ones that matter: a department site can answer a laptop in half a
 * second and this machine in thirty.
 *
 * Reads only. Changes nothing. Safe to delete once the deployment is settled.
 */

declare(strict_types=1);

@set_time_limit(300);

$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../api/lib.php';

upstream_user_agent((string) ($config['user_agent'] ?? ''));

header('Content-Type: text/html; charset=utf-8');

/** Time a single fetch and describe the outcome. */
function probe(string $url, int $timeout, string $accept = 'application/json'): array
{
    $start = microtime(true);
    $body  = http_get($url, $timeout, $accept);
    $ms    = (int) round((microtime(true) - $start) * 1000);

    return [
        'ok'    => $body !== null,
        'ms'    => $ms,
        'bytes' => $body === null ? 0 : strlen($body),
        'body'  => $body,
    ];
}

function cell(array $r, string $extra = ''): string
{
    $klass = $r['ok'] ? 'ok' : 'bad';
    $label = $r['ok'] ? 'ok' : 'failed';
    $slow  = $r['ms'] > 8000 ? ' slow' : '';

    return '<td class="' . $klass . $slow . '">' . $label
        . '<span class="ms">' . number_format($r['ms']) . ' ms</span>'
        . ($extra !== '' ? '<span class="ms">' . htmlspecialchars($extra) . '</span>' : '')
        . '</td>';
}

$timeout  = (int) $config['http_timeout'];
$cacheDir = (string) $config['cache_dir'];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Billboard slides diagnostic</title>
<style>
  body { font: 16px/1.5 Roboto, Arial, sans-serif; margin: 40px; color: #333; }
  h1 { font-size: 28px; margin: 0 0 4px; color: #CC0000; }
  h2 { font-size: 20px; margin: 36px 0 10px; }
  p.lede { margin: 0 0 24px; color: #4D4D4D; }
  table { border-collapse: collapse; width: 100%; max-width: 1100px; }
  th, td { text-align: left; padding: 9px 12px; border-bottom: 1px solid #CCC; vertical-align: top; }
  th { background: #F2F2F2; font-weight: 700; }
  td.ok { color: #2F6B2F; }
  td.bad { color: #990000; font-weight: 700; }
  td.slow { background: #FEF8CB; }
  .ms { display: block; color: #4D4D4D; font-size: 13px; font-weight: 400; }
  code { background: #F2F2F2; padding: 1px 5px; }
  .note { max-width: 1100px; background: #F2F2F2; padding: 14px 18px; margin: 22px 0; }
</style>
</head>
<body>

<h1>Billboard slides diagnostic</h1>
<p class="lede">Run from <?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'this server') ?> at <?= date('M j, Y g:i a') ?>.</p>

<h2>Environment</h2>
<table>
  <tr><th>Check</th><th>Value</th></tr>
  <tr><td>PHP version</td><td><?= htmlspecialchars(PHP_VERSION) ?></td></tr>
  <tr><td>cURL extension</td><td class="<?= function_exists('curl_init') ? 'ok' : 'bad' ?>"><?= function_exists('curl_init') ? 'present' : 'missing, using the stream wrapper' ?></td></tr>
  <tr>
    <td>zlib (gzdecode)</td>
    <td class="<?= function_exists('gzdecode') ? 'ok' : 'bad' ?>">
      <?= function_exists('gzdecode') ? 'present' : 'MISSING' ?><br>
      <span class="ms">Required. Some origins send gzip whether or not it was requested,
      and without this the response is unreadable binary.</span>
    </td>
  </tr>
  <tr><td>max_execution_time</td><td><?= htmlspecialchars((string) ini_get('max_execution_time')) ?>s (time_budget is <?= (int) ($config['time_budget'] ?? 20) ?>s)</td></tr>
  <tr><td>memory_limit</td><td><?= htmlspecialchars((string) ini_get('memory_limit')) ?></td></tr>
  <tr>
    <td>stale-while-revalidate</td>
    <td class="<?= function_exists('fastcgi_finish_request') ? 'ok' : 'bad' ?>">
      <?= function_exists('fastcgi_finish_request')
            ? 'available'
            : 'unavailable on this SAPI, so run tools/warm.sh on a schedule' ?>
    </td>
  </tr>
  <tr>
    <td>Cache directory</td>
    <td class="<?= is_dir($cacheDir) && is_writable($cacheDir) ? 'ok' : 'bad' ?>">
      <?= htmlspecialchars($cacheDir) ?><br>
      <?= is_dir($cacheDir) ? (is_writable($cacheDir) ? 'writable' : 'NOT WRITABLE') : 'does not exist yet' ?>
    </td>
  </tr>
</table>

<h2>News feeds</h2>
<p class="lede">Each department is tried exactly as <code>feed.php</code> would try it. Rows shaded yellow took over eight seconds, which is slow enough that a display would notice on a cold cache.</p>
<table>
  <tr><th>Key</th><th>Host</th><th>Configured</th><th>REST</th><th>RSS</th><th>Cache</th></tr>
<?php foreach ($config['sites'] as $key => $site):
    $host = $site['host'];
    $pref = strtolower((string) ($site['source'] ?? 'auto'));
    $base = 'https://' . $host;

    $rest = ['ok' => false, 'ms' => 0, 'bytes' => 0, 'body' => null];
    if ($pref !== 'rss') {
        $rest = probe($base . '/wp-json/wp/v2/posts?per_page=1&_fields=id', $timeout);
    }

    $rss = ['ok' => false, 'ms' => 0, 'bytes' => 0, 'body' => null];
    if ($pref !== 'rest') {
        $rss = probe($base . '/feed/', $timeout, 'application/rss+xml, application/xml');
    }

    $cacheGlob = glob(rtrim($cacheDir, '/') . '/feed-' . $key . '-*.json') ?: [];
    $cacheAge  = $cacheGlob !== [] ? time() - (int) filemtime($cacheGlob[0]) : null;
?>
  <tr>
    <td><code><?= htmlspecialchars((string) $key) ?></code></td>
    <td><?= htmlspecialchars($host) ?></td>
    <td><?= htmlspecialchars($pref) ?></td>
    <?php if ($pref === 'rss'): ?><td>skipped</td><?php else: echo cell($rest); endif; ?>
    <?php if ($pref === 'rest'): ?><td>skipped</td><?php else: echo cell($rss, $rss['ok'] ? number_format($rss['bytes'] / 1024) . ' KB' : ''); endif; ?>
    <td><?= $cacheAge === null ? 'none yet' : 'age ' . number_format($cacheAge) . 's' ?></td>
  </tr>
<?php endforeach; ?>
</table>

<h2>Instagram feeds</h2>
<table>
  <tr><th>Key</th><th>Handle</th><th>Source page</th><th>Fetch</th><th>Posts found</th></tr>
<?php foreach (($config['instagram'] ?? []) as $key => $ig):
    if (!isset($config['sites'][$key])) { continue; }
    $host = $config['sites'][$key]['host'];
    $path = '/' . ltrim((string) ($ig['path'] ?? '/'), '/');
    $page = probe('https://' . $host . $path, $timeout, 'text/html');
    $found = $page['ok'] ? preg_match_all('/<div class="sbi_item/', (string) $page['body']) : 0;
?>
  <tr>
    <td><code><?= htmlspecialchars((string) $key) ?></code></td>
    <td>@<?= htmlspecialchars((string) $ig['handle']) ?></td>
    <td><?= htmlspecialchars($host . $path) ?></td>
    <?= cell($page) ?>
    <td class="<?= $found > 0 ? 'ok' : 'bad' ?>"><?= (int) $found ?></td>
  </tr>
<?php endforeach; ?>
</table>

<div class="note">
  <strong>Reading this.</strong> A failed REST cell is expected where the site is
  pinned to <code>rss</code>. A cell over eight seconds means that department's
  origin is slow from this server, which is exactly what
  <code>tools/warm.sh</code> on a ten minute schedule is for: it pays that cost
  out of band so no display ever waits for it. Zero posts found for an Instagram
  row means that page does not render a Smash Balloon feed.
</div>

</body>
</html>
