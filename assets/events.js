/* ==========================================================================
   NC State Billboard Events Slide
   Upcoming events on Centennial Campus, as an agenda on a 1920x1080 canvas.

   Events come from api/events.php, which reads calendar.ncsu.edu and keeps
   only those whose venue falls inside the campus bounding box in config.php.

   URL parameters (all optional):
     count=6         events to show, 1-8
     days=21         how far ahead to look, overrides config.php
     refresh=900     seconds between refreshes
     title=...       override the headline
     eyebrow=...     override the small line above it

   Example:
     events.html?count=6
   ========================================================================== */

(function () {
  'use strict';

  var params = new URLSearchParams(window.location.search);

  var CONFIG = {
    count:   clamp(parseInt(params.get('count'), 10) || 6, 1, 8),
    days:    parseInt(params.get('days'), 10) || 0,
    refresh: clamp(parseInt(params.get('refresh'), 10) || 900, 120, 86400),
    title:   params.get('title') || '',
    eyebrow: params.get('eyebrow') || ''
  };

  // AP style: abbreviate months of six or more letters when used with a date.
  var AP_MONTHS = ['Jan.', 'Feb.', 'March', 'April', 'May', 'June',
                   'July', 'Aug.', 'Sept.', 'Oct.', 'Nov.', 'Dec.'];

  var TZ = 'America/New_York';

  var stage    = document.getElementById('stage');
  var list     = document.getElementById('evList');
  var eyebrow  = document.getElementById('evEyebrow');
  var titleEl  = document.getElementById('evTitle');
  var windowEl = document.getElementById('evWindow');
  var countEl  = document.getElementById('evCount');
  var template = document.getElementById('evRowTemplate');

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
   * Break an ISO timestamp into Eastern date parts.
   *
   * The calendar stamps each event with its own offset, so parsing is reliable,
   * but the parts have to be read back in Eastern or an evening event would
   * land on the following day for a player whose clock is set elsewhere.
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

    // Intl reports midnight as hour 24 in some engines.
    if (out.hour === 24) { out.hour = 0; }

    return out;
  }

  /** "Aug. 20, 2026" in AP style. */
  function apDate(p) {
    var month = AP_MONTHS[p.month - 1];
    return month ? month + ' ' + p.day + ', ' + p.year : '';
  }

  /**
   * AP style time: lowercase with periods, no ":00" on the hour, and the words
   * noon and midnight rather than 12 p.m. and 12 a.m.
   */
  function apTime(p) {
    if (p.hour === 12 && p.minute === 0) { return 'noon'; }
    if (p.hour === 0 && p.minute === 0) { return 'midnight'; }

    var suffix = p.hour < 12 ? 'a.m.' : 'p.m.';
    var hour12 = p.hour % 12;
    if (hour12 === 0) { hour12 = 12; }

    var mins = p.minute === 0 ? '' : ':' + (p.minute < 10 ? '0' : '') + p.minute;

    return hour12 + mins + ' ' + suffix;
  }

  /** "Today", "Tomorrow", or the weekday, for the row's time line. */
  function relativeDay(p, today) {
    var days = Math.round(
      (Date.UTC(p.year, p.month - 1, p.day) -
       Date.UTC(today.year, today.month - 1, today.day)) / 86400000
    );
    if (days === 0) { return 'Today'; }
    if (days === 1) { return 'Tomorrow'; }
    return null;
  }

  function loadEvents() {
    var q = new URLSearchParams({ count: String(CONFIG.count) });
    if (CONFIG.days) { q.set('days', String(CONFIG.days)); }
    q.set('_', String(Math.floor(Date.now() / 60000))); // 60s cache buster

    return fetch('api/events.php?' + q.toString(), { cache: 'no-store' })
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

  function render(data) {
    if (CONFIG.eyebrow) { eyebrow.textContent = CONFIG.eyebrow; }
    if (CONFIG.title) { titleEl.textContent = CONFIG.title; }

    var days = (data.window && data.window.days) || CONFIG.days || 21;
    windowEl.textContent = 'Next ' + days + ' days';

    list.innerHTML = '';

    var today = parts(new Date().toISOString());
    var lastDate = '';

    data.events.forEach(function (ev) {
      var p = parts(ev.startISO);
      if (!p) { return; }

      var row = template.content.firstElementChild.cloneNode(true);
      var key = p.year + '-' + p.month + '-' + p.day;

      // Per-event-type brand accent (config.php's events.type_colors).
      // Falls back to Wolfpack Red in CSS when the type has none configured.
      if (ev.typeColor) { row.querySelector('.ev-chip').style.setProperty('--type-accent', ev.typeColor); }

      if (key === lastDate) {
        row.classList.add('same-day');
      } else {
        row.querySelector('.ev-chip-month').textContent = AP_MONTHS[p.month - 1] || '';
        row.querySelector('.ev-chip-day').textContent = p.day;
      }
      lastDate = key;

      row.querySelector('.ev-name').textContent = ev.title;

      // Time, then where. "Today" and "Tomorrow" earn their place on a wall;
      // any other day is already obvious from the chip beside it.
      var when = ev.allDay ? 'All day' : apTime(p);
      var rel = today ? relativeDay(p, today) : null;
      if (rel) { when = rel + ', ' + when; }

      var where = ev.venue || '';
      if (ev.room) { where += (where ? ', ' : '') + ev.room; }

      var meta = row.querySelector('.ev-meta');
      var timeSpan = document.createElement('span');
      timeSpan.className = 'ev-time';
      timeSpan.textContent = when;
      meta.appendChild(timeSpan);

      if (where) {
        var sep = document.createElement('span');
        sep.className = 'ev-sep';
        sep.textContent = '/';
        meta.appendChild(sep);
        meta.appendChild(document.createTextNode(where));
      }

      list.appendChild(row);
    });

    var first = data.events[0] ? parts(data.events[0].startISO) : null;
    countEl.textContent = first ? 'Next event ' + apDate(first) : '';

    hideStatus();
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

    showStatus('Loading events', 'Fetching upcoming events from calendar.ncsu.edu.');

    function attempt() {
      loadEvents()
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
      loadEvents().then(render).catch(function () { /* keep showing what is up */ });
    }, CONFIG.refresh * 1000);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
