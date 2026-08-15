/* ==========================================================================
   NC State Billboard Instagram Slide
   Shows the most recent posts from a department Instagram account as a grid of
   cards on a 1920x1080 canvas.

   The posts come from api/instagram.php, which reads the feed the Smash Balloon
   plugin already renders on the department's own WordPress site. That plugin
   holds the Meta credentials and refreshes its own token, so this slide needs
   no access token of its own and cannot expire.

   URL parameters (all optional):
     site=csc        key from config.php (must also appear in the instagram array)
     count=4         number of posts, 1-8
     refresh=900     seconds between refreshes
     label=...       override the small line above the handle

   Example:
     instagram.html?site=csc&count=4
   ========================================================================== */

(function () {
  'use strict';

  var params = new URLSearchParams(window.location.search);

  var CONFIG = {
    site:    (params.get('site') || 'csc').toLowerCase().replace(/[^a-z0-9_-]/g, ''),
    count:   clamp(parseInt(params.get('count'), 10) || 4, 1, 8),
    refresh: clamp(parseInt(params.get('refresh'), 10) || 900, 120, 86400),
    label:   params.get('label') || ''
  };

  // AP style: abbreviate months of six or more letters when used with a date.
  var AP_MONTHS = ['Jan.', 'Feb.', 'March', 'April', 'May', 'June',
                   'July', 'Aug.', 'Sept.', 'Oct.', 'Nov.', 'Dec.'];

  var TZ = 'America/New_York';

  var stage    = document.getElementById('stage');
  var grid     = document.getElementById('igGrid');
  var eyebrow  = document.getElementById('igEyebrow');
  var handleEl = document.getElementById('igHandle');
  var followEl = document.getElementById('igFollow');
  var template = document.getElementById('igCardTemplate');

  var statusEl = null;

  function clamp(n, lo, hi) {
    if (isNaN(n)) { return lo; }
    return Math.min(hi, Math.max(lo, n));
  }

  function scaleStage() {
    var s = Math.min(window.innerWidth / 1920, window.innerHeight / 1080);
    stage.style.transform = 'translate(-50%, -50%) scale(' + s + ')';
  }

  /**
   * Format an Instagram timestamp in AP style.
   *
   * The proxy reports these in UTC, so they are converted to Eastern before
   * formatting. A post made at 9 p.m. in Raleigh is stamped the next day in
   * UTC, and showing tomorrow's date on a wall today looks like a bug.
   */
  function apDate(iso) {
    if (!iso) { return ''; }
    var d = new Date(iso);
    if (isNaN(d.getTime())) { return ''; }

    var parts;
    try {
      parts = new Intl.DateTimeFormat('en-US', {
        timeZone: TZ, year: 'numeric', month: 'numeric', day: 'numeric'
      }).formatToParts(d);
    } catch (e) {
      return '';
    }

    var get = function (type) {
      var found = parts.filter(function (p) { return p.type === type; })[0];
      return found ? parseInt(found.value, 10) : 0;
    };

    var month = AP_MONTHS[get('month') - 1];
    if (!month) { return ''; }

    return month + ' ' + get('day') + ', ' + get('year');
  }

  function loadFeed() {
    var q = new URLSearchParams({ site: CONFIG.site, count: String(CONFIG.count) });
    q.set('_', String(Math.floor(Date.now() / 60000))); // 60s cache buster

    return fetch('api/instagram.php?' + q.toString(), { cache: 'no-store' })
      .then(function (r) {
        if (!r.ok) {
          return r.json().catch(function () { return {}; }).then(function (body) {
            throw new Error(body.error || ('proxy ' + r.status));
          });
        }
        return r.json();
      })
      .then(function (data) {
        if (!data || !data.posts || !data.posts.length) { throw new Error('no posts returned'); }
        return data;
      });
  }

  function render(data) {
    var site = data.site || {};
    var handle = site.handle || CONFIG.site;

    eyebrow.textContent  = CONFIG.label || labelFor(site);
    handleEl.textContent = '@' + handle;
    followEl.textContent = 'Follow along at instagram.com/' + handle;

    grid.innerHTML = '';

    data.posts.forEach(function (post) {
      var card = template.content.firstElementChild.cloneNode(true);
      var img  = card.querySelector('.ig-img');

      img.src = post.image;
      // Instagram gives no alt text through this path. An empty alt is the
      // correct signal for an image the caption already describes.
      img.alt = '';
      img.addEventListener('error', function () { card.remove(); });

      card.querySelector('.ig-caption').textContent = post.caption || '';
      card.querySelector('.ig-date').textContent    = apDate(post.dateISO);

      grid.appendChild(card);
    });

    hideStatus();
  }

  /** Department name from config.php, falling back to the host. */
  function labelFor(site) {
    if (site.label) { return site.label; }
    return site.host ? site.host.replace(/\.ncsu\.edu$/, '').toUpperCase() : 'NC State University';
  }

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

  function start() {
    scaleStage();
    window.addEventListener('resize', scaleStage);

    showStatus('Loading Instagram', 'Fetching recent posts.');

    function attempt() {
      loadFeed()
        .then(render)
        .catch(function (err) {
          showStatus(
            'Instagram is unavailable',
            'The slide could not load posts right now (' + err.message + '). ' +
            'It will keep trying every minute.'
          );
          setTimeout(attempt, 60000);
        });
    }

    attempt();

    setInterval(function () {
      loadFeed().then(render).catch(function () { /* keep showing what is up */ });
    }, CONFIG.refresh * 1000);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
