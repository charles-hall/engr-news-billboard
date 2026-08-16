---
title: NC State College of Engineering Billboard Slides — Handoff
last_updated: 2026-08-16
---

# Handoff: Engineering News Billboard Slides

This document is for whoever picks up maintenance of this project next —
whether that's finishing a task, fixing something broken, or just getting
oriented. It covers what the system is, where it lives, how to do the common
tasks, and where the sharp edges are. For full technical reference (every
config option, every URL parameter, every troubleshooting scenario), see
`README.md` in this repository — this document is the shorter briefing that
points you at the right section of that when you need depth.

## What this is

Custom digital signage slides for `billboard.ncsu.edu`, the College of
Engineering's lobby display system. Three slide types, each a static HTML
page backed by a small PHP proxy:

1. **News** (`index.html`) — cycles the latest WordPress posts from a
   department site (featured image, headline, date, abstract), one
   department at a time via `?site=`.
2. **Instagram** (`instagram.html`) — a grid of recent Instagram posts, read
   from the Smash Balloon plugin already running on a department's WordPress
   site rather than the Meta API (see "Why no Meta API" below).
3. **Events** (`events.html` agenda list, `events-cycle.html` one-at-a-time
   with photos) — upcoming events on Centennial Campus, pulled from NC
   State's Localist calendar at `calendar.ncsu.edu`.
4. **Department events** (`department-events.html`) — an agenda from an
   allowlisted public department Google Calendar. Computer Science (`?site=csc`)
   is the first configured example.

Everything is styled to brand.ncsu.edu: Wolfpack Red anchoring every slide's
top and bottom, Roboto/Roboto Condensed/Roboto Slab (self-hosted, no Google
Fonts dependency on the kiosk), AP style dates and times, and a per-department
or per-event-type accent color from the brand's secondary palette.

## Where it lives

| What | Where |
| --- | --- |
| Source code | `github.com/charles-hall/engr-news-billboard` |
| Live deployment | `https://billboard.engr.it/news-slides/` |
| Hosting | Plesk, server "ls3" (a different box from `ece.ncsu.edu`, which is "ls2" — matters if you're ever asked whether an extension like cURL is enabled in the right place) |
| Deploy mechanism | Plesk's Git extension, pulling from the GitHub repo. Push to the tracked branch, then pull/deploy in Plesk. |
| Scheduled cache warm-up | Plesk scheduled task (cron) running `tools/warm.sh` every 10 minutes |

To add the news slide to billboard.ncsu.edu's rotation, use its **Add URL**
dialog with a URL like `https://billboard.engr.it/news-slides/index.html?site=csc&count=5&dwell=12`.
Full parameter tables for every slide are in `README.md`.

## Architecture, briefly

Each slide is a static HTML/CSS/JS page (no build step, no framework) that
fetches JSON from a PHP proxy under `api/`:

- `api/feed.php` — WordPress REST API first, RSS fallback if REST is blocked
- `api/instagram.php` — scrapes the Smash Balloon plugin's rendered HTML
- `api/events.php` — reads Localist's public JSON API, geofences to
  Centennial Campus by venue coordinates (see "Why a bounding box" below)
- `api/department-events.php` — reads public Google Calendar iCalendar feeds,
  expands recurring events and applies exception dates/cancellations
- `api/image.php` — serves Instagram images mirrored locally (see below)
- `api/lib.php` — shared helpers: HTTP fetch with gzip handling, caching
  helpers, text cleanup (AP style, dash-tightening, HTML stripping)

All four cache their upstream calls to a `cache/` directory (gitignored, not
`/tmp` — see "Why not /tmp" below) using a stale-while-revalidate pattern: a
request gets the cached copy back instantly, and the refresh happens after
the response has already gone out, so a display is never the one waiting on
a slow department site.

`config.php` is the one file you'll edit most. It holds the department
allowlist, per-department accent colors, Instagram handles, events
geofencing bounds, and all the cache/timeout tuning. Everything in it is
commented with the reasoning behind the current values — read the comment
before changing a number, most of them exist because something broke without
them.

## Common tasks

**Add a new department to the news slide.** Add an entry to `sites` in
`config.php` (host, label, accent color) and to `SITES` in `tools/warm.sh`.
See "Department accent colors" in `README.md` for how to pick an on-brand,
AA-contrast color for the new entry — there's a worked methodology, not just
a color picker.

**Enable Instagram for a department.** Only works if that department's
WordPress site is running the Smash Balloon Instagram Feed plugin. As of this
writing, only csc, ece and ccee have it configured and enabled. Getting a new
one working is described in `config.php`'s `instagram` section — mostly a
WordPress-side task (installing/configuring the plugin), not a code change
here.

**Change how many days ahead the events slides look, or which event types are
excluded.** `config.php`'s `events` block — `days` and `exclude_types`.

**Add a department events calendar.** Add an allowlisted entry under
`department_events` in `config.php`, then add its key to
`DEPARTMENT_EVENT_SITES` in `tools/warm.sh`. Never accept a raw calendar ID
from the slide URL; `?site=` is the allowlisted public interface.

**Force a slide to refetch immediately instead of waiting for its cache to
expire.** Add `&refresh=1` to the proxy URL directly (e.g.
`api/feed.php?site=csc&refresh=1`), not to the slide's own URL.

**Something on the live site looks wrong and you're not sure why.** Open
`tools/diagnose.php` in a browser. It tests every feed from the server side
and reports cURL/zlib availability, cache freshness, and per-site fetch
results. `?cache=1` gives a fast, no-network-calls version of the same
report.

## Things that will bite you if you don't know them going in

- **Cache directory is not `/tmp`.** It was, once, and enabling a PHP
  extension in Plesk restarted PHP-FPM, which wiped `/tmp` (systemd
  PrivateTmp) and cold-started every department feed at once. `cache_dir` in
  `config.php` now defaults to an in-repo `cache/` directory instead. Don't
  "clean up" by pointing it back at `/tmp`.
- **Some department sites gzip-compress their response even when the request
  didn't ask for it.** `ece.ncsu.edu`'s WAF (Sucuri) does this;
  `csc.ncsu.edu`'s (Cloudflare) doesn't. `api/lib.php`'s `maybe_gunzip()`
  handles it by sniffing the gzip magic bytes rather than trusting the
  `Content-Encoding` header. If a feed ever reports "0 markers found" in a
  suspiciously small byte count, this is almost certainly why.
- **A warm-up request (`refresh=1`, from the scheduled task) gets much longer
  timeouts than a live display request.** `warm_time_budget` /
  `warm_http_timeout` vs. `time_budget` / `http_timeout` in `config.php`.
  `cbe.ncsu.edu` takes ~28 seconds cold; a display should never wait that
  long, but the scheduled warm-up can and should.
- **Instagram images are mirrored locally; event photos are not.** Instagram
  CDN URLs are signed and expire, so `api/instagram.php` copies them to
  `cache/ig-images/` and serves them through `api/image.php`. Localist's
  event photo URLs (`localist-images.azureedge.net/...`) are stable and
  unsigned, so `api/events.php` links them directly — don't build a mirror
  for those, it isn't needed.
- **Why no Meta API for Instagram:** using the Smash Balloon plugin's already
  -rendered HTML avoids needing a Meta Business account, App Review, and the
  ongoing maintenance of refreshing a 60-day access token. The tradeoff is
  that it only works on departments that have that plugin installed and
  configured — it's reading the department site's own rendering, not a
  general API.
- **Why a bounding box for "Centennial Campus":** Localist's `campus_id`
  field is null on every event at this NC State instance, and there's no
  campus filter to query. The events code instead checks each event's venue
  lat/long against a box calibrated from real venue coordinates (documented
  in `config.php` and `README.md`). If a new Centennial venue ever falls
  outside the box, or a main-campus one falls inside it, that box is the
  first place to check.
- **RSS is a fallback, not a preference.** `config.php`'s per-site `source`
  defaults to `'auto'` (REST first, RSS if REST fails). It only gets pinned
  to `'rss'` or `'rest'` when one path is known to be broken for that site.
  Don't pin a site without a documented reason — check the comment history in
  `config.php` first, since a prior pin may have already been reverted once
  the underlying issue was fixed.

## Verifying a change before it ships

There's no automated test suite — this is static HTML/CSS/JS plus a few PHP
proxies, no build step. Verify by:

1. Running PHP's built-in server locally (`php -S 127.0.0.1:8099`) against
   a copy of the repo and hitting the `api/*.php` endpoints directly with
   `curl` to check the JSON shape.
2. Loading the slide HTML in a browser at 1920×1080 (or via a headless
   browser screenshot) to check layout, text overflow, and image loading.
3. `tools/diagnose.php` for a full pass across every configured site.
4. On the live deployment, `tools/warm.sh`'s log
   (`cache/warm-last-run.txt`) shows whether the last scheduled run
   succeeded for every site.

## Contact / ownership

Charles Hall (cphall2@ncsu.edu) leads departmental communications for the
College of Engineering and owns this project's requirements and priorities.
Brand questions beyond what's documented in `README.md` go to
ncstatebrand@ncsu.edu per brand.ncsu.edu.
