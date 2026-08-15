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
#   Command:   /bin/sh /var/www/vhosts/YOUR-DOMAIN/httpdocs/billboard/tools/warm.sh
#   Run:       every 10 minutes  (cron: */10 * * * *)
#
# Set BILLBOARD_URL to wherever the slides are deployed. Everything else has a
# working default.
#
# Each run writes a short report to the cache directory, which
# tools/diagnose.php displays. Without it there is no way to tell a warm-up that
# is not scheduled from one that is running and failing.

BILLBOARD_URL="${BILLBOARD_URL:-https://YOUR-SERVER/billboard}"
SITES="${SITES:-csc ece mae ne ccee bme cbe mse ise engr}"
IG_SITES="${IG_SITES:-csc ece ccee}"
COUNT="${COUNT:-5}"

# Must match cache_dir in config.php.
CACHE_DIR="${CACHE_DIR:-/tmp/ncstate-billboard-cache}"
LOG="$CACHE_DIR/warm-last-run.txt"

mkdir -p "$CACHE_DIR" 2>/dev/null

ok=0
failed=""

warm_one() {
    # $1 = label for the report, $2 = url
    if curl -fsS -m 90 -o /dev/null "$2"; then
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

{
    echo "started   $started"
    echo "finished  $(date '+%Y-%m-%d %H:%M:%S %Z')"
    echo "url       $BILLBOARD_URL"
    echo "ok        $ok"
    echo "failed   ${failed:- none}"
} > "$LOG" 2>/dev/null

[ -z "$failed" ] || exit 1
