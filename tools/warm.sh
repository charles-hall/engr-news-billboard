#!/bin/sh
#
# Warm the feed caches so no display ever waits on a cold origin.
#
# The department sites sit behind Cloudflare and Sucuri. A cold response to the
# feed query takes close to thirty seconds to build while a warm one takes under
# half a second. This script pays that cost on a schedule instead of making a
# billboard pay it in front of an audience.
#
# Plesk: Websites & Domains > Scheduled Tasks > Add Task
#   Task type: Run a command
#   Command:   /bin/sh /var/www/vhosts/ece.ncsu.edu/billboard.engr.it/news-slides/tools/warm.sh
#   Run:       every 10 minutes  (cron: */10 * * * *)
#
# BILLBOARD_URL defaults to the live deployment. Everything else has a working
# default too, so the task above needs no arguments.
#
# Each run writes a short report to the cache directory, which
# tools/diagnose.php displays. Without it there is no way to tell a warm-up that
# is not scheduled from one that is running and failing.

BILLBOARD_URL="${BILLBOARD_URL:-https://billboard.engr.it/news-slides}"
# Tolerate a trailing slash, which otherwise produces //api/ in every request.
BILLBOARD_URL="${BILLBOARD_URL%/}"
SITES="${SITES-csc ece mae ne ccee bme cbe mse ise engr}"
IG_SITES="${IG_SITES-csc ece ccee}"
DEPARTMENT_EVENT_SITES="${DEPARTMENT_EVENT_SITES-csc ece}"
COUNT="${COUNT:-5}"

# Must match cache_dir in config.php. Default is the deployment's own cache/
# directory, since a PHP-FPM restart wipes anything under a PrivateTmp /tmp.
CACHE_DIR="${CACHE_DIR:-$(cd "$(dirname "$0")/.." && pwd)/cache}"
LOG="$CACHE_DIR/warm-last-run.txt"

# Fail fast and legibly on an unedited placeholder. Otherwise this emits one
# DNS error per feed and buries the actual problem in the noise.
case "$BILLBOARD_URL" in
    *YOUR-SERVER*|*YOUR-DOMAIN*|*example.com*)
        echo "warm: BILLBOARD_URL is still a placeholder ($BILLBOARD_URL)." >&2
        echo "warm: Edit it at the top of this script, or pass it in:" >&2
        echo "warm:   BILLBOARD_URL=https://billboard.engr.it/news-slides sh $0" >&2
        exit 2
        ;;
esac

mkdir -p "$CACHE_DIR" 2>/dev/null

ok=0
failed=""

warm_one() {
    # $1 = label for the report, $2 = url
    if curl -fsS -m 180 -o /dev/null "$2"; then
        ok=$((ok + 1))
    else
        failed="$failed $1"
        echo "warm: failed $1" >&2
    fi
}

started=$(date '+%Y-%m-%d %H:%M:%S %Z')

for site in $SITES; do
    warm_one "news:$site" "$BILLBOARD_URL/api/feed.php?site=$site&count=$COUNT&refresh=1"
done

for site in $IG_SITES; do
    warm_one "instagram:$site" "$BILLBOARD_URL/api/instagram.php?site=$site&refresh=1"
done

# Set WARM_EVENTS=0 to skip.
if [ "${WARM_EVENTS:-1}" = "1" ]; then
    warm_one "events" "$BILLBOARD_URL/api/events.php?refresh=1"
fi

# Public Google Calendars are much larger than the Localist response (the CSC
# feed carries its full history), so warm them out of band as well.
for site in $DEPARTMENT_EVENT_SITES; do
    warm_one "department-events:$site" "$BILLBOARD_URL/api/department-events.php?site=$site&refresh=1"
done

{
    echo "started   $started"
    echo "finished  $(date '+%Y-%m-%d %H:%M:%S %Z')"
    echo "url       $BILLBOARD_URL"
    echo "ok        $ok"
    echo "failed   ${failed:- none}"
} > "$LOG" 2>/dev/null

[ -z "$failed" ] || exit 1
