/* ==========================================================================
   NC State Billboard News and Instagram Loop
   Cycles a department's most recent news stories and shows its Instagram wall
   between them, all from one billboard URL.

   Built from the same pieces as index.html and instagram.html: the news
   stories use slide.css and fit.js, the wall uses instagram.css, and both read
   the same PHP proxies (api/feed.php and api/instagram.php), so the caches the
   scheduled warm-up keeps fresh serve this page too.

   URL parameters (all optional):
     site=csc            key from config.php        (default: csc)
     count=15            news stories in the loop   (1-15)
     dwell=12            seconds per news story     (4-120)
     every=5             show the Instagram wall after every N stories
                         (0 turns the wall off)
     igdwell=15          seconds the Instagram wall stays up (4-120)
     igcount=4           Instagram posts on the wall (1-6)
     theme=light|dark    news slide theme, default light
     refresh=600         seconds between feed refreshes
     category=slug       limit news to a category slug
     tag=slug            limit news to a tag slug
     kenburns=0          disable the slow photo push
     label=...           override the small line above the Instagram handle

   Example, the Computer Science loop:
     loop.html?site=csc
   That is 15 stories at 12 seconds plus 3 Instagram walls at 15 seconds:
   225 seconds per pass.
   ========================================================================== */

(function () {
  'use strict';

  var params = new URLSearchParams(window.location.search);

  var CONFIG = {
    site:     (params.get('site') || 'csc').toLowerCase().replace(/[^a-z0-9_-]/g, ''),
    count:    clamp(parseInt(params.get('count'), 10) || 15, 1, 15),
    dwell:    clamp(parseFloat(params.get('dwell')) || 12, 4, 120),
    every:    params.has('every') ? clamp(parseInt(params.get('every'), 10), 0, 15) : 5,
    igDwell:  clamp(parseFloat(params.get('igdwell')) || 15, 4, 120),
    igCount:  clamp(parseInt(params.get('igcount'), 10) || 4, 1, 6),
    theme:    params.get('theme') === 'dark' ? 'dark' : 'light',
    refresh:  clamp(parseInt(params.get('refresh'), 10) || 600, 60, 86400),
    category: (params.get('category') || '').replace(/[^a-z0-9_-]/gi, ''),
    tag:      (params.get('tag') || '').replace(/[^a-z0-9_-]/gi, ''),
    kenburns: params.get('kenburns') !== '0',
    label:    params.get('label') || ''
  };

  var AP_MONTHS = ['Jan.', 'Feb.', 'March', 'April', 'May', 'June',
                   'July', 'Aug.', 'Sept.', 'Oct.', 'Nov.', 'Dec.'];
  var TZ = 'America/New_York';

  // Must match instagram.css and instagram.js.
  var GRID_WIDTH = 1808;
  var GRID_GAP   = 26;
  var TILE_MAX   = 434;

  var stage        = document.getElementById('stage');
  var deck         = document.getElementById('deck');
  var dotsWrap     = document.getElementById('dots');
  var progress     = document.getElementById('progress');
  var footerSource = document.getElementById('footerSource');
  var slideTpl     = document.getElementById('slideTemplate');

  var igSlide  = document.getElementById('igSlide');
  var igGrid   = document.getElementById('igGrid');
  var igEyebrow = document.getElementById('igEyebrow');
  var igHandle = document.getElementById('igHandle');
  var igFollow = document.getElementById('igFollow');
  var igTpl    = document.getElementById('igCardTemplate');

  var newsSlides = [];      // DOM nodes, one per story
  var sequence   = [];      // [{type:'news', i:n} | {type:'ig'}]
  var position   = 0;
  var timer      = null;
  var haveIg     = false;

  // Refreshed data waits here and is swapped in at the top of the next pass,
  // so nothing on screen changes mid-story.
  var pendingNews = null;
  var pendingIg   = null;

  var statusEl = null;

  /* --------------------------------------------------------------- helpers */

  function clamp(n, lo, hi) {
    if (isNaN(n)) { return lo; }
    return Math.min(hi, Math.max(lo, n));
  }

  function scaleStage() {
    var s = Math.min(window.innerWidth / 1920, window.innerHeight / 1080);
    stage.style.transform = 'translate(-50%, -50%) scale(' + s + ')';
  }

  /** WordPress date in AP style, read as the site's local date. */
  function apDateLocal(iso) {
    var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(iso || ''));
    if (!m) { return ''; }
    return AP_MONTHS[parseInt(m[2], 10) - 1] + ' ' + parseInt(m[3], 10) + ', ' + m[1];
  }

  /** Instagram timestamps arrive in UTC; show the Raleigh date. */
  function apDateUtc(iso) {
    var d = new Date(iso || '');
    if (isNaN(d.getTime())) { return ''; }
    try {
      var parts = new Intl.DateTimeFormat('en-US', {
        timeZone: TZ, year: 'numeric', month: 'numeric', day: 'numeric'
      }).formatToParts(d);
      var get = function (t) {
        var f = parts.filter(function (p) { return p.type === t; })[0];
        return f ? parseInt(f.value, 10) : 0;
      };
      var month = AP_MONTHS[get('month') - 1];
      return month ? month + ' ' + get('day') + ', ' + get('year') : '';
    } catch (e) {
      return '';
    }
  }

  function getJson(url) {
    return fetch(url, { cache: 'no-store' }).then(function (r) {
      if (!r.ok) { throw new Error('proxy ' + r.status); }
      return r.json();
    });
  }

  /* ------------------------------------------------------------------ data */

  function loadNews() {
    var q = new URLSearchParams({ site: CONFIG.site, count: String(CONFIG.count) });
    if (CONFIG.category) { q.set('category', CONFIG.category); }
    if (CONFIG.tag) { q.set('tag', CONFIG.tag); }
    q.set('_', String(Math.floor(Date.now() / 30000)));
    return getJson('api/feed.php?' + q.toString()).then(function (data) {
      if (!data || !data.posts || !data.posts.length) { throw new Error('empty feed'); }
      return data;
    });
  }

  function loadIg() {
    var q = new URLSearchParams({ site: CONFIG.site, count: String(CONFIG.igCount) });
    q.set('_', String(Math.floor(Date.now() / 60000)));
    return getJson('api/instagram.php?' + q.toString()).then(function (data) {
      if (!data || !data.posts || !data.posts.length) { throw new Error('no posts'); }
      return data;
    });
  }

  /* -------------------------------------------------------------- building */

  function buildNewsSlide(post, siteName, accent) {
    var node = slideTpl.content.firstElementChild.cloneNode(true);
    var img = node.querySelector('.media-img');

    if (accent) { node.style.setProperty('--dept-accent', accent); }

    if (post.image) {
      img.src = post.image;
      img.alt = post.alt || '';
      if (CONFIG.kenburns) { img.classList.add('kenburns'); }
      img.addEventListener('error', function () { node.classList.add('no-image'); });
    } else {
      node.classList.add('no-image');
    }

    node.querySelector('.eyebrow').textContent  = siteName;
    node.querySelector('.headline').textContent = post.title;
    node.querySelector('.date').textContent     = apDateLocal(post.dateISO);
    node.querySelector('.abstract').textContent = post.excerpt || '';
    return node;
  }

  function renderNews(data) {
    var site = data.site || {};
    var siteName = site.name || 'NC State University';

    deck.innerHTML = '';
    dotsWrap.innerHTML = '';
    newsSlides = [];

    data.posts.slice(0, CONFIG.count).forEach(function (post) {
      var node = buildNewsSlide(post, siteName, site.accent || '');
      deck.appendChild(node);
      newsSlides.push(node);

      var dot = document.createElement('span');
      dot.className = 'dot';
      dotsWrap.appendChild(dot);
    });

    footerSource.textContent = site.host ? 'News from ' + site.host : siteName;
    NCStateFit.fitWhenReady(newsSlides);
  }

  function renderIg(data) {
    var site = data.site || {};
    var handle = site.handle || CONFIG.site;

    igEyebrow.textContent = CONFIG.label || site.label ||
      (site.host ? site.host.replace(/\.ncsu\.edu$/, '').toUpperCase() : 'NC State University');
    igHandle.textContent = '@' + handle;
    igFollow.textContent = 'Follow along at instagram.com/' + handle;

    igGrid.innerHTML = '';
    var posts = data.posts.slice(0, CONFIG.igCount);
    var n = posts.length;
    var tile = Math.min(TILE_MAX, Math.floor((GRID_WIDTH - (n - 1) * GRID_GAP) / n));
    igGrid.style.gridTemplateColumns = 'repeat(' + n + ', ' + tile + 'px)';

    posts.forEach(function (post) {
      var card = igTpl.content.firstElementChild.cloneNode(true);
      var img = card.querySelector('.ig-img');
      img.src = post.image;
      img.alt = '';
      img.addEventListener('error', function () { card.remove(); });
      card.querySelector('.ig-caption').textContent = post.caption || '';
      card.querySelector('.ig-date').textContent    = apDateUtc(post.dateISO);
      igGrid.appendChild(card);
    });

    haveIg = n > 0;
  }

  /**
   * The running order for one pass: N stories, the wall, N more, the wall...
   * The wall also closes the pass when the story count is a multiple of N, so
   * with the defaults it appears after stories 5, 10 and 15. If Instagram is
   * unavailable the loop is simply news, back to back.
   */
  function buildSequence() {
    sequence = [];
    newsSlides.forEach(function (_, i) {
      sequence.push({ type: 'news', i: i });
      if (haveIg && CONFIG.every > 0 && (i + 1) % CONFIG.every === 0) {
        sequence.push({ type: 'ig' });
      }
    });
    if (!newsSlides.length && haveIg) { sequence.push({ type: 'ig' }); }
  }

  /* -------------------------------------------------------------- rotation */

  function show(pos) {
    var step = sequence[pos];
    if (!step) { return; }

    var seconds;

    if (step.type === 'ig') {
      igSlide.classList.add('is-active');
      seconds = CONFIG.igDwell;
    } else {
      var i = step.i;
      NCStateFit.fit(newsSlides[i]);
      newsSlides.forEach(function (s, n) { s.classList.toggle('is-active', n === i); });
      Array.prototype.forEach.call(dotsWrap.children, function (d, n) {
        d.classList.toggle('is-active', n === i);
      });

      progress.classList.remove('run');
      void progress.offsetWidth;
      progress.classList.add('run');

      igSlide.classList.remove('is-active');
      seconds = CONFIG.dwell;
    }

    clearTimeout(timer);
    timer = setTimeout(next, seconds * 1000);
  }

  function next() {
    position += 1;
    if (position >= sequence.length) {
      position = 0;
      applyPending();
    }
    show(position);
  }

  function applyPending() {
    var changed = false;
    if (pendingNews) { renderNews(pendingNews); pendingNews = null; changed = true; }
    if (pendingIg)   { renderIg(pendingIg);     pendingIg = null;   changed = true; }
    if (changed) { buildSequence(); }
  }

  /* ---------------------------------------------------------------- status */

  function showStatus(title, detail) {
    if (!statusEl) {
      statusEl = document.createElement('div');
      statusEl.className = 'status';
      statusEl.innerHTML = '<h2></h2><p></p>';
      stage.appendChild(statusEl);
    }
    statusEl.querySelector('h2').textContent = title;
    statusEl.querySelector('p').textContent = detail;
    statusEl.hidden = false;
  }

  function hideStatus() {
    if (statusEl) { statusEl.hidden = true; }
  }

  /* ------------------------------------------------------------------ boot */

  function start() {
    stage.classList.add('theme-' + CONFIG.theme);
    stage.style.setProperty('--dwell', CONFIG.dwell + 's');
    scaleStage();
    window.addEventListener('resize', scaleStage);

    showStatus('Loading news', 'Fetching the latest stories from ' + CONFIG.site + '.ncsu.edu.');

    // Instagram is a garnish: if it fails, the news still runs.
    var igReady = CONFIG.every > 0
      ? loadIg().then(renderIg).catch(function () { haveIg = false; })
      : Promise.resolve();

    function attempt() {
      Promise.all([loadNews(), igReady])
        .then(function (results) {
          renderNews(results[0]);
          buildSequence();
          hideStatus();
          position = 0;
          show(0);
        })
        .catch(function (err) {
          showStatus(
            'News is unavailable',
            'The slide could not load stories right now (' + err.message + '). ' +
            'It will keep trying every minute.'
          );
          setTimeout(attempt, 60000);
        });
    }

    attempt();

    setInterval(function () {
      loadNews().then(function (data) {
        if (!newsSlides.length) {
          renderNews(data); buildSequence(); hideStatus(); position = 0; show(0);
        } else {
          pendingNews = data;
        }
      }).catch(function () { /* keep the current stories */ });

      if (CONFIG.every > 0) {
        loadIg().then(function (data) { pendingIg = data; })
          .catch(function () { /* keep the current wall */ });
      }
    }, CONFIG.refresh * 1000);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
