<?php
/**
 * Department events proxy
 *
 * GET api/department-events.php?site=csc&count=6
 *
 * Reads an allowlisted public Google Calendar iCalendar feed, expands the
 * recurring events that fall inside the requested window, and returns the
 * same core event shape used by api/events.php. No Google API key is needed.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=300');

$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/lib.php';

/* ------------------------------------------------------------------ input */

$calendars = (array) ($config['department_events'] ?? []);
$site = strtolower((string) ($_GET['site'] ?? $config['default_site'] ?? 'csc'));

if (!isset($calendars[$site]) || !is_array($calendars[$site])) {
    fail(400, 'Unknown department events key. Allowed: ' . implode(', ', array_keys($calendars)));
}

$department = $calendars[$site];
$calendarId = trim((string) ($department['calendar_id'] ?? ''));
if ($calendarId === '' || !preg_match('/^[A-Za-z0-9._%+@-]+$/', $calendarId)) {
    fail(500, 'The configured Google Calendar ID is invalid.');
}

$count = isset($_GET['count']) ? (int) $_GET['count'] : 6;
$count = max(1, min(12, $count));

$days = isset($_GET['days']) ? (int) $_GET['days'] : (int) ($department['days'] ?? 45);
$days = max(1, min(180, $days));

$refresh = isset($_GET['refresh']) && filter_var($_GET['refresh'], FILTER_VALIDATE_BOOLEAN);
$budget  = $refresh ? (int) ($config['warm_time_budget'] ?? 120) : (int) ($config['time_budget'] ?? 20);
$timeout = $refresh ? (int) ($config['warm_http_timeout'] ?? 45) : (int) ($config['http_timeout'] ?? 15);

if ($refresh) {
    @set_time_limit($budget + 60);
}

upstream_user_agent((string) ($config['user_agent'] ?? ''));

try {
    $tz = new DateTimeZone((string) ($department['timezone'] ?? $config['timezone'] ?? 'America/New_York'));
} catch (Exception $e) {
    fail(500, 'The configured department calendar timezone is invalid.');
}

$now = new DateTimeImmutable('now', $tz);
$windowEnd = $now->modify('+' . $days . ' days');
$calendarUrl = 'https://calendar.google.com/calendar/ical/' . rawurlencode($calendarId) . '/public/basic.ics';
$embedUrl = 'https://calendar.google.com/calendar/embed?' . http_build_query([
    'src' => $calendarId,
    'ctz' => $tz->getName(),
]);

/* ------------------------------------------------------------------ cache */

$cacheDir = (string) $config['cache_dir'];
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0775, true);
}

$cacheFile = rtrim($cacheDir, '/') . '/department-events-' . sha1($site . '|' . $days) . '.json';
$cacheAge = (is_readable($cacheFile) && filemtime($cacheFile) !== false)
    ? time() - (int) filemtime($cacheFile)
    : PHP_INT_MAX;
$cached = is_readable($cacheFile) ? @file_get_contents($cacheFile) : false;
$haveCache = $cached !== false && $cached !== '';
$ttl = (int) ($department['cache_ttl'] ?? 1800);

/** Emit a cached payload, removing events that have ended and applying count. */
function serve_department_events(string $json, int $count, DateTimeImmutable $now, bool $stale = false, int $age = 0): void
{
    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['events']) || !is_array($data['events'])) {
        echo $json;
        exit;
    }

    $nowIso = $now->format('c');
    $data['events'] = array_values(array_filter(
        $data['events'],
        static function (array $event) use ($nowIso): bool {
            return (string) ($event['endISO'] ?? $event['startISO'] ?? '') >= $nowIso;
        }
    ));
    $data['events'] = array_slice($data['events'], 0, $count);

    if ($stale) {
        $data['stale'] = true;
        $data['cacheAge'] = $age;
    }

    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$refresh && $haveCache && $cacheAge < $ttl) {
    serve_department_events((string) $cached, $count, $now);
}

$alreadySent = false;
if (!$refresh && $haveCache && $cacheAge < (int) $config['stale_ttl']) {
    $data = json_decode((string) $cached, true);
    if (is_array($data) && isset($data['events']) && is_array($data['events'])) {
        $nowIso = $now->format('c');
        $data['events'] = array_values(array_filter(
            $data['events'],
            static function (array $event) use ($nowIso): bool {
                return (string) ($event['endISO'] ?? $event['startISO'] ?? '') >= $nowIso;
            }
        ));
        $data['stale'] = true;
        $data['cacheAge'] = $cacheAge;
        $data['events'] = array_slice($data['events'], 0, $count);
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (finish_request_and_continue()) {
            $alreadySent = true;
        } else {
            exit;
        }
    }
}

/* ----------------------------------------------------------- iCal helpers */

/** Parse one unfolded iCalendar property line. */
function ical_property(string $line): ?array
{
    $colon = strpos($line, ':');
    if ($colon === false) {
        return null;
    }

    $left = substr($line, 0, $colon);
    $value = substr($line, $colon + 1);
    $bits = explode(';', $left);
    $name = strtoupper((string) array_shift($bits));
    $params = [];

    foreach ($bits as $bit) {
        $equals = strpos($bit, '=');
        if ($equals !== false) {
            $params[strtoupper(substr($bit, 0, $equals))] = trim(substr($bit, $equals + 1), "\"");
        }
    }

    return ['name' => $name, 'params' => $params, 'value' => $value];
}

/** Decode iCalendar text escaping after folded lines have been joined. */
function ical_text(string $value): string
{
    $value = str_replace(['\\n', '\\N'], "\n", $value);
    $value = str_replace(['\\,', '\\;', '\\\\'], [',', ';', '\\'], $value);
    return $value;
}

/** Parse a DTSTART-style property and report whether it is an all-day date. */
function ical_datetime(array $property, DateTimeZone $defaultTz): ?array
{
    $value = trim((string) ($property['value'] ?? ''));
    $params = (array) ($property['params'] ?? []);
    $allDay = ($params['VALUE'] ?? '') === 'DATE' || preg_match('/^\d{8}$/', $value) === 1;

    $zone = $defaultTz;
    if (!$allDay && isset($params['TZID'])) {
        try {
            $zone = new DateTimeZone((string) $params['TZID']);
        } catch (Exception $e) {
            $zone = $defaultTz;
        }
    }

    if ($allDay) {
        $date = DateTimeImmutable::createFromFormat('!Ymd', $value, $zone);
    } elseif (substr($value, -1) === 'Z') {
        $date = DateTimeImmutable::createFromFormat('!Ymd\THis\Z', $value, new DateTimeZone('UTC'));
    } else {
        $date = DateTimeImmutable::createFromFormat('!Ymd\THis', $value, $zone);
    }

    if (!$date instanceof DateTimeImmutable) {
        return null;
    }

    return ['date' => $date, 'allDay' => $allDay];
}

/** Parse an RRULE into an uppercase key/value map. */
function ical_rule(string $value): array
{
    $rule = [];
    foreach (explode(';', $value) as $part) {
        $equals = strpos($part, '=');
        if ($equals !== false) {
            $rule[strtoupper(substr($part, 0, $equals))] = strtoupper(substr($part, $equals + 1));
        }
    }
    return $rule;
}

/** Convert an iCalendar weekday token to PHP's ISO weekday number. */
function ical_weekday(string $token): int
{
    $map = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];
    return $map[substr($token, -2)] ?? 0;
}

/** Match a BYDAY token, including values such as 2TU and -1FR. */
function matches_byday(DateTimeImmutable $candidate, string $token): bool
{
    $weekday = ical_weekday($token);
    if ($weekday === 0 || (int) $candidate->format('N') !== $weekday) {
        return false;
    }

    $ordinalText = substr($token, 0, -2);
    if ($ordinalText === '') {
        return true;
    }

    $ordinal = (int) $ordinalText;
    $day = (int) $candidate->format('j');
    if ($ordinal > 0) {
        return (int) floor(($day - 1) / 7) + 1 === $ordinal;
    }

    $daysInMonth = (int) $candidate->format('t');
    return -((int) floor(($daysInMonth - $day) / 7) + 1) === $ordinal;
}

/** Whether a date is one of the occurrences described by a recurrence rule. */
function matches_rule(DateTimeImmutable $candidate, DateTimeImmutable $origin, array $rule): bool
{
    $frequency = $rule['FREQ'] ?? '';
    $interval = max(1, (int) ($rule['INTERVAL'] ?? 1));
    $days = (int) $origin->setTime(0, 0)->diff($candidate->setTime(0, 0))->days;
    $byDays = isset($rule['BYDAY']) ? explode(',', $rule['BYDAY']) : [];

    if (isset($rule['BYMONTH'])) {
        $months = array_map('intval', explode(',', $rule['BYMONTH']));
        if (!in_array((int) $candidate->format('n'), $months, true)) {
            return false;
        }
    }

    if ($byDays !== []) {
        $dayMatch = false;
        foreach ($byDays as $byDay) {
            if (matches_byday($candidate, $byDay)) {
                $dayMatch = true;
                break;
            }
        }
        if (!$dayMatch) {
            return false;
        }
    }

    if (isset($rule['BYMONTHDAY'])) {
        $monthDays = array_map('intval', explode(',', $rule['BYMONTHDAY']));
        $day = (int) $candidate->format('j');
        $negativeDay = $day - (int) $candidate->format('t') - 1;
        if (!in_array($day, $monthDays, true) && !in_array($negativeDay, $monthDays, true)) {
            return false;
        }
    }

    if ($frequency === 'DAILY') {
        return $days % $interval === 0;
    }

    if ($frequency === 'WEEKLY') {
        $weekStart = ical_weekday($rule['WKST'] ?? 'MO') ?: 1;
        $originOffset = ((int) $origin->format('N') - $weekStart + 7) % 7;
        $candidateOffset = ((int) $candidate->format('N') - $weekStart + 7) % 7;
        $originWeek = $origin->setTime(0, 0)->modify('-' . $originOffset . ' days');
        $candidateWeek = $candidate->setTime(0, 0)->modify('-' . $candidateOffset . ' days');
        $weeks = intdiv((int) $originWeek->diff($candidateWeek)->days, 7);
        $rightWeek = $weeks % $interval === 0;
        $rightDay = $byDays !== [] || (int) $candidate->format('N') === (int) $origin->format('N');
        return $rightWeek && $rightDay;
    }

    $months = ((int) $candidate->format('Y') - (int) $origin->format('Y')) * 12
        + (int) $candidate->format('n') - (int) $origin->format('n');

    if ($frequency === 'MONTHLY') {
        $rightMonth = $months % $interval === 0;
        $rightDay = $byDays !== [] || isset($rule['BYMONTHDAY'])
            || (int) $candidate->format('j') === (int) $origin->format('j');
        return $rightMonth && $rightDay;
    }

    if ($frequency === 'YEARLY') {
        $years = (int) $candidate->format('Y') - (int) $origin->format('Y');
        $rightYear = $years % $interval === 0;
        $rightMonth = isset($rule['BYMONTH'])
            || (int) $candidate->format('n') === (int) $origin->format('n');
        $rightDay = $byDays !== [] || isset($rule['BYMONTHDAY'])
            || (int) $candidate->format('j') === (int) $origin->format('j');
        return $rightYear && $rightMonth && $rightDay;
    }

    return false;
}

/** Read all properties from one VEVENT block. */
function event_properties(string $block): array
{
    $properties = [];
    foreach (preg_split('/\r?\n/', $block) ?: [] as $line) {
        $property = ical_property($line);
        if ($property !== null) {
            $properties[$property['name']][] = $property;
        }
    }
    return $properties;
}

/** First property value, decoded as iCalendar text. */
function first_ical_value(array $properties, string $name): string
{
    return isset($properties[$name][0]['value'])
        ? ical_text((string) $properties[$name][0]['value'])
        : '';
}

/** Build the public event payload shared by single and recurring events. */
function normalized_department_event(
    array $properties,
    DateTimeImmutable $start,
    DateTimeImmutable $end,
    bool $allDay,
    string $accent,
    bool $tighten,
    int $excerptMax
): ?array {
    $title = plain_text(first_ical_value($properties, 'SUMMARY'), $tighten);
    if ($title === '') {
        return null;
    }

    $description = plain_text(first_ical_value($properties, 'DESCRIPTION'), $tighten);

    return [
        'id'        => first_ical_value($properties, 'UID') . '@' . $start->format('U'),
        'title'     => $title,
        'url'       => first_ical_value($properties, 'URL'),
        'startISO'  => $start->format('c'),
        'endISO'    => $end->format('c'),
        'allDay'    => $allDay,
        'venue'     => plain_text(first_ical_value($properties, 'LOCATION'), false),
        'room'      => '',
        'type'      => '',
        'typeColor' => $accent,
        'image'     => '',
        'excerpt'   => trim_words($description, $excerptMax),
    ];
}

/* ------------------------------------------------------------------ fetch */

$raw = http_get($calendarUrl, min($timeout, time_left($budget)), 'text/calendar');
if ($raw === null || strpos($raw, 'BEGIN:VCALENDAR') === false) {
    if ($alreadySent) {
        exit;
    }
    if ($haveCache && $cacheAge < (int) $config['stale_ttl']) {
        serve_department_events((string) $cached, $count, $now, true, $cacheAge);
    }
    fail(
        502,
        'Could not read the public Google Calendar for '
        . (string) ($department['label'] ?? $site)
        . '. No usable cache is available either.'
    );
}

// RFC 5545 folds long content lines by starting the continuation with a space
// or tab. Join those before parsing properties.
$raw = preg_replace("/\r?\n[ \t]/", '', $raw) ?? $raw;
preg_match_all('/BEGIN:VEVENT\r?\n(.*?)\r?\nEND:VEVENT/s', $raw, $matches);

$records = [];
$overrides = [];
foreach ($matches[1] ?? [] as $block) {
    $properties = event_properties((string) $block);
    $uid = first_ical_value($properties, 'UID');
    if ($uid === '' || !isset($properties['DTSTART'][0])) {
        continue;
    }

    $records[] = $properties;
    if (isset($properties['RECURRENCE-ID'][0])) {
        $recurrence = ical_datetime($properties['RECURRENCE-ID'][0], $tz);
        if ($recurrence !== null) {
            $overrides[$uid . '|' . $recurrence['date']->format('U')] = true;
        }
    }
}

/* ---------------------------------------------------------- normalize */

$out = [];
$seen = [];
$tighten = (bool) ($config['tighten_dashes'] ?? true);
$excerptMax = (int) ($config['excerpt_max'] ?? 260);
$accent = (string) ($department['accent'] ?? $config['sites'][$site]['accent'] ?? '#CC0000');

foreach ($records as $properties) {
    if (strtoupper(first_ical_value($properties, 'STATUS')) === 'CANCELLED') {
        continue;
    }

    $parsedStart = ical_datetime($properties['DTSTART'][0], $tz);
    if ($parsedStart === null) {
        continue;
    }

    $start = $parsedStart['date'];
    $allDay = (bool) $parsedStart['allDay'];
    $parsedEnd = isset($properties['DTEND'][0]) ? ical_datetime($properties['DTEND'][0], $tz) : null;
    $end = $parsedEnd !== null
        ? $parsedEnd['date']
        : $start->modify($allDay ? '+1 day' : '+1 hour');
    $duration = max(0, $end->getTimestamp() - $start->getTimestamp());
    $uid = first_ical_value($properties, 'UID');

    // A RECURRENCE-ID VEVENT is Google's replacement for one occurrence. It
    // is emitted at its own DTSTART and the base occurrence is skipped below.
    if (isset($properties['RECURRENCE-ID'][0])) {
        if ($end >= $now && $start <= $windowEnd) {
            $event = normalized_department_event($properties, $start, $end, $allDay, $accent, $tighten, $excerptMax);
            if ($event !== null && !isset($seen[$event['id']])) {
                $seen[$event['id']] = true;
                $out[] = $event;
            }
        }
        continue;
    }

    if (!isset($properties['RRULE'][0])) {
        if ($end >= $now && $start <= $windowEnd) {
            $event = normalized_department_event($properties, $start, $end, $allDay, $accent, $tighten, $excerptMax);
            if ($event !== null && !isset($seen[$event['id']])) {
                $seen[$event['id']] = true;
                $out[] = $event;
            }
        }
        continue;
    }

    $rule = ical_rule((string) $properties['RRULE'][0]['value']);
    $until = null;
    if (isset($rule['UNTIL'])) {
        $untilProp = ['value' => $rule['UNTIL'], 'params' => []];
        $parsedUntil = ical_datetime($untilProp, $tz);
        $until = $parsedUntil !== null ? $parsedUntil['date'] : null;
    }
    $countLimit = isset($rule['COUNT']) ? max(0, (int) $rule['COUNT']) : 0;

    $excluded = [];
    foreach ($properties['EXDATE'] ?? [] as $exdateProperty) {
        foreach (explode(',', (string) $exdateProperty['value']) as $exdateValue) {
            $one = $exdateProperty;
            $one['value'] = $exdateValue;
            $parsedExdate = ical_datetime($one, $tz);
            if ($parsedExdate !== null) {
                $excluded[$parsedExdate['date']->format('U')] = true;
            }
        }
    }

    $originDate = $start->setTime(0, 0);
    $cursor = $originDate;
    $lastDate = $windowEnd->setTime(23, 59, 59);
    $occurrenceNumber = 0;

    while ($cursor <= $lastDate) {
        $candidate = $cursor->setTime(
            (int) $start->format('H'),
            (int) $start->format('i'),
            (int) $start->format('s')
        );

        if ($candidate >= $start && matches_rule($candidate, $start, $rule)) {
            $occurrenceNumber++;
            if ($countLimit > 0 && $occurrenceNumber > $countLimit) {
                break;
            }
            if ($until !== null && $candidate > $until) {
                break;
            }

            $candidateKey = $candidate->format('U');
            $candidateEnd = $candidate->modify('+' . $duration . ' seconds');
            $overrideKey = $uid . '|' . $candidateKey;

            if (!isset($excluded[$candidateKey]) && !isset($overrides[$overrideKey])
                && $candidateEnd >= $now && $candidate <= $windowEnd) {
                $event = normalized_department_event(
                    $properties,
                    $candidate,
                    $candidateEnd,
                    $allDay,
                    $accent,
                    $tighten,
                    $excerptMax
                );
                if ($event !== null && !isset($seen[$event['id']])) {
                    $seen[$event['id']] = true;
                    $out[] = $event;
                }
            }
        }

        $cursor = $cursor->modify('+1 day');
    }
}

usort($out, static function (array $a, array $b): int {
    return strcmp((string) $a['startISO'], (string) $b['startISO']);
});
$out = array_slice($out, 0, (int) ($department['limit'] ?? 12));

/* ---------------------------------------------------------------- respond */

$payload = [
    'source' => [
        'site'   => $site,
        'name'   => (string) ($department['label'] ?? $config['sites'][$site]['label'] ?? $site),
        'title'  => (string) ($department['title'] ?? 'Upcoming Events'),
        'accent' => $accent,
        'url'    => $embedUrl,
    ],
    'window' => [
        'days' => $days,
        'from' => $now->format('c'),
        'to'   => $windowEnd->format('c'),
    ],
    'generated' => date('c'),
    'cached'    => false,
    'stale'     => false,
    'events'    => $out,
];

$json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if (is_dir($cacheDir) && is_writable($cacheDir) && $json !== false) {
    $tmp = $cacheFile . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $json) !== false) {
        @rename($tmp, $cacheFile);
    }
}

if (!$alreadySent) {
    $payload['events'] = array_slice($payload['events'], 0, $count);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
