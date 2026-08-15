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
white panel at the right under a Wolfpack Red rule. Wolfpack Red bars close the top
and bottom of the frame, with a progress line and story dots in the footer.

- Headlines: Roboto Condensed Bold, auto-sized from 78px down to fit
- Abstract: Roboto Regular, 33px, clamped to five lines
- Date: Roboto Slab, AP style ("Aug. 14, 2026")
- All three faces are self-hosted, so the slide never waits on an outside CDN

---

## Quick start

```
https://YOUR-SERVER/billboard/index.html?site=csc&count=5&dwell=12
```

That URL cycles the five newest Computer Science stories, twelve seconds each.

### Adding it to billboard.ncsu.edu

In the **Add URL** dialog:

| Field | Value |
| --- | --- |
| URL | `https://YOUR-SERVER/billboard/index.html?site=csc&count=5&dwell=12` |
| Title | Computer Science News |
| Number of seconds to display slide | `60` |
| Do you want the slide to reload after it has been displayed? | **Yes** |

Set the duration to **count x dwell**. Five stories at twelve seconds is sixty
seconds. Reload set to Yes guarantees the deck restarts at story one and picks up
anything published since the last pass.

Leave the Slide Scheduler fields blank unless the department wants news to run only
during certain hours.

---

## URL parameters

| Parameter | Default | Notes |
| --- | --- | --- |
| `site` | `csc` | Key from `config.php`: `csc`, `ece`, `mae`, `ne`, `ccee`, `engr` |
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

## Deploying on Plesk

The whole project is static files plus one PHP script. There is no build step, no
database and no dependencies to install.

**Requirements:** PHP 7.4 or newer with either the cURL extension or
`allow_url_fopen`. A stock Plesk PHP handler has both.

### Deploy with the Plesk Git extension

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
https://YOUR-SERVER/billboard/api/feed.php?site=csc&count=5
```

You should get JSON with five posts. If you get an error, see Troubleshooting.

### Deploy without Git

Copy the repository contents to the target folder over SFTP. Same result, but
someone has to remember to do it again next time.

### File permissions

Nothing needs to be writable in the web root. Cached feeds go to the system temp
directory, which PHP can already write to. To move the cache somewhere else, change
`cache_dir` in `config.php` and make sure the PHP user can write there.

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
- **RSS fallback.** Not every department exposes the REST API. `ece.ncsu.edu` sits
  behind Sucuri and answers every REST route with `401 MISSING_AUTHORIZATION_HEADER`,
  the signature of a plugin requiring an `Authorization` header. When REST returns
  nothing, `feed.php` reads `/feed/` instead. The NC State theme puts the featured
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
- Colors are Wolfpack Red `#CC0000`, Wolfpack Black and Wolfpack White, with brand
  grays for secondary text. Body text on white clears WCAG AA.
- The dark theme uses white for the department eyebrow rather than Wolfpack Red,
  which does not clear AA contrast on black at that size.
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

**A department returns 401 on REST**
Expected for some sites, and handled. `ece.ncsu.edu` returns
`401 MISSING_AUTHORIZATION_HEADER` on every REST route because a plugin or WAF rule
requires an `Authorization` header. The proxy falls back to RSS on its own and the
slide works normally. Check which path a department is on:

```bash
curl -s "https://YOUR-SERVER/billboard/api/feed.php?site=ece" | grep -o '"source":"[a-z]*"'
```

Nothing needs to change on the department's site. If someone later opens up REST
there, the proxy will start preferring it again with no edit here.

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

**Stories are out of date**
The feed cache is ten minutes and the page refreshes every ten. Force a fresh pull
with `api/feed.php?site=csc&refresh=1`.

**A story appears with no photo**
`require_image` is `true` by default, so this should not happen. If it does, the
featured image URL is returning an error. Check it directly.

---

## Repository layout

```
index.html            the slide
assets/slide.css      layout and brand styling
assets/slide.js       fetch, rotation, scaling, AP dates
assets/fonts.css      self-hosted Roboto family
assets/fonts/         woff2 files (SIL Open Font License 1.1)
api/feed.php          cached WordPress REST proxy
config.php            department allowlist and defaults
tools/screenshot.js   development only, renders the slide headless for review
```

`tools/` is not needed in production and can be excluded from deployment.

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
