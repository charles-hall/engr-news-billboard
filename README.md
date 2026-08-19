# NC State Billboard News Slides

Dynamic 1920x1080 digital signage slides that show the latest news from an NC State
department WordPress site. Built for [billboard.ncsu.edu](https://billboard.ncsu.edu)
and hosted on a Plesk server.

Repository: <https://github.com/charles-hall/engr-news-billboard>

Each slide shows the featured image, headline, publication date and abstract for a
story, then fades to the next one. Type, color and editorial style follow
[brand.ncsu.edu](https://brand.ncsu.edu).

One billboard URL covers a whole department. Add it once and it keeps itself current.

---

## What it looks like

A 55/45 split: the featured photo fills the left of the canvas, the story sits on a
white panel at the right under a short accent bar. Wolfpack Red bars close the top
and bottom of the frame, with a progress line and story dots in the footer, so the
core brand color still opens and closes every slide.

- Headlines: Roboto Condensed Bold, sized to the room the panel can spare
  (see "Fitting the headline" below)
- Abstract: Roboto Regular, 33px, clamped to five lines
- Date: Roboto Slab, AP style ("Aug. 14, 2026")
- Eyebrow: uppercase Roboto Condensed with wide tracking, a label treatment
  matching ncsu.edu's own section labels
- All three faces are self-hosted, so the slide never waits on an outside CDN

When a story arrives, the accent bar draws itself in and each text line rises
into place a beat after the one before; the active footer dot stretches into a
short bar so the current position reads from across a room. All motion honors
`prefers-reduced-motion`, falling back to simple fades.

### Fitting the headline

Department headlines run from four words to twenty, so `assets/fit.js` sizes
each one to the space its panel can actually spare, rather than to a fixed
budget. It is shared by the news slide and the cycling events slide, which use
the same layout.

The order it tries:

1. Measure the room left after the eyebrow, date and abstract take what they
   genuinely need. A three-line abstract hands its leftover space to the
   headline, so most headlines keep the full 78px.
2. Step the type down toward 44px until the text fits that room.
3. If it still does not fit, reduce the abstract from five lines to four, then
   three, and try the sizes again.
4. Only if all of that fails, trim, and then to a whole number of lines.

When the text fits, no ceiling is set at all, so the descenders on the last
line are never shaved. `.headline` deliberately carries no `overflow: hidden`
in CSS for that reason; the fitter applies it only in the trim case.

The fit is re-run whenever a slide becomes active. A web font arriving after
the first measurement would otherwise leave headlines sized against the
fallback face, which is how a headline that measured as fitting can still
render a line too tall.

Two earlier bugs are worth knowing about, since both produced the same symptom
on the wall, a headline sheared through the middle of its last line:

- The budget was hardcoded to 380px while the panel had roughly 450px to give,
  so headlines were trimmed with room to spare.
- `.slide` used an implicit `auto` grid row, which is sized to its content. An
  overlong headline stretched the row past the bottom of the frame and
  inflated the panel height the fitter measured, so the longer the text ran,
  the more room it appeared to have and the less it shrank. The row is now
  pinned with `minmax(0, 1fr)`, and the fitter measures the slide rather than
  the panel.

### Department accent colors

The eyebrow and the bar above it are not always red. Each department carries its
own color from brand.ncsu.edu's expanded palette, set in `config.php`'s `sites`
array and checked there for WCAG AA contrast on white:

| Department | Color | Hex |
| --- | --- | --- |
| Computer Science | Innovation Blue | `#427E93` |
| Electrical and Computer Engineering | Bio-Indigo | `#4156A1` |
| Mechanical and Aerospace Engineering | Pyroman Flame | `#D14905` |
| Nuclear Engineering | Hunt Yellow (shade) | `#966D00` |
| Civil, Construction and Environmental Engineering | Genomic Green | `#6F7D1C` |
| Biomedical Engineering | Carmichael Aqua | `#008473` |
| Chemical and Biomolecular Engineering | Carmichael Aqua (shade) | `#00716D` |
| Materials Science and Engineering | Bio-Indigo (tint) | `#5B73BB` |
| Industrial and Systems Engineering | Pyroman Flame (shade) | `#C03003` |
| College of Engineering | Reynolds Red | `#990000` |

Hunt Yellow's base hex fails contrast outright on white, which is why Nuclear
Engineering uses a shade from its approved ramp instead. `?theme=dark` ignores
these and keeps the eyebrow white, since several of them do not clear AA on
black at this size either; the accent bar still varies there since it carries
no text.

To change a department's color, edit its `accent` in `config.php` and mirror the
same hex into `DIRECT_HOSTS` in `assets/slide.js` (used only by the no-PHP
fallback path).

---

## Quick start

```
https://billboard.engr.it/news-slides/index.html?site=csc&count=5&dwell=12
```

That URL cycles the five newest Computer Science stories, twelve seconds each.

### Adding it to billboard.ncsu.edu

In the **Add URL** dialog:

| Field | Value |
| --- | --- |
| URL | `https://billboard.engr.it/news-slides/index.html?site=csc&count=5&dwell=12` |
| Title | Computer Science News |
| Number of seconds to display slide | `60` |
| Do you want the slide to reload after it has been displayed? | **Yes** |

Set the duration to **count x dwell**. Five stories at twelve seconds is sixty
seconds. Reload set to Yes guarantees the deck restarts at story one and picks up
anything published since the last pass.

Leave the Slide Scheduler fields blank unless the department wants news to run only
during certain hours.

### Departments

Ten department keys ship in `config.php`, all verified working:

| Key | Site | Feed path |
| --- | --- | --- |
| `csc` | Computer Science | REST |
| `ece` | Electrical and Computer Engineering | REST |
| `mae` | Mechanical and Aerospace Engineering | REST |
| `ne` | Nuclear Engineering | REST |
| `ccee` | Civil, Construction and Environmental Engineering | REST |
| `bme` | Biomedical Engineering | REST |
| `cbe` | Chemical and Biomolecular Engineering | REST |
| `mse` | Materials Science and Engineering | REST |
| `ise` | Industrial and Systems Engineering | REST |
| `engr` | College of Engineering | REST |

Each billboard entry is just a different URL, so one deployment can run Computer
Science on a display in EB2 and CCEE on another in Mann Hall.

`ise` is formally the Edward P. Fitts Department of Industrial and Systems
Engineering. The label is shortened because the eyebrow sits directly above the
headline and the full name crowds it. Change it in `config.php` to use the full
name.

---

## URL parameters

| Parameter | Default | Notes |
| --- | --- | --- |
| `site` | `csc` | Key from `config.php`, see the department table above |
| `count` | `5` | Stories in the deck, 1 to 12 |
| `dwell` | `12` | Seconds per story, 4 to 120 |
| `theme` | `light` | `light` or `dark` |
| `refresh` | `600` | Seconds between feed refreshes. New stories appear at the top of the next pass |
| `category` | none | Limit to a category slug |
| `tag` | none | Limit to a tag slug |
| `kenburns` | on | `kenburns=0` turns off the slow photo push |
| `direct` | off | `direct=1` skips the PHP proxy and queries WordPress from the browser |

Examples:

```
index.html?site=ece&count=6&dwell=10
index.html?site=ccee&theme=dark
index.html?site=engr&count=4&dwell=15&category=research
```

---

## The Instagram slide

A second slide shows recent Instagram posts as four white cards on a Wolfpack Red
field. Instagram posts are square, so the news slide's split layout does not
apply, and captions are short by design because hashtag-heavy caption text reads
poorly from across a lobby.

```
https://billboard.engr.it/news-slides/instagram.html?site=csc&count=4
```

Billboard settings: 20 seconds, reload **Yes**. There is nothing to cycle through,
so the duration is just how long you want it on screen.

### It needs no Meta credentials

This is the part worth understanding before anyone offers to "get you an API key."

The obvious approach is the Instagram API. That path requires the account
converted to a Business or Creator account, a Meta developer app, App Review to
go live, and a long-lived token that expires every 60 days and must be
programmatically refreshed. For unattended signage that last item is the real
risk: the board goes blank two months after launch and nobody knows why.

None of that is necessary, because csc.ncsu.edu and ece.ncsu.edu already run
Smash Balloon Instagram Feed Pro. That plugin already holds the credentials and
already refreshes its own token. `api/instagram.php` reads the feed the plugin
has already rendered on the department's own site and normalizes it to JSON.

The tradeoff is that this parses Smash Balloon's markup, so a major plugin update
could change it. That failure is soft: the proxy keeps serving its last good
cache for up to twelve hours, and the news slide is unaffected.

### Images are mirrored, not hotlinked

Instagram CDN URLs are signed and expire. A slide that linked to them directly
would show broken images the moment a signature lapsed. Every image is copied
into the cache directory instead and served through `api/image.php`, which only
reads files already mirrored and never fetches anything itself.

### Why the Instagram cache is not keyed on count

One cache per source page serves every `count`. It used to be keyed on the
count as well, which quietly meant the scheduled warm-up warmed one count and a
slide asking for any other fell through to the slow path: half a megabyte of
markup plus four Instagram images, fetched while a display waited. If that
coincided with a slow origin, the display showed its error card.

Image mirroring also happens after the response has been flushed, or during a
`refresh=1` warm-up run where nothing is waiting. The first request after a
cache expiry answers with Instagram CDN URLs, which work fine for hours, and
picks up the mirrored copies on the next pass.

### Instagram URL parameters

| Parameter | Default | Notes |
| --- | --- | --- |
| `site` | `csc` | Key that appears in both `sites` and `instagram` in `config.php` |
| `count` | `4` | Posts to show, 1 to 8. Four fits the grid cleanly |
| `refresh` | `900` | Seconds between refreshes |
| `label` | none | Override the small line above the handle |

### When an Instagram slide will not load

`api/instagram.php` answers with a 502 and an error that names what it actually
saw. Add `&debug=1` to get the same information as a normal response:

```
https://billboard.engr.it/news-slides/api/instagram.php?site=ece&debug=1
```

```json
{ "fetched_bytes": 580122, "sbi_item": 4, "data_full_res": 4,
  "plugin_present": true, "posts_parsed": 4 }
```

Read it like this:

- **`fetched_bytes` is 0, or the error says "Could not load"** - this server
  could not reach that page in time. ece.ncsu.edu does this intermittently,
  answering in under a second most of the time and timing out past fifteen
  occasionally. Once any cache exists this is invisible, because a stale copy is
  served instantly and the refresh happens behind it. If it happens on a first
  ever request, run the warm-up and try again. Check outbound access and whether
  a WAF is treating the server differently from a browser.
- **Bytes came back but `sbi_item` is 0** - first compare `fetched_bytes`
  against the page's real size. If it is roughly a seventh of it, you are
  looking at a compressed body that was never decoded, and the marker count is
  zero because the parser is scanning binary. That was a real bug here:
  ece.ncsu.edu sits behind Sucuri, which returns `content-encoding: gzip` even
  for a request that omits Accept-Encoding, while csc.ncsu.edu behind Cloudflare
  honours the omission. So CSC worked and ECE returned "0 markers in 73,053
  bytes", which was exactly the compressed form of a 580,122 byte page.
  `http_get()` now decodes on the magic bytes, but if `zlib` is missing from PHP
  entirely it cannot, and `tools/diagnose.php` reports that. Otherwise: `path`
  points at the wrong page, or the site serves this server a different variant
  than it serves a browser.
- **`sbi_item` is greater than 0 but `posts_parsed` is 0** - the plugin's markup
  has changed. Send the numbers along and the parser can be adjusted.

The parser splits posts with `explode()` rather than one lazy regex across the
whole document, and falls back to scanning for images directly if the wrapper
markup is not what it expects, so a plugin update degrades rather than fails.

### Which departments work today

The slide can only read a feed the department's own site actually publishes.

| Key | Handle | Status |
| --- | --- | --- |
| `csc` | @NCStateCS | Working, four posts from the homepage |
| `ece` | @NCStateECE | Working, four posts from the homepage |
| `ccee` | @NCStateCCEE | Working, three posts from the homepage |
| `engr` | @NCStateEngr | Plugin active but no feed rendered. Needs a shortcode page |
| `mae` | @NCStateMAE | Smash Balloon not installed |
| `ne` | @NCStateNuclear | Smash Balloon not installed |
| `cbe` | @NCStateCBE | Smash Balloon not installed |
| `mse` | @NCStateMSE | Smash Balloon not installed |
| `ise` | @NCStateISE | Smash Balloon not installed |
| `bme` | none found | No Instagram link on the site |

engr.ncsu.edu is the quick win: the plugin is already active and authenticated,
it just does not render a feed on the homepage. Add a page holding only
`[instagram-feed num=8]`, set it to noindex, then uncomment the `engr` line in
`config.php` and point `path` at that page.

The six without the plugin need it installed and authenticated on their own
site. That is a WordPress task on their end; nothing in this repository changes.
The handles above came from the Instagram links in each site's footer, so the
accounts exist, but confirm them with each department before going live.

The slide sizes itself to whatever comes back. Three posts render as three
centred tiles at the same size as four, rather than stretching to fill the width,
which would make each photo taller and squeeze the captions.

### Adding a department to the Instagram slide

Add an entry to the `instagram` array in `config.php`:

```php
'mae' => ['handle' => 'NCStateMAE', 'path' => '/'],
```

Capitalise the handle the way the department styles the account
(`NCStateMAE`, not `ncstatemae`). Instagram usernames are case-insensitive in
URLs, so the cased form is used for the on-screen handle, the follow line and
the profile link alike.

`path` is the page on that department's site that renders the feed. The homepage
works and needs nothing created. A dedicated page carrying only the
`[instagram-feed num=8]` shortcode is better if you want more than the four posts
the homepage widget shows, or if you want the slide insulated from homepage
redesigns. Set that page to noindex and point `path` at it.

Captions are trimmed: trailing hashtag blocks and "link in bio" tails are
removed, but hashtags used mid-sentence are kept, because deleting `#NCStateCS`
from "Congrats to #NCStateCS student ..." breaks the sentence. Set
`clean_captions` to `false` in `config.php` for verbatim captions.

---

## The events slide

Upcoming events on Centennial Campus, as an agenda: a date chip, the event, the
time and the venue, with the event type set at the row's right edge in that
type's accent color so the colored chips read as a labeled system.

```
https://billboard.engr.it/news-slides/events.html?count=6
```

Billboard settings: 25 seconds, reload **Yes**. Nothing cycles, so the duration
is just how long you want it up.

### Defining "Centennial Campus"

The calendar has no campus to filter on. `campus_id` is null on every event and
there is no campus filter set to query, so the campus is a bounding box of venue
coordinates, set in `config.php`.

The box was calibrated from real venue coordinates rather than a map:

| | Latitude |
| --- | --- |
| Hunt Library | 35.7693 |
| Monteith Engineering Research Center | 35.7694 |
| Wilson College of Textiles | 35.7700 |
| The Oval | 35.7706 |
| Partners I | 35.7711 |
| Venture Place | 35.7723 |
| Venture III | 35.7734 |
| *Carmichael Gym (main campus)* | *35.7839* |
| *Talley Student Union (main campus)* | *35.7841* |

The two campuses separate with about a thousand feet to spare, so the box
(35.758 to 35.780) is in no danger of catching main campus. Its western edge
also excludes the Centennial Biomedical Campus over on Blue Ridge Road, which is
a different place despite the name.

Venue coordinates are populated on 48 of 50 events sampled. The handful without
them are dropped rather than guessed at.

### What gets filtered out

`exclude_types` in `config.php` drops Workshops and Training plus Information
Sessions. The Graduate School posts a lot of those and they read as internal
business rather than something a passer-by would act on. Remove entries from
that array to let them back in.

Recurring series are collapsed to one entry each, and the instance shown is the
next one that has not finished, not the first one the API lists, which for a
weekly series is usually in the past.

### Event-type accent colors

Like the news slide's per-department colors, the date chip here (and the
eyebrow/bar on the cycling variant below) varies by Localist event type,
drawn from `config.php`'s `events.type_colors`:

| Event type | Color | Hex |
| --- | --- | --- |
| Community Events | Carmichael Aqua | `#008473` |
| Student Life | Bio-Indigo | `#4156A1` |
| Tours and Open Houses | Innovation Blue | `#427E93` |
| Exhibitions | Pyroman Flame | `#D14905` |
| Lectures and Talks | Reynolds Red | `#990000` |
| Meetings and Conferences | Genomic Green | `#6F7D1C` |
| Performances | Hunt Yellow (shade) | `#966D00` |

A type not listed (Academic Calendar, High-Impact Experiences, and other rare,
mostly administrative categories) falls back to Wolfpack Red, same as an event
whose type is missing entirely. `api/events.php` resolves the color server
side and returns it as `typeColor`, so both events slides read one source of
truth.

### Events URL parameters

| Parameter | Default | Notes |
| --- | --- | --- |
| `count` | `6` | Events to show, 1 to 8. Rows divide the canvas, so any count fills it |
| `days` | `21` | How far ahead to look, overriding `config.php` |
| `refresh` | `900` | Seconds between refreshes |
| `title` | none | Override the headline |
| `eyebrow` | none | Override the small line above it |

Times follow AP style: lowercase with periods, no `:00` on the hour, and the
words noon and midnight rather than 12 p.m. and 12 a.m. Events today and
tomorrow say so.

### The cycling variant: events-cycle.html

A second version of the events slide for when a photo carries more weight than
a list. It shows one event at a time with its featured image and description,
using the same split media/panel layout, crossfade, Ken Burns push, progress
bar and dot indicators as the news slide (`index.html`).

```
https://billboard.engr.it/news-slides/events-cycle.html?count=5&dwell=12
```

Billboard settings: reload **Yes**, duration long enough to cover a full pass
(`count × dwell`, so 60 seconds for the defaults above).

It reads the same `api/events.php` proxy as the agenda version, just with two
more fields per event: `image` (Localist's `photo_url`) and `excerpt`
(`description_text`, trimmed to `excerpt_max` characters the same way news
abstracts are). Unlike Instagram photos, Localist's image URLs are not signed
and do not expire (`localist-images.azureedge.net/photos/...`), so they are
linked directly rather than mirrored through `api/image.php`. An event whose
photo fails to load falls back to a plain Wolfpack Red panel, same as a
newsless post on the news slide.

Because both events slides share one cache (keyed on the host and day window,
not on count or which fields are used), warming one warms the other — no
changes were needed in `tools/warm.sh`.

| Parameter | Default | Notes |
| --- | --- | --- |
| `count` | `5` | Events to cycle through, 1 to 8 |
| `dwell` | `12` | Seconds each event stays on screen |
| `days` | `21` | How far ahead to look, overriding `config.php` |
| `refresh` | `900` | Seconds between feed refreshes |
| `theme` | `light` | `dark` for a black panel and white text |
| `kenburns` | on | Set to `0` to disable the slow photo push |
| `eyebrow` | the event's type | Override the small line above the headline. Defaults to the event's type (colored to match), falling back to "Upcoming on Centennial Campus" for untyped events |

---

## Keeping the caches warm

Worth doing, and it takes two minutes.

These department sites sit behind Cloudflare. A cold response to the feed query
takes close to **thirty seconds** to build, while a warm one takes under half a
second. `feed.php` already handles this with stale-while-revalidate: it serves
the cached copy instantly and refreshes after the display has been answered, so a
billboard never waits. But the very first request after a deploy still pays full
price, and stale-while-revalidate needs PHP-FPM, which Plesk uses by default.

`tools/warm.sh` removes the question entirely. Edit `BILLBOARD_URL` at the top
(and `SITES` / `IG_SITES` if you do not want all of them), then in Plesk go to
**Websites & Domains > Scheduled Tasks > Add Task**:

- Task type: **Run a command**
- Command: `/bin/sh /var/www/vhosts/ece.ncsu.edu/billboard.engr.it/news-slides/tools/warm.sh`
- Run: every 10 minutes (`*/10 * * * *`)

No arguments needed. `BILLBOARD_URL` already defaults to the live deployment.

Every display then hits a warm cache every time.

A warm-up run gets much longer limits than a display request
(`warm_http_timeout` and `warm_time_budget`, 45 and 120 seconds against 15 and
20). Nothing is waiting on it, so it can afford to sit through a cold origin
that a display never should: cbe.ncsu.edu takes about twenty-eight seconds to
build its feed response cold, and was failing the warm run under the display
limits, leaving no cache behind for the displays that came after.

Each run writes a report to the cache directory, and `tools/diagnose.php` shows
it at the top: when it last ran, how many feeds succeeded, and which failed.
Without that, a warm-up that was never scheduled and one that runs and fails
look identical from the outside, since both just leave stale caches.

A stale cache is not automatically a broken warm-up, so read the two together.
A department that is stale while others are warm is usually one that is simply
not in the `SITES` list.

---

## Deploying on Plesk

The whole project is static files plus one PHP script. There is no build step, no
database and no dependencies to install.

**Requirements:** PHP 7.4 or newer with either the cURL extension or
`allow_url_fopen`. A stock Plesk PHP handler has both.

### Deploy with the Plesk Git extension

The live deployment is <https://billboard.engr.it/news-slides/>, served from
`/var/www/vhosts/ece.ncsu.edu/billboard.engr.it/news-slides`.

1. In Plesk, open the domain and choose **Git > Add Repository**.
2. Choose **Remote Git hosting** and paste
   `https://github.com/charles-hall/engr-news-billboard.git`. If the repository is
   private, add the deploy key Plesk generates to the repository's
   **Settings > Deploy keys** on GitHub.
3. Set the deployment path. Use `httpdocs/billboard` to serve the slides from a
   subfolder, or `httpdocs` to serve them from the domain root.
4. Set **Deployment mode** to **Automatic** so a push to `main` publishes.
5. Push to GitHub. Plesk pulls and the slide is live.

Confirm the feed works before adding anything to the billboard:

```
https://billboard.engr.it/news-slides/api/feed.php?site=csc&count=5
```

You should get JSON with five posts. If you get an error, see Troubleshooting.

### Deploy without Git

Copy the repository contents to the target folder over SFTP. Same result, but
someone has to remember to do it again next time.

### The cache directory

Cached feeds and mirrored images go to `cache/` inside the deployment, which the
PHP user needs to be able to write. It is in `.gitignore`, so a `git pull` never
touches it, and the contents are public news and public Instagram posts, so
nothing there matters if it is served.

It deliberately does not use the system temp directory. PHP-FPM on Plesk often
runs with systemd `PrivateTmp`, which means restarting the pool destroys
everything under `/tmp`. Restarting the pool is exactly what enabling a PHP
extension does, so a routine change wipes every cache on the box and every feed
gets refetched from a cold origin at once, which is the stampede all of this
caching exists to prevent. It cost us that once.

If `cache/` cannot be created, `config.php` falls back to the temp directory so
the slides keep working on a read-only deployment.

---

## Adding a department

Edit `config.php` and add an entry to the `sites` array:

```php
'bme' => ['host' => 'bme.ncsu.edu', 'label' => 'Biomedical Engineering'],
```

Then use `?site=bme`. Set `label` to `null` to use whatever site title the
WordPress REST API reports instead.

The allowlist is a security control, not a convenience. `api/feed.php` refuses any
host that is not listed and not on `ncsu.edu`, so the proxy can never be pointed at
an arbitrary URL. Add departments here rather than accepting a hostname from the
query string.

To use the browser-only fallback (`?direct=1`) for a new department, add the same
entry to `DIRECT_HOSTS` at the top of `assets/slide.js`.

---

## How it holds up on a wall

A billboard runs unattended for months, so the failure modes matter more than the
happy path.

- **Caching.** `api/feed.php` caches each feed for ten minutes. A hundred players
  showing the same department generate roughly six requests an hour against the
  department site, not thousands.
- **Stale content beats no content.** If a department site is down or slow, the
  proxy serves the last good feed for up to twelve hours rather than an error card.
- **RSS fallback.** Not every department exposes the REST API at all times.
  `ece.ncsu.edu` spent a stretch behind a plugin that answered every REST route
  with `401 MISSING_AUTHORIZATION_HEADER`. When REST returns nothing,
  `feed.php` reads `/feed/` instead. The NC State theme puts the featured
  image, its alt text and the excerpt directly in the RSS `<description>`, so no
  content is lost. The `source` field in the JSON says which path was used.
- **Fallback fetch.** If the PHP proxy itself is unreachable, the page queries the
  WordPress REST API straight from the browser. WordPress sends permissive CORS
  headers on REST reads, so this works today and is there for the day PHP is not.
  Note this browser-side path has no RSS fallback, so a REST-locked department
  depends on the proxy being up.
- **Retries.** A page that fails every path shows a branded card and retries every
  minute, on its own, without anyone visiting the display.
- **Missing photos.** Posts without a featured image are skipped by default. If one
  is included and its photo 404s, the panel falls back to Wolfpack Red rather than a
  black rectangle.
- **Self-hosted type.** The Roboto faces ship with the repo. A player that boots
  before the network settles still renders in the brand face.
- **Resolution independence.** The canvas is always 1920x1080 and gets scaled to the
  player's viewport, so the same URL is safe on a 4K panel or in a preview window.

---

## Editorial and brand notes

- Dates are formatted in AP style, abbreviating months of six or more letters:
  `Jan. 5, 2026`, `March 5, 2026`, `Sept. 5, 2026`.
- Excerpts are stripped of markup, WordPress "Continue reading" tails and `[...]`
  suffixes, then trimmed to 260 characters on a word boundary.
- Spaces around em and en dashes in excerpt text are closed to match College of
  Engineering house style. This is typographic only, the wording is untouched. Set
  `tighten_dashes` to `false` in `config.php` to reproduce site copy verbatim.
- Core colors are Wolfpack Red `#CC0000`, Wolfpack Black and Wolfpack White, with
  brand grays for secondary text; body text on white clears WCAG AA. The eyebrow
  and accent bar vary per department -- see "Department accent colors" above.
- The dark theme uses white for the eyebrow regardless of department, since
  several accent colors do not clear AA contrast on black at that size.
- Featured image alt text from WordPress is carried through to the `img` element.

---

## Troubleshooting

**`api/feed.php` returns "Unknown site key"**
The `site` parameter is not in the `sites` array in `config.php`. Check spelling.

**"Could not read posts over REST or RSS"**
Both paths failed. The server may not be able to make outbound HTTPS requests, or
the department site may be down. Test from the server:

```bash
curl -sI https://DEPT.ncsu.edu/wp-json/wp/v2/posts
curl -sI https://DEPT.ncsu.edu/feed/
```

If the feed responds but `feed.php` still fails, the site probably does not put a
featured image in its RSS. Add `&require_image=0` to confirm, and see the note on
`require_image` below.

**Something is wrong and you want to see it from the server's point of view**

Open `tools/diagnose.php` in a browser:

```
https://billboard.engr.it/news-slides/tools/diagnose.php
```

It tests every configured department from the server itself and reports PHP
settings, cache writability, and per-department REST and RSS timings. Timings
from your own server are the only ones that matter: a department site can answer
a laptop in half a second and a data centre in thirty. Cells over eight seconds
are shaded. The page reads only and changes nothing; delete it once the
deployment has settled.

**Pinning a department to one path**

Add `source` to its entry in `config.php` when you know which path works:

```php
'ece' => ['host' => 'ece.ncsu.edu', 'label' => '...', 'source' => 'rss'],
```

`auto` (the default) tries REST then falls back to RSS. `rss` or `rest` skips
the other entirely.

ECE was pinned to `rss` for a while because its REST API returned 401 on every
route, and on a cold cache those two doomed attempts were enough to push the
request past the point where a display gives up waiting. That plugin has since
been deactivated, so ECE is back on `auto` and takes REST at about 1.6 seconds
cold. Pin a site only when you have confirmed one path genuinely does not work,
and unpin it when that changes.

**A department returns 401 on REST**
Handled, and worth understanding rather than panicking about. `ece.ncsu.edu` did
this for a while: `401 MISSING_AUTHORIZATION_HEADER` on every REST route, because
a plugin required an `Authorization` header. The proxy falls back to RSS on its
own and the slide keeps working. Check which path a department is on:

```bash
curl -s "https://billboard.engr.it/news-slides/api/feed.php?site=ece" | grep -o '"source":"[a-z]*"'
```

Nothing needs to change on the department's site, and when someone later opens
REST back up the proxy starts preferring it again with no edit here, which is
exactly what happened on ECE.

**Slow first load on a quiet department site**
A cold CDN cache can take six or seven seconds to answer the first REST request.
`http_timeout` is set to 12 seconds for that reason, and only one request per
`cache_ttl` ever waits.

**The slide shows "News is unavailable"**
Open `api/feed.php?site=csc` directly in a browser. If that returns JSON, the
problem is in the page; check the browser console. If it returns an error, the
problem is server side.

**Headlines look wrong or fall back to Arial**
Confirm `assets/fonts/` deployed. Some SFTP clients skip `.woff2` files.

**A headline is cut off or runs under the date**
Confirm `assets/fit.js` deployed and that `index.html` loads it before
`slide.js`; without it the headline keeps its full 78px and overruns. See
"Fitting the headline" above for how the sizing works.

**Stories are out of date**
The feed cache is ten minutes and the page refreshes every ten. Force a fresh pull
with `api/feed.php?site=csc&refresh=1`.

**A story appears with no photo**
`require_image` is `true` by default, so this should not happen. If it does, the
featured image URL is returning an error. Check it directly.

---

## Repository layout

```
index.html             the news slide
instagram.html         the Instagram slide
events.html            the events slide (agenda list)
events-cycle.html      the events slide (cycling photo variant)
assets/slide.css       layout and brand styling, shared stage
assets/slide.js        news fetch, rotation, scaling, AP dates
assets/fit.js          sizes headlines to the panel, shared by both slide types
assets/instagram.css   Instagram grid styling
assets/instagram.js    Instagram fetch and rendering
assets/events.css      agenda styling
assets/events.js       events fetch, AP dates and times (agenda list)
assets/events-cycle.js events fetch, rotation, AP dates (cycling variant; reuses slide.css)
assets/fonts.css       self-hosted Roboto family
assets/fonts/          woff2 files (SIL Open Font License 1.1)
api/lib.php            shared helpers: fetch, cache, text cleanup
api/feed.php           cached WordPress REST proxy with RSS fallback
api/instagram.php      Instagram proxy, reads Smash Balloon, mirrors images
api/events.php         events proxy, reads calendar.ncsu.edu and geofences
api/image.php          serves mirrored Instagram images
config.php             department allowlist and defaults
tools/warm.sh          scheduled cache warm-up, see above
tools/diagnose.php     open in a browser to test every feed from the server
tools/screenshot.js    development only, renders a slide headless for review
```

`tools/screenshot.js` is not needed in production. `tools/warm.sh` is, if you set
up the scheduled task.

---

## Local development

```bash
php -S 127.0.0.1:8080 -t .
open http://127.0.0.1:8080/index.html?site=csc&dwell=6
```

To capture what the slide actually renders:

```bash
npm install playwright
node tools/screenshot.js "http://127.0.0.1:8080/index.html?site=csc&dwell=6" 5 6000
```

---

## Credits

Built for NC State University College of Engineering Communications.
Typefaces: Roboto, Roboto Condensed and Roboto Slab, SIL Open Font License 1.1.
Brand standards: [brand.ncsu.edu](https://brand.ncsu.edu).
