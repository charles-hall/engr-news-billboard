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
     * Where cached JSON lives. The system temp directory needs no setup on
     * Plesk. Point this at a private directory if you prefer.
     */
    'cache_dir' => sys_get_temp_dir() . '/ncstate-billboard-cache',

    /**
     * Seconds to wait on the department site before giving up. Generous on
     * purpose: a cold CDN cache on a quiet department site can take six or
     * seven seconds, and only one request per cache_ttl ever pays that cost.
     */
    'http_timeout' => 12,

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

    /* ------------------------------------------------------ Instagram slide */

    /**
     * Per-department Instagram settings, used by api/instagram.php.
     *
     * These read the Instagram feed already rendered by the Smash Balloon
     * plugin on the department's own WordPress site. That plugin holds the
     * Meta credentials and refreshes its own token, so nothing here needs a
     * Meta app, an access token, or App Review.
     *
     * handle = shown on the slide, without the @
     * path   = page on the department site that renders the feed. The
     *          homepage works, but a dedicated page carrying only the
     *          [instagram-feed] shortcode survives homepage redesigns and can
     *          be set to show more posts.
     */
    'instagram' => [
        'csc' => ['handle' => 'ncstatecs',   'path' => '/'],
        'ece' => ['handle' => 'ncstateece',  'path' => '/'],
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
