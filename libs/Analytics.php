<?php
/**
 * Google Analytics 4 (gtag.js).
 *
 * Emits the Google tag into <head>. Configured by environment:
 *
 *   GA_MEASUREMENT_ID  the G-XXXXXXXX id. Defaults to this property's id so
 *                      the tag works with no configuration at all; override it
 *                      for a second property (a staging id, say) rather than
 *                      editing this file.
 *   GA_ENABLED         '0' to emit nothing. Useful for a local environment
 *                      that should not report into production analytics.
 *
 * Two deliberate omissions from the tag, both of which are the difference
 * between a traffic report and a privacy incident:
 *
 *   - no user_id, and no custom dimensions. A user_id here would be an LRN or
 *     an email address, and GA4 would then hold those in a system that is not
 *     covered by the portal's own access controls, retention policy or
 *     deletion path. Role-level reporting is available in GA4 without it.
 *   - no cookies set for advertising or personalisation. The default
 *     config sends page_location and referrer only, which is enough for
 *     traffic and funnel reporting.
 *
 * Analytics is loaded by the universal bootstrap in config/database.php, so
 * the function is available on every page and a page cannot silently miss it.
 * Which pages actually call it is a separate decision, and the one that
 * matters: JSON endpoints, file downloads and redirects do not, because
 * putting a <script> in a JSON body or in a .doc attachment is meaningless at
 * best and corrupting at worst. tests/analytics_test.php pins that split,
 * because it is exactly the kind of thing that decays quietly.
 */

define('GA_DEFAULT_MEASUREMENT_ID', 'G-JZ0E3M8PPD');

function ga_measurement_id(): string
{
    $id = trim((string) (getenv('GA_MEASUREMENT_ID') ?: ''));

    if ($id === '') {
        $id = GA_DEFAULT_MEASUREMENT_ID;
    }

    // Validated rather than escaped. The value lands inside a script src and
    // inside a JS string literal, and the only shape that can legitimately
    // appear there is a GA4 measurement id. Anything else is either a typo or
    // an attempt to close the quote and append a script, so it is dropped and
    // the tag is simply not emitted -- a page with no analytics is strictly
    // better than a page running somebody else's code.
    if (preg_match('/^G-[A-Z0-9]{4,20}$/', $id) !== 1) {
        return '';
    }

    return $id;
}

function analytics_enabled(): bool
{
    // An explicit emptiness test rather than `getenv(...) ?: '1'`, because the
    // string "0" is falsy in PHP. The shorthand would read GA_ENABLED=0 as
    // unset and leave analytics switched on -- the one value this variable
    // exists to express.
    $flag = getenv('GA_ENABLED');
    if ($flag !== false && trim((string) $flag) === '0') {
        return false;
    }

    return ga_measurement_id() !== '';
}

/**
 * The Google tag, ready to drop into <head>. Emits nothing at all when
 * analytics is off, so callers do not need to guard.
 */
function google_analytics_tag(): string
{
    if (!analytics_enabled()) {
        return '';
    }

    $id = htmlspecialchars(ga_measurement_id(), ENT_QUOTES, 'UTF-8');

    return <<<HTML
    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id={$id}"></script>
    <script>
      window.dataLayer = window.dataLayer || [];
      function gtag(){dataLayer.push(arguments);}
      gtag('js', new Date());
      gtag('config', '{$id}');
    </script>
    HTML;
}
