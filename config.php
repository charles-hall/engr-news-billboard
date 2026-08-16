<?php
/**
 * NC State Billboard News Slides - configuration
 *
 * Everything here is safe to commit. There are no secrets.
 * Edit this file to add departments or change defaults, then redeploy.
 */

return [

    /**
     * Departments the slide is allowed to pull from.
     *
     * Key   = the value you pass in the URL, e.g. index.html?site=csc
     * host  = the WordPress hostname (must end in .ncsu.edu)
     * label = eyebrow text shown above the headline. Leave null to use the
     *         site title reported by the WordPress REST API.
     *
     * The allowlist is a security control, not a convenience. feed.php will
     * refuse any host that is not listed here, so the proxy can never be used
     * to fetch arbitrary URLs.
     */
    'sites' => [
        'csc'  => ['host' => 'csc.ncsu.edu',   'label' => 'Computer Science'],

        /*
         * source: 'auto' (the default) tries the REST API and falls back to RSS.
         * 'rest' or 'rss' pins a site to one path, which is worth doing when the
         * other is known to fail.
         *
         * ece.ncsu.edu was pinned to 'rss' for a while: every wp/v2 route
         * answered 401 MISSING_AUTHORIZATION_HEADER behind Sucuri. That plugin
         * has since been deactivated and REST now works there, so it is back on
         * 'auto' and gets the better path, with RSS still underneath it if the
         * lockdown ever returns.
         */
        'ece'  => ['host' => 'ece.ncsu.edu',   'label' => 'Electrical and Computer Engineering'],
        'mae'  => ['host' => 'mae.ncsu.edu',   'label' => 'Mechanical and Aerospace Engineering'],
        'ne'   => ['host' => 'ne.ncsu.edu',    'label' => 'Nuclear Engineering'],
        'ccee' => ['host' => 'ccee.ncsu.edu',  'label' => 'Civil, Construction and Environmental Engineering'],
        'bme'  => ['host' => 'bme.ncsu.edu',   'label' => 'Biomedical Engineering'],
        'cbe'  => ['host' => 'cbe.ncsu.edu',   'label' => 'Chemical and Biomolecular Engineering'],
        'mse'  => ['host' => 'mse.ncsu.edu',   'label' => 'Materials Science and Engineering'],
        // Formally the Edward P. Fitts Department of Industrial and Systems
        // Engineering. Shortened here because the eyebrow sits above the
        // headline and the full name crowds it.
        'ise'  => ['host' => 'ise.ncsu.edu',   'label' => 'Industrial and Systems Engineering'],
        'engr' => ['host' => 'engr.ncsu.edu',  'label' => 'College of Engineering'],
    ],

    /** Default department when the URL has no ?site= parameter. */
    'default_site' => 'csc',

    /** How many stories to pull. Hard ceiling is 12. */
    'default_count' => 5,

    /**
     * Skip posts that have no featured image. Recommended: true.
     * When false, image-less posts render on a Wolfpack Red fallback panel.
     */
    'require_image' => true,

    /** Seconds to keep a cached feed before refetching. */
    'cache_ttl' => 600,

    /**
     * Seconds a stale cache may still be served when the department site is
     * unreachable. Twelve hours keeps the billboard showing real news through
     * a maintenance window instead of an error card.
     */
    'stale_ttl' => 43200,

    /**
     * Where cached feeds and mirrored images live.
     *
     * A directory inside the deployment, not the system temp directory.
     * /tmp looked convenient and was not: PHP-FPM on Plesk often runs with
     * systemd PrivateTmp, so restarting the pool (which is what enabling a PHP
     * extension does) destroys every cache on the box. Every feed then has to
     * be refetched from a cold origin at once, which is the exact stampede all
     * this caching exists to prevent.
     *
     * The contents are public news and public Instagram posts, so there is
     * nothing here that would matter if it were served. `cache/` is in
     * .gitignore, so deployments do not clobber it.
     *
     * Falls back to the temp directory if this path cannot be created, which
     * keeps the slides working on a read-only deployment.
     */
    'cache_dir' => is_writable(__DIR__) || is_dir(__DIR__ . '/cache')
        ? __DIR__ . '/cache'
        : sys_get_temp_dir() . '/ncstate-billboard-cache',

    /**
     * Seconds to wait on the department site before giving up. Generous on
     * purpose: a cold CDN cache on a quiet department site can take six or
     * seven seconds, and ece.ncsu.edu intermittently takes past twelve to
     * serve its own homepage. Only one request per cache_ttl pays this, and
     * with stale-while-revalidate no display waits for it at all.
     */
    'http_timeout' => 15,

    /**
     * Hard ceiling, in seconds, on time spent talking to department sites in a
     * single request.
     *
     * Without this, a site that is merely slow rather than down could stack up
     * several timeouts and run past PHP's max_execution_time, killing the script
     * before it can write its cache or serve stale content. Keep this
     * comfortably under max_execution_time, which is 30 on most Plesk handlers.
     */
    'time_budget' => 20,

    /*
     * The same two limits, but for a scheduled warm-up run (refresh=1).
     *
     * Nothing is waiting on a warm-up, so it can afford to sit through a cold
     * origin that a display never should. cbe.ncsu.edu takes about twenty-eight
     * seconds to build its feed response cold, and ece.ncsu.edu has slow spells
     * of its own, so both were failing the warm run under the display limits
     * and leaving no cache behind for the displays that followed.
     *
     * The scripts raise max_execution_time to match when refresh=1, so this
     * works on a host with the usual 30 second default.
     */
    'warm_http_timeout' => 45,
    'warm_time_budget'  => 120,

    /**
     * User agent sent to department sites.
     *
     * Deliberately browser-like. Some of these sites sit behind a WAF that
     * treats unfamiliar agents differently, and this proxy is only ever reading
     * public pages from our own university's sites.
     */
    'user_agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) '
        . 'Chrome/126.0 Safari/537.36 NCState-Billboard/1.0 (+https://brand.ncsu.edu)',

    /**
     * Timezone used to convert RSS publication dates to local time.
     *
     * Only used on the RSS fallback path. WordPress always stamps RSS pubDate
     * in GMT, unlike the REST API which reports site local time, so without
     * this an evening post would show tomorrow's date on the wall.
     */
    'timezone' => 'America/New_York',

    /** Trim abstracts to this many characters, on a word boundary. */
    'excerpt_max' => 260,

    /**
     * College of Engineering house style closes the space on either side of an
     * em or en dash. This is a typographic fix only; the wording of the source
     * excerpt is never changed. Set false to reproduce the site copy verbatim.
     */
    'tighten_dashes' => true,

    /* --------------------------------------------------------- Events slide */

    /**
     * Upcoming events, read from the university calendar at calendar.ncsu.edu.
     *
     * That calendar runs Localist, whose public API needs no key.
     */
    'events' => [
        'host' => 'calendar.ncsu.edu',

        /** Days ahead to look. */
        'days' => 21,

        /** Events to keep. The slide shows fewer; the rest are headroom. */
        'limit' => 12,

        /**
         * Which campus, defined as a bounding box.
         *
         * The calendar has no campus field: campus_id is null on every event,
         * and there is no campus filter set. Venue coordinates are populated on
         * 48 of 50 events sampled, so a box is the reliable way to do this.
         *
         * Calibrated from real venue coordinates rather than a map. Centennial
         * venues sit between 35.7693 (Hunt Library) and 35.7734 (Venture III);
         * the nearest main-campus venues start at 35.7839 (Carmichael) and
         * 35.7841 (Talley). The box below clears both by a wide margin, and its
         * western edge excludes the Centennial Biomedical Campus over on Blue
         * Ridge Road, which is a different place despite the name.
         */
        'bounds' => [
            'south' => 35.7580,
            'north' => 35.7800,
            'west'  => -78.6900,
            'east'  => -78.6650,
        ],

        /**
         * Event types to leave off the wall, by Localist type name.
         * Workshops and information sessions are numerous and read as internal
         * business rather than something a passer-by would act on.
         */
        'exclude_types' => ['Workshops and Training', 'Information Session'],

        /** Seconds to keep the parsed events before refetching. */
        'cache_ttl' => 1800,
    ],

    /* ------------------------------------------------------ Instagram slide */

    /**
     * Per-department Instagram settings, used by api/instagram.php.
     *
     * These read the Instagram feed already rendered by the Smash Balloon
     * plugin on the department's own WordPress site. That plugin holds the
     * Meta credentials and refreshes its own token, so nothing here needs a
     * Meta app, an access token, or App Review.
     *
     * handle = shown on the slide, without the @. Capitalise it the way the
     *          department styles the account (@NCStateECE, not @ncstateece).
     *          Instagram usernames are case-insensitive in URLs, so the cased
     *          form is safe to use for the profile link as well.
     * path   = page on the department site that renders the feed. The
     *          homepage works, but a dedicated page carrying only the
     *          [instagram-feed] shortcode survives homepage redesigns and can
     *          be set to show more posts.
     */
    'instagram' => [
        'csc'  => ['handle' => 'NCStateCS',   'path' => '/'],
        'ece'  => ['handle' => 'NCStateECE',  'path' => '/'],
        'ccee' => ['handle' => 'NCStateCCEE', 'path' => '/'],

        /*
         * The rest are not enabled because their sites do not publish a feed
         * this can read. Enabling one is a WordPress task, not a change here.
         *
         * engr.ncsu.edu HAS Smash Balloon active but renders no feed on the
         * homepage. Add a page carrying only [instagram-feed num=8], set it to
         * noindex, then uncomment this and point path at it:
         *
         *   'engr' => ['handle' => 'NCStateEngr', 'path' => '/instagram-feed/'],
         *
         * mae, ne, bme, cbe, mse and ise do not have the plugin installed at
         * all. Each links to an Instagram account from the footer, so the
         * accounts exist:
         *
         *   mae  -> NCStateMAE        ne  -> NCStateNuclear
         *   bme  -> (no link found)   cbe -> NCStateCBE
         *   mse  -> NCStateMSE        ise -> NCStateISE
         *
         * Installing and authenticating Smash Balloon on those sites is the
         * only prerequisite; nothing in this repository needs to change.
         */
    ],

    /** Seconds to keep the parsed Instagram feed before refetching. */
    'instagram_cache_ttl' => 1800,

    /**
     * Mirror Instagram images onto this server.
     *
     * Instagram CDN URLs are signed and expire. A billboard that hotlinks them
     * shows broken images the moment a signature lapses, so every image is
     * copied locally and served through api/image.php instead.
     */
    'instagram_mirror_images' => true,

    /** Seconds a mirrored image stays on disk before being refetched. */
    'instagram_image_ttl' => 604800,

    /** Trim Instagram captions to this many characters, on a word boundary. */
    'caption_max' => 150,

    /**
     * Drop trailing hashtag blocks and "link in bio" tails from captions.
     * Hashtags used mid-sentence are kept, since removing those breaks the
     * sentence they are part of.
     */
    'clean_captions' => true,
];
