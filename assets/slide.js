/* ==========================================================================
   NC State Billboard News Slides
   Pulls the latest posts from a department WordPress site and cycles them on a
   1920x1080 canvas for billboard.ncsu.edu.

   URL parameters (all optional):
     site=csc            key from config.php  (default: csc)
     count=5             number of stories    (1-12)
     dwell=12            seconds per story
     theme=light|dark    default light
     refresh=600         seconds between feed refreshes
     category=slug       limit to a category slug
     tag=slug            limit to a tag slug
     kenburns=0          disable the slow photo push
     direct=1            skip the PHP proxy and query WordPress from the browser

   Example:
     index.html?site=csc&count=5&dwell=12
   ========================================================================== */

(function () {
  'use strict';

  var params = new URLSearchParams(window.location.search);

  var CONFIG = {
    site:     (params.get('site') || 'csc').toLowerCase().replace(/[^a-z0-9_-]/g, ''),
    count:    clamp(parseInt(params.get('count'), 10) || 5, 1, 12),
    dwell:    clamp(parseFloat(params.get('dwell')) || 12, 4, 120),
    theme:    params.get('theme') === 'dark' ? 'dark' : 'light',
    refresh:  clamp(parseInt(params.get('refresh'), 10) || 600, 60, 86400),
    category: (params.get('category') || '').replace(/[^a-z0-9_-]/gi, ''),
    tag:      (params.get('tag') || '').replace(/[^a-z0-9_-]/gi, ''),
    kenburns: params.get('kenburns') !== '0',
    direct:   params.get('direct') === '1'
  };

  // Used only by the browser-side fallback path, so the slide still works if
  // the PHP proxy is unavailable or the files are hosted somewhere static.
  // accent values mirror config.php's per-department 'accent' entries, kept
  // in sync by hand since this path never talks to the PHP proxy.
  var DIRECT_HOSTS = {
    csc:  { host: 'csc.ncsu.edu',   label: 'Computer Science', accent: '#427E93' },
    ece:  { host: 'ece.ncsu.edu',   label: 'Electrical and Computer Engineering', accent: '#4156A1' },
    mae:  { host: 'mae.ncsu.edu',   label: 'Mechanical and Aerospace Engineering', accent: '#D14905' },
    ne:   { host: 'ne.ncsu.edu',    label: 'Nuclear Engineering', accent: '#966D00' },
    ccee: { host: 'ccee.ncsu.edu',  label: 'Civil, Construction and Environmental Engineering', accent: '#6F7D1C' },
    bme:  { host: 'bme.ncsu.edu',   label: 'Biomedical Engineering', accent: '#008473' },
    cbe:  { host: 'cbe.ncsu.edu',   label: 'Chemical and Biomolecular Engineering', accent: '#00716D' },
    mse:  { host: 'mse.ncsu.edu',   label: 'Materials Science and Engineering', accent: '#5B73BB' },
    ise:  { host: 'ise.ncsu.edu',   label: 'Industrial and Systems Engineering', accent: '#C03003' },
    engr: { host: 'engr.ncsu.edu',  label: 'College of Engineering', accent: '#990000' }
  };

  // AP style: abbreviate months of six or more letters when used with a date.
  var AP_MONTHS = ['Jan.', 'Feb.', 'March', 'April', 'May', 'June',
                   'July', 'Aug.', 'Sept.', 'Oct.', 'Nov.', 'Dec.'];

  var stage    = document.getElementById('stage');
  var deck     = document.getElementById('deck');
  var dotsWrap = document.getElementById('dots');
  var progress = document.getElementById('progress');
  var footerSource = document.getElementById('footerSource');
  var template = document.getElementById('slideTemplate');

  var slides = [];
  var index = 0;
  var timer = null;
  var pendingFeed = null;   // new data swapped in at the next loop boundary
  var statusEl = null;

  /* --------------------------------------------------------------- helpers */

  function clamp(n, lo, hi) {
    if (isNaN(n)) { return lo; }
    return Math.min(hi, Math.max(lo, n));
  }

  /** Fit the 1920x1080 stage to whatever viewport the player gives us. */
  function scaleStage() {
    var s = Math.min(window.innerWidth / 1920, window.innerHeight / 1080);
    stage.style.transform = 'translate(-50%, -50%) scale(' + s + ')';
  }

  /**
   * Format a WordPress date string in AP style, e.g. "Aug. 14, 2026".
   * Parsed by regex rather than Date() so the site's local publish date is not
   * shifted by the player's time zone.
   */
  function apDate(iso) {
    var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(iso || ''));
    if (!m) { return ''; }
    var month = AP_MONTHS[parseInt(m[2], 10) - 1] || '';
    var day = parseInt(m[3], 10);
    return month + ' ' + day + ', ' + m[1];
  }

  function stripHtml(html) {
    var d = document.createElement('div');
    d.innerHTML = String(html || '');
    var text = d.textContent || '';
    return text.replace(/ /g, ' ')
               .replace(/\s*(\[(?:…|\.\.\.)\]|Continue reading.*)$/i, '')
               .replace(/\s+/g, ' ')
               // House style: no space on either side of an em or en dash.
               .replace(/\s*([—–])\s*/g, '$1')
               .trim();
  }

  function trimWords(text, max) {
    if (text.length <= max) { return text; }
    var cut = text.slice(0, max);
    var sp = cut.lastIndexOf(' ');
    if (sp > max * 0.6) { cut = cut.slice(0, sp); }
    return cut.replace(/[ ,;:.]+$/, '') + '...';
  }

  /* ------------------------------------------------------------------ data */

  function proxyUrl() {
    var q = new URLSearchParams({
      site: CONFIG.site,
      count: String(CONFIG.count)
    });
    if (CONFIG.category) { q.set('category', CONFIG.category); }
    if (CONFIG.tag) { q.set('tag', CONFIG.tag); }
    q.set('_', String(Math.floor(Date.now() / 30000))); // 30s cache buster
    return 'api/feed.php?' + q.toString();
  }

  function directUrl() {
    var entry = DIRECT_HOSTS[CONFIG.site];
    if (!entry) { return null; }
    var q = new URLSearchParams({
      per_page: String(Math.min(20, CONFIG.count * 3)),
      orderby: 'date',
      order: 'desc',
      _embed: 'wp:featuredmedia'
    });
    if (CONFIG.category) { q.set('category_name', CONFIG.category); }
    if (CONFIG.tag) { q.set('tag', CONFIG.tag); }
    return 'https://' + entry.host + '/wp-json/wp/v2/posts?' + q.toString();
  }

  /** Convert raw WordPress REST posts into the same shape feed.php returns. */
  function normalizeDirect(rawPosts) {
    var entry = DIRECT_HOSTS[CONFIG.site];
    var out = [];

    rawPosts.forEach(function (post) {
      if (out.length >= CONFIG.count) { return; }

      var media = post._embedded &&
                  post._embedded['wp:featuredmedia'] &&
                  post._embedded['wp:featuredmedia'][0];
      var image = null;
      var alt = '';

      if (media && !media.code) {
        var sizes = (media.media_details && media.media_details.sizes) || {};
        ['2048x2048', '1536x1536', 'large', 'full'].some(function (k) {
          if (sizes[k] && sizes[k].source_url) { image = sizes[k].source_url; return true; }
          return false;
        });
        if (!image && media.source_url) { image = media.source_url; }
        alt = stripHtml(media.alt_text || '');
      }

      if (!image) { return; }   // require_image, matching the proxy default

      var title = stripHtml(post.title && post.title.rendered);
      if (!title) { return; }

      out.push({
        id: post.id,
        title: title,
        url: post.link || '',
        dateISO: post.date || '',
        excerpt: trimWords(stripHtml(post.excerpt && post.excerpt.rendered), 260),
        image: image,
        alt: alt
      });
    });

    return {
      site: {
        key: CONFIG.site,
        host: entry.host,
        name: entry.label,
        url: 'https://' + entry.host,
        accent: entry.accent || null
      },
      posts: out
    };
  }

  function loadFeed() {
    var chain = CONFIG.direct
      ? Promise.reject(new Error('direct mode'))
      : fetch(proxyUrl(), { cache: 'no-store' }).then(function (r) {
          if (!r.ok) { throw new Error('proxy ' + r.status); }
          return r.json();
        }).then(function (data) {
          if (!data || !data.posts || !data.posts.length) { throw new Error('empty feed'); }
          return data;
        });

    return chain.catch(function () {
      // Fall back to querying WordPress straight from the browser. WordPress
      // sends permissive CORS headers on REST GETs, so this works today and
      // keeps the slide alive if PHP is ever unavailable.
      var url = directUrl();
      if (!url) { throw new Error('no host configured for "' + CONFIG.site + '"'); }
      return fetch(url, { cache: 'no-store' })
        .then(function (r) {
          if (!r.ok) { throw new Error('wordpress ' + r.status); }
          return r.json();
        })
        .then(function (raw) {
          var data = normalizeDirect(raw || []);
          if (!data.posts.length) { throw new Error('no posts with a featured image'); }
          return data;
        });
    });
  }

  /* -------------------------------------------------------------- building */

  function buildSlide(post, siteName, accent) {
    var node = template.content.firstElementChild.cloneNode(true);
    var img = node.querySelector('.media-img');

    // Per-department brand accent (see config.php). Falls back to Wolfpack
    // Red in CSS when a department has none configured.
    if (accent) { node.style.setProperty('--dept-accent', accent); }

    if (post.image) {
      img.src = post.image;
      img.alt = post.alt || '';
      if (CONFIG.kenburns) { img.classList.add('kenburns'); }
      // A broken photo URL should not leave a black rectangle on the wall.
      img.addEventListener('error', function () { node.classList.add('no-image'); });
    } else {
      node.classList.add('no-image');
    }

    node.querySelector('.eyebrow').textContent  = siteName;
    node.querySelector('.headline').textContent = post.title;
    node.querySelector('.date').textContent     = apDate(post.dateISO);
    node.querySelector('.abstract').textContent = post.excerpt || '';

    return node;
  }

  function render(data) {
    var siteName = (data.site && data.site.name) || 'NC State University';
    var host = (data.site && data.site.host) || '';
    var accent = (data.site && data.site.accent) || '';

    deck.innerHTML = '';
    dotsWrap.innerHTML = '';
    slides = [];

    data.posts.forEach(function (post, i) {
      var node = buildSlide(post, siteName, accent);
      deck.appendChild(node);
      slides.push(node);

      var dot = document.createElement('span');
      dot.className = 'dot' + (i === 0 ? ' is-active' : '');
      dotsWrap.appendChild(dot);
    });

    footerSource.textContent = host ? 'News from ' + host : siteName;

    // Sizes every headline to the room its panel can spare, now and again
    // once the brand faces are in. See assets/fit.js.
    NCStateFit.fitWhenReady(slides);

    hideStatus();
    index = 0;
    show(0);
  }

  /* -------------------------------------------------------------- rotation */

  function show(i) {
    if (!slides.length) { return; }

    // Re-fit the slide about to be seen. Cheap for one element, and it is the
    // last chance to correct a headline that was measured before its font
    // arrived.
    NCStateFit.fit(slides[i]);

    slides.forEach(function (s, n) { s.classList.toggle('is-active', n === i); });
    Array.prototype.forEach.call(dotsWrap.children, function (d, n) {
      d.classList.toggle('is-active', n === i);
    });

    // Restart the progress fill and the photo push from zero.
    progress.classList.remove('run');
    void progress.offsetWidth;   // force reflow so the animation replays
    progress.classList.add('run');

    clearTimeout(timer);
    timer = setTimeout(next, CONFIG.dwell * 1000);
  }

  function next() {
    var last = index >= slides.length - 1;
    index = last ? 0 : index + 1;

    // Swap in refreshed content only at the top of the loop, so a story never
    // disappears mid-display.
    if (last && pendingFeed) {
      var data = pendingFeed;
      pendingFeed = null;
      render(data);
      return;
    }

    show(index);
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

    function attempt() {
      loadFeed()
        .then(render)
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

    // Periodic refresh. New posts appear at the next full pass of the deck.
    setInterval(function () {
      loadFeed().then(function (data) {
        if (!data || !data.posts || !data.posts.length) { return; }
        // Nothing on screen yet means an earlier load failed: show it now.
        if (!slides.length) { render(data); } else { pendingFeed = data; }
      }).catch(function () { /* keep showing the current stories */ });
    }, CONFIG.refresh * 1000);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
