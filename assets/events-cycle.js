/* ==========================================================================
   NC State Billboard Events Slide (cycling / photo variant)
   Cycles through upcoming Centennial Campus events one at a time, each with
   its featured photo and description -- the same visual pattern as the news
   slide (index.html / assets/slide.js), sourced from api/events.php instead
   of api/feed.php.

   For an agenda-style list of the same events instead, see events.html.

   URL parameters (all optional):
     count=5             events to show, 1-8
     dwell=12            seconds per event
     days=21             how far ahead to look, overrides config.php
     theme=light|dark    default light
     refresh=900         seconds between feed refreshes
     kenburns=0          disable the slow photo push
     eyebrow=...          override the small line above the headline
                          (defaults to the event's type, else the campus line)

   Example:
     events-cycle.html?count=5&dwell=12
   ========================================================================== */

(function () {
  'use strict';

  var params = new URLSearchParams(window.location.search);

  var CONFIG = {
    count:    clamp(parseInt(params.get('count'), 10) || 5, 1, 8),
    dwell:    clamp(parseFloat(params.get('dwell')) || 12, 4, 120),
    days:     parseInt(params.get('days'), 10) || 0,
    theme:    params.get('theme') === 'dark' ? 'dark' : 'light',
    refresh:  clamp(parseInt(params.get('refresh'), 10) || 900, 120, 86400),
    kenburns: params.get('kenburns') !== '0',
    eyebrow:  params.get('eyebrow') || ''
  };

  // AP style: abbreviate months of six or more letters when used with a date.
  var AP_MONTHS = ['Jan.', 'Feb.', 'March', 'April', 'May', 'June',
                   'July', 'Aug.', 'Sept.', 'Oct.', 'Nov.', 'Dec.'];

  var TZ = 'America/New_York';

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

  function scaleStage() {
    var s = Math.min(window.innerWidth / 1920, window.innerHeight / 1080);
    stage.style.transform = 'translate(-50%, -50%) scale(' + s + ')';
  }

  /**
   * Break an ISO timestamp into Eastern date parts. Each event carries its
   * own offset, so this reads it back in Eastern rather than the player's
   * local zone, matching events.js.
   */
  function parts(iso) {
    var d = new Date(iso);
    if (isNaN(d.getTime())) { return null; }

    var out = {};
    try {
      new Intl.DateTimeFormat('en-US', {
        timeZone: TZ,
        year: 'numeric', month: 'numeric', day: 'numeric',
        hour: 'numeric', minute: 'numeric', hour12: false
      }).formatToParts(d).forEach(function (p) {
        if (p.type !== 'literal') { out[p.type] = parseInt(p.value, 10); }
      });
    } catch (e) {
      return null;
    }

    if (out.hour === 24) { out.hour = 0; }
    return out;
  }

  /** "Aug. 20, 2026" in AP style. */
  function apDate(p) {
    var month = AP_MONTHS[p.month - 1];
    return month ? month + ' ' + p.day + ', ' + p.year : '';
  }

  /** AP style time: no ":00" on the hour, "noon"/"midnight" instead of 12. */
  function apTime(p) {
    if (p.hour === 12 && p.minute === 0) { return 'noon'; }
    if (p.hour === 0 && p.minute === 0) { return 'midnight'; }

    var suffix = p.hour < 12 ? 'a.m.' : 'p.m.';
    var hour12 = p.hour % 12;
    if (hour12 === 0) { hour12 = 12; }

    var mins = p.minute === 0 ? '' : ':' + (p.minute < 10 ? '0' : '') + p.minute;

    return hour12 + mins + ' ' + suffix;
  }

  /** "Today" or "Tomorrow", or null if neither applies. */
  function relativeDay(p, today) {
    var days = Math.round(
      (Date.UTC(p.year, p.month - 1, p.day) -
       Date.UTC(today.year, today.month - 1, today.day)) / 86400000
    );
    if (days === 0) { return 'Today'; }
    if (days === 1) { return 'Tomorrow'; }
    return null;
  }

  /**
   * One line combining when and where. Unlike the agenda slide, only one
   * event is on screen at a time, so the full date always appears rather
   * than being implied by a chip -- "Today" or "Tomorrow" leads when it
   * applies, but the date follows it either way.
   */
  function whenWhere(ev) {
    var p = parts(ev.startISO);
    if (!p) { return ''; }

    var today = parts(new Date().toISOString());
    var rel = today ? relativeDay(p, today) : null;

    var when = rel ? rel + ', ' + apDate(p) : apDate(p);
    if (!ev.allDay) { when += ' · ' + apTime(p); } else { when += ' · All day'; }

    var where = ev.venue || '';
    if (ev.room) { where += (where ? ', ' : '') + ev.room; }

    return where ? when + ' · ' + where : when;
  }

  /* ------------------------------------------------------------------ data */

  function proxyUrl() {
    var q = new URLSearchParams({ count: String(CONFIG.count) });
    if (CONFIG.days) { q.set('days', String(CONFIG.days)); }
    q.set('_', String(Math.floor(Date.now() / 30000))); // 30s cache buster
    return 'api/events.php?' + q.toString();
  }

  function loadFeed() {
    return fetch(proxyUrl(), { cache: 'no-store' })
      .then(function (r) {
        if (!r.ok) {
          return r.json().catch(function () { return {}; }).then(function (body) {
            throw new Error(body.error || ('proxy ' + r.status));
          });
        }
        return r.json();
      })
      .then(function (data) {
        if (!data || !data.events || !data.events.length) { throw new Error('no events returned'); }
        return data;
      });
  }

  /* -------------------------------------------------------------- building */

  function buildSlide(ev) {
    var node = template.content.firstElementChild.cloneNode(true);
    var img = node.querySelector('.media-img');

    // Per-event-type brand accent from config.php (events.type_colors),
    // resolved server-side into ev.typeColor. Same --dept-accent variable
    // slide.css already uses for the news slide's per-department color;
    // falls back to Wolfpack Red in CSS when the type has none configured.
    if (ev.typeColor) { node.style.setProperty('--dept-accent', ev.typeColor); }

    if (ev.image) {
      img.src = ev.image;
      img.alt = '';
      if (CONFIG.kenburns) { img.classList.add('kenburns'); }
      // A broken or removed photo should not leave a black rectangle on the
      // wall; fall back to the plain Wolfpack Red panel like the news slide.
      img.addEventListener('error', function () { node.classList.add('no-image'); });
    } else {
      node.classList.add('no-image');
    }

    // The eyebrow names the event type when Localist reports one, so the
    // accent color above it reads as a labeled system rather than random
    // variety. A ?eyebrow= override always wins; the campus line is the
    // fallback for untyped events.
    node.querySelector('.eyebrow').textContent =
      CONFIG.eyebrow || ev.type || 'Upcoming on Centennial Campus';
    node.querySelector('.headline').textContent = ev.title;
    node.querySelector('.date').textContent     = whenWhere(ev);
    node.querySelector('.abstract').textContent = ev.excerpt || '';

    return node;
  }

  function render(data) {
    deck.innerHTML = '';
    dotsWrap.innerHTML = '';
    slides = [];

    data.events.forEach(function (ev, i) {
      var node = buildSlide(ev);
      deck.appendChild(node);
      slides.push(node);

      var dot = document.createElement('span');
      dot.className = 'dot' + (i === 0 ? ' is-active' : '');
      dotsWrap.appendChild(dot);
    });

    footerSource.textContent = 'More at calendar.ncsu.edu';

    // Same fitter as the news slide, since the layout is identical.
    NCStateFit.fitWhenReady(slides);

    hideStatus();
    index = 0;
    show(0);
  }

  /* -------------------------------------------------------------- rotation */

  function show(i) {
    if (!slides.length) { return; }

    NCStateFit.fit(slides[i]);

    slides.forEach(function (s, n) { s.classList.toggle('is-active', n === i); });
    Array.prototype.forEach.call(dotsWrap.children, function (d, n) {
      d.classList.toggle('is-active', n === i);
    });

    progress.classList.remove('run');
    void progress.offsetWidth;   // force reflow so the animation replays
    progress.classList.add('run');

    clearTimeout(timer);
    timer = setTimeout(next, CONFIG.dwell * 1000);
  }

  function next() {
    var last = index >= slides.length - 1;
    index = last ? 0 : index + 1;

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

    showStatus('Loading events', 'Fetching upcoming events from calendar.ncsu.edu.');

    function attempt() {
      loadFeed()
        .then(render)
        .catch(function (err) {
          showStatus(
            'Events are unavailable',
            'The slide could not load events right now (' + err.message + '). ' +
            'It will keep trying every minute.'
          );
          setTimeout(attempt, 60000);
        });
    }

    attempt();

    setInterval(function () {
      loadFeed().then(function (data) {
        if (!data || !data.events || !data.events.length) { return; }
        if (!slides.length) { render(data); } else { pendingFeed = data; }
      }).catch(function () { /* keep showing what is up */ });
    }, CONFIG.refresh * 1000);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
