/* ==========================================================================
   NC State Billboard Slides - fitting the headline to the panel

   Shared by the news slide (slide.js) and the cycling events slide
   (events-cycle.js), which use the same media/panel layout.

   The problem this solves, seen on a lobby screen: a long headline came out
   cut through the middle of its last line, with the letters sheared in half
   above the date.

   The old approach shrank the type against a hardcoded 380px budget and, if
   that was not enough, let `overflow: hidden` do the rest. Two things were
   wrong with it. The budget was a guess that ignored the space actually
   going spare (a three-line abstract leaves far more room than a five-line
   one, and the panel had 450px to give, not 380). And its failure mode was
   the worst available: a horizontal cut wherever the pixel ran out.

   What happens instead:

     - the headline is measured against the room it really has, which is the
       panel minus what the eyebrow, date and abstract genuinely occupy, so a
       short abstract hands its leftover space to a long headline
     - when the text fits, no ceiling is imposed at all, so descenders on the
       last line are never shaved
     - if a headline is long enough to need it, abstract lines are traded away
       for headline lines before anything is given up
     - only if all of that fails does it trim, and then to a whole number of
       lines, so a cut can never bisect one

   It is also re-run each time a slide becomes active. A web font that arrives
   after the first measurement would otherwise leave every headline sized
   against the fallback face, which is how a headline measured as fitting can
   still render one line too tall.
   ========================================================================== */

window.NCStateFit = (function () {
  'use strict';

  var MAX  = 78;   // starting size, matches .headline in slide.css
  var MIN  = 44;   // still legible across a lobby at 1920x1080
  var STEP = 2;

  // Lines the abstract may be reduced to, in order, to buy the headline room.
  var ABSTRACT_STEPS = [5, 4, 3];

  function px(value) {
    var n = parseFloat(value);
    return isNaN(n) ? 0 : n;
  }

  /** Height an element occupies in flow, margins included. */
  function outerHeight(el) {
    var cs = getComputedStyle(el);
    if (cs.display === 'none') {
      return 0;
    }
    return el.offsetHeight + px(cs.marginTop) + px(cs.marginBottom);
  }

  function setClamp(el, lines) {
    el.style.webkitLineClamp = String(lines);
    el.style.lineClamp = String(lines);
  }

  /** Everything in the panel that is not the headline, at its real height. */
  function measureOthers(inner, headline) {
    var total = 0;
    for (var i = 0; i < inner.children.length; i++) {
      if (inner.children[i] !== headline) {
        total += outerHeight(inner.children[i]);
      }
    }
    return total;
  }

  function lineHeightOf(headline, size) {
    var line = px(getComputedStyle(headline).lineHeight);
    return line || size * 1.06;
  }

  /**
   * Size one slide's headline to the space its panel can spare.
   * Safe to call repeatedly; every pass starts from a clean slate.
   */
  function fit(node) {
    if (!node) {
      return;
    }

    var panel    = node.querySelector('.panel');
    var inner    = node.querySelector('.panel-inner');
    var headline = node.querySelector('.headline');
    var abstract = node.querySelector('.abstract');

    if (!panel || !inner || !headline) {
      return;
    }

    // Clear what an earlier pass set, or the measurements below describe the
    // previous fit rather than the text.
    headline.style.fontSize  = '';
    headline.style.maxHeight = '';
    headline.style.overflow  = '';
    if (abstract) {
      abstract.style.webkitLineClamp = '';
      abstract.style.lineClamp = '';
    }

    /*
     * Height is taken from the slide, not the panel.
     *
     * The panel is a grid item, and a grid row sized to its content grows
     * when the content overflows it. Measuring the panel therefore asked the
     * overlong headline how much room the overlong headline was allowed to
     * have, and got a bigger answer the longer it ran: 1497px of "available"
     * space in a 980px frame, so nothing ever shrank. The slide is
     * absolutely positioned against the deck, so its height is fixed by its
     * offsets and no amount of text can move it.
     *
     * slide.css now also pins the row with `minmax(0, 1fr)`, which fixes it
     * from the other side. Both are kept: the CSS keeps content off the
     * footer, and this keeps the arithmetic honest if the layout is ever
     * reworked.
     */
    var ps = getComputedStyle(panel);
    var frame = node.clientHeight || panel.clientHeight;
    var available = frame - px(ps.paddingTop) - px(ps.paddingBottom);

    var hs = getComputedStyle(headline);
    var headlineMargins = px(hs.marginTop) + px(hs.marginBottom);

    var steps = abstract ? ABSTRACT_STEPS : [0];

    for (var s = 0; s < steps.length; s++) {
      if (abstract && steps[s]) {
        setClamp(abstract, steps[s]);
      }

      var budget = available - measureOthers(inner, headline) - headlineMargins;

      for (var size = MAX; size >= MIN; size -= STEP) {
        headline.style.fontSize = size + 'px';

        // The +1 absorbs sub-pixel line heights; scrollHeight is an integer.
        if (headline.scrollHeight <= budget + 1) {
          // It fits, so impose no ceiling: nothing can be clipped, and the
          // last line keeps its descenders.
          return;
        }
      }
    }

    /*
     * Nothing fit even at the smallest size with the shortest abstract, which
     * takes a headline far longer than anything a department has published.
     * Trim, but to a whole number of lines so the cut lands between lines
     * instead of through one.
     */
    headline.style.fontSize = MIN + 'px';
    var line = lineHeightOf(headline, MIN);
    var room = available - measureOthers(inner, headline) - headlineMargins;
    var lines = Math.max(1, Math.floor((room + 1) / line));

    headline.style.maxHeight = Math.floor(lines * line) + 'px';
    headline.style.overflow  = 'hidden';
  }

  /** Fit a list of slides. */
  function fitAll(nodes) {
    Array.prototype.forEach.call(nodes || [], fit);
  }

  /**
   * Run now, then again once the brand faces are in.
   *
   * document.fonts.ready can resolve before a face this page has not yet
   * asked for is loaded, so the specific weights are requested by name too.
   * Measuring against Arial and rendering in Roboto Condensed is exactly how
   * a headline ends up one line taller than it measured.
   */
  function fitWhenReady(nodes) {
    fitAll(nodes);

    if (!document.fonts) {
      return;
    }

    var faces = [
      document.fonts.load('700 78px "Roboto Condensed"'),
      document.fonts.load('400 33px "Roboto"'),
      document.fonts.ready
    ];

    Promise.all(faces.map(function (p) {
      return Promise.resolve(p).catch(function () { /* fit anyway */ });
    })).then(function () {
      fitAll(nodes);
    });
  }

  return { fit: fit, fitAll: fitAll, fitWhenReady: fitWhenReady };
})();
