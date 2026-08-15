#!/bin/sh
#
# Warm the feed caches so no display ever waits on a cold origin.
#
# The department sites sit behind Cloudflare. A cold response to the feed query
# takes close to thirty seconds to build while a warm one takes under half a
# second. This script pays that cost on a schedule instead of making a billboard
# pay it in front of an audience.
#
# Plesk: Websites & Domains > Scheduled Tasks > Add Task
#   Task type: Run a command
#   Command:   /bin/sh /var/www/vhosts/YOUR-DOMAIN/httpdocs/billboard/tools/warm.sh
#   Run:       every 10 minutes  (cron: */10 * * * *)
#
# Set BILLBOARD_URL to wherever the slides are deployed.

BILLBOARD_URL="${BILLBOARD_URL:-https://YOUR-SERVER/billboard}"
SITES="${SITES:-csc ece mae ne ccee bme cbe mse ise engr}"
IG_SITES="${IG_SITES:-csc ece}"
COUNT="${COUNT:-5}"

for site in $SITES; do
    curl -fsS -m 60 -o /dev/null \
        "$BILLBOARD_URL/api/feed.php?site=$site&count=$COUNT&refresh=1" \
        || echo "warm: news feed failed for $site" >&2
done

for site in $IG_SITES; do
    curl -fsS -m 60 -o /dev/null \
        "$BILLBOARD_URL/api/instagram.php?site=$site&refresh=1" \
        || echo "warm: instagram feed failed for $site" >&2
done
