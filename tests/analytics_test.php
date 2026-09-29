<?php
/**
 * Tests for the Google Analytics tag.
 *
 * The interesting properties are all about which pages carry the tag and which
 * deliberately do not, because "add it to every page" is an instruction that
 * decays silently:
 *
 *   - every page a human can see in a browser carries exactly one tag, and it
 *     loads the tag from the site the project actually configured
 *   - the pages that look like they emit HTML but do not -- JSON endpoints,
 *     redirects, and the .doc download -- carry none, and saying so is the
 *     point: a script injected into a JSON body is a parsing bug and one
 *     embedded in a Word attachment is a corrupted download
 *   - the tag is not sent a user_id, an LRN or an email address
 *   - the two CSP copies both permit the origins GA needs, in all three
 *     directives, and stay in sync
 *   - the static HTML files, which never run PHP, carry a hand-inlined copy
 *     that matches the generated one
 *
 * These are hermetic: no file is fetched, no network is touched.
 *
 * Run: php tests/analytics_test.php
 */

require_once __DIR__ . '/../libs/Analytics.php';

$failures = [];
$checks = 0;

function check(string $label, bool $passed): void
{
    global $failures, $checks;
    $checks++;
    if (!$passed) {
        $failures[] = $label;
    }
}

function same(string $label, $expected, $actual): void
{
    check(
        $label . ' (expected ' . json_encode($expected) . ', got ' . json_encode($actual) . ')',
        $expected === $actual
    );
}

function with_ga_env(array $env, callable $body)
{
    $previous = [];
    foreach ($env as $name => $value) {
        $previous[$name] = getenv($name);
        putenv($name . '=' . $value);
    }

    try {
        return $body();
    } finally {
        foreach ($previous as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $value);
            }
        }
    }
}

const GA_BASELINE = ['GA_MEASUREMENT_ID' => '', 'GA_ENABLED' => ''];

function ga_env(array $overrides = []): array
{
    return array_merge(GA_BASELINE, $overrides);
}

$root = dirname(__DIR__);

// ---------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------

same('the default measurement id is this property', 'G-JZ0E3M8PPD', with_ga_env(ga_env(), 'ga_measurement_id'));
check('analytics is on by default', with_ga_env(ga_env(), 'analytics_enabled') === true);
check('GA_ENABLED=0 is the kill switch', with_ga_env(ga_env(['GA_ENABLED' => '0']), 'analytics_enabled') === false);
check('GA_ENABLED=0 with surrounding space is still the kill switch', with_ga_env(ga_env(['GA_ENABLED' => ' 0 ']), 'analytics_enabled') === false);
check('GA_ENABLED=1 keeps analytics on', with_ga_env(ga_env(['GA_ENABLED' => '1']), 'analytics_enabled') === true);
check('an unrecognised GA_ENABLED value does not disable analytics', with_ga_env(ga_env(['GA_ENABLED' => 'off']), 'analytics_enabled') === true);
same(
    'the id can be overridden for a second property',
    'G-ABCDEF1234',
    with_ga_env(ga_env(['GA_MEASUREMENT_ID' => 'G-ABCDEF1234']), 'ga_measurement_id')
);

// The id lands inside a script src and inside a JS string literal. A value
// that is not a measurement id is either a typo or an attempt to close the
// quote and append a script, so it is dropped rather than escaped -- a page
// with no analytics is strictly better than a page running injected code.
foreach ([
    'a javascript payload is rejected' => "G-AA');alert(1);//",
    'a bare host is rejected' => 'evil.example.com',
    'a lowercase id is rejected' => 'g-jz0e3m8ppd',
    'a truncated id is rejected' => 'G-AB',
    'an id with a path is rejected' => 'G-AA/../../x',
    'a UA-style id is rejected' => 'UA-12345-1',
] as $label => $hostile) {
    same($label, '', with_ga_env(ga_env(['GA_MEASUREMENT_ID' => $hostile]), 'ga_measurement_id'));
    check($label . ' emits no tag at all', with_ga_env(ga_env(['GA_MEASUREMENT_ID' => $hostile]), 'google_analytics_tag') === '');
}

// ---------------------------------------------------------------------
// The emitted tag
// ---------------------------------------------------------------------

$tag = with_ga_env(ga_env(), 'google_analytics_tag');

check('the tag loads gtag.js from googletagmanager', str_contains($tag, 'https://www.googletagmanager.com/gtag/js?id=G-JZ0E3M8PPD'));
check('the loader is async, so it cannot block the gate or first paint', (bool) preg_match('/<script async src=/', $tag));
check('dataLayer is initialised', str_contains($tag, 'window.dataLayer = window.dataLayer || []'));
check('the gtag function is defined', str_contains($tag, 'function gtag(){dataLayer.push(arguments);}'));
check('the js timestamp is sent', str_contains($tag, "gtag('js', new Date())"));
check('the property is configured', str_contains($tag, "gtag('config', 'G-JZ0E3M8PPD')"));
same('exactly one loader script is emitted', 1, substr_count($tag, 'googletagmanager.com/gtag/js'));

// "Don't add more than one Google tag to each page." Two tags on one page
// double-count every hit and produce a realtime figure that is simply wrong.
same('exactly one config call is emitted', 1, substr_count($tag, "gtag('config'"));

check('a disabled deployment emits nothing', with_ga_env(ga_env(['GA_ENABLED' => '0']), 'google_analytics_tag') === '');

// The whole reason this was done without a user_id. A GA user_id here would
// be an LRN or an email, in a system with none of the portal's access
// controls, retention policy or deletion path.
foreach (['user_id', 'userId', 'setUserId', 'custom_map'] as $forbidden) {
    check('the tag does not send ' . $forbidden, !str_contains($tag, $forbidden));
}

// ---------------------------------------------------------------------
// Page coverage
// ---------------------------------------------------------------------

// Every page that renders a document a human reads.
$taggedPages = [
    'index.php',
    'session_expired.php',
    'forgot_password.php',
    'reset_password.php',
    'student/login.php',
    'student/signup.php',
    'student/dashboard.php',
    'student/assignments.php',
    'student/profile.php',
    'teacher/login.php',
    'teacher/signup.php',
    'teacher/dashboard.php',
    'teacher/assignments.php',
    'teacher/post_assignment.php',
    'teacher/edit_assignment.php',
    'teacher/students.php',
    'teacher/contact_student.php',
    'teacher/profile.php',
    'controllers/download_assignment.php',
];

foreach ($taggedPages as $file) {
    $source = (string) file_get_contents($root . '/' . $file);

    check($file . ' emits the Google tag', str_contains($source, 'google_analytics_tag()'));
    // The helper is loaded by config/database.php, which every page already
    // requires. Asserted so that moving it out of the bootstrap into
    // per-page requires cannot silently break the pages above.
    check($file . ' goes through the bootstrap that loads the helper', str_contains($source, 'database.php'));
    // The tag has to be in <head>: a <script> in <body> still runs, but it
    // runs after the first paint and after Google has already recorded a
    // bounced visit in some configurations.
    $headEnd = stripos($source, '</head>');
    $tagAt = strpos($source, 'google_analytics_tag()');
    check($file . ' puts the tag inside <head>', $headEnd !== false && $tagAt !== false && $tagAt < $headEnd);
}

// ---------------------------------------------------------------------
// Deliberate exclusions
// ---------------------------------------------------------------------

// The instruction was "every page", which a naive reading would apply to
// these. Each of them would be actively wrong, and each exclusion is pinned
// here because the obvious "fix" for a missing tag is to add it everywhere.
$excluded = [
    // Redirects only; there is no document to instrument.
    'logout.php' => 'redirect',
    // Sends Content-Type: application/vnd.ms-word with a .doc attachment. A
    // <script> tag here is embedded in the downloaded document.
    'download_guide.php' => 'vnd.ms-word',
    // Included partials. nav.php is pulled into a page that already carries
    // the tag, so a tag here would double it.
    'student/nav.php' => 'partial',
    'teacher/nav.php' => 'partial',
    // JSON bodies and file streams. A <script> in a JSON response is a
    // parsing bug for the client, and tracking a download request as a
    // pageview would inflate the numbers.
    'controllers/ajax_check_traffic.php' => 'json',
    'controllers/public_stats.php' => 'json',
    'controllers/webauthn_login_verify.php' => 'json',
    'controllers/webauthn_register_verify.php' => 'json',
    'controllers/upload_endpoint.php' => 'json',
    'controllers/process_job.php' => 'json',
    'controllers/submit.php' => 'redirect',
    'controllers/check_session.php' => 'redirect',
    'controllers/manage_assignment.php' => 'redirect',
];

foreach ($excluded as $file => $why) {
    $source = (string) file_get_contents($root . '/' . $file);
    check($file . ' carries no Google tag (' . $why . ')', !str_contains($source, 'google_analytics_tag()'));
    check($file . ' carries no inlined tag either', !str_contains($source, 'googletagmanager.com/gtag/js'));
}

// A stronger form of the same guard: nothing that returns JSON or a file
// stream may carry the tag, whatever it is called.
$controllerViolations = [];
foreach (glob($root . '/controllers/*.php') ?: [] as $file) {
    $source = (string) file_get_contents($file);
    if (str_contains($source, 'google_analytics_tag()')) {
        $servesHtml = str_contains($source, '<!DOCTYPE html>') || str_contains($source, '<!doctype html>');
        if (!$servesHtml) {
            $controllerViolations[] = basename($file);
        }
    }
}
same('no controller carries the tag without also serving HTML', [], $controllerViolations);

// download_guide.php is the one page that emits a full HTML document and
// still must not be instrumented. Asserted separately so a future
// "the coverage check missed this one" fix does not quietly add it.
$guide = (string) file_get_contents($root . '/download_guide.php');
check('download_guide.php is a Word attachment, not a page', str_contains($guide, 'application/vnd.ms-word'));
check('download_guide.php stays uninstrumented', !str_contains($guide, 'google_analytics_tag()'));

// ---------------------------------------------------------------------
// Static HTML files
// ---------------------------------------------------------------------
//
// These never run PHP, so the snippet is inlined. Two hand-maintained copies
// of the same tag is exactly the kind of duplication that drifts, so they are
// compared against the generated output rather than eyeballed.

$staticPages = ['offline.html', 'EDUPORTAL_TEACHER_STUDENT_GUIDE.html'];

foreach ($staticPages as $file) {
    $source = (string) file_get_contents($root . '/' . $file);

    check($file . ' carries the inlined tag', str_contains($source, 'googletagmanager.com/gtag/js?id=G-JZ0E3M8PPD'));
    same($file . ' carries exactly one loader', 1, substr_count($source, 'googletagmanager.com/gtag/js'));
    same($file . ' carries exactly one config call', 1, substr_count($source, "gtag('config'"));
    check($file . ' initialises dataLayer', str_contains($source, 'window.dataLayer = window.dataLayer || []'));
    check($file . ' sends no user_id', !str_contains($source, 'user_id'));
    check($file . ' puts the tag inside <head>', (stripos($source, 'googletagmanager.com') ?: 0) < (stripos($source, '</head>') ?: PHP_INT_MAX));

    // Byte-for-byte agreement on the parts that matter: the same id and the
    // same configuration call as the PHP output.
    $expectedId = with_ga_env(ga_env(), 'ga_measurement_id');
    check($file . ' uses the same measurement id as the PHP tag', str_contains($source, "gtag('config', '{$expectedId}')"));
    check($file . ' loads the same id as the PHP tag', str_contains($source, 'gtag/js?id=' . $expectedId));
}

// ---------------------------------------------------------------------
// Content-Security-Policy
// ---------------------------------------------------------------------

$database = (string) file_get_contents($root . '/config/database.php');
$apache = (string) file_get_contents($root . '/pwa-apache.conf');

// GA needs three separate directives. Miss connect-src and the beacons are
// dropped silently -- hits never arrive and the numbers are just lower, with
// no error anywhere.
foreach (['config/database.php' => $database, 'pwa-apache.conf' => $apache] as $name => $csp) {
    check($name . ' allows the gtag script origin', str_contains($csp, 'script-src') && str_contains($csp, 'https://www.googletagmanager.com'));
    check($name . ' allows the analytics beacon over XHR', str_contains($csp, 'connect-src') && str_contains($csp, 'https://www.google-analytics.com'));
    check($name . ' allows the regional beacon host', str_contains($csp, 'https://region1.google-analytics.com'));
    check($name . ' allows the noscript measurement pixel', str_contains($csp, 'img-src') && str_contains($csp, 'https://www.google-analytics.com'));

    // The rest of the policy must not have been traded away for analytics.
    check($name . ' still denies objects', str_contains($csp, "object-src 'none'"));
    check($name . ' still denies framing', str_contains($csp, "frame-ancestors 'none'"));
    check($name . ' still restricts form-action', str_contains($csp, "form-action 'self'"));
    check($name . ' still keeps Trusted Types', str_contains($csp, 'trusted-types eduportal') || $name === 'pwa-apache.conf');
    // No wildcards were introduced for GA.
    check($name . ' did not open script-src to a wildcard', !str_contains($csp, 'script-src *'));
}

// The two copies are emitted by different components -- PHP sets one with
// header(), Apache sets one with "Header always set" -- so a drift means the
// stricter or the looser one wins depending on which component answered. They
// are compared directive by directive rather than as strings, so a mismatch
// names the directive instead of dumping two long policies.
$cspDirectives = static function (string $csp): array {
    // PHP writes 'Content-Security-Policy: default-src ...' and Apache writes
    // 'Header always set Content-Security-Policy "default-src ...' -- a colon
    // in one, a space and a quote in the other. Matching only one of them
    // silently compares the policy against an empty string, which reports
    // every directive as missing.
    preg_match('/Content-Security-Policy[^A-Za-z]*(default-src[^"]*)"/', $csp, $match);
    $policy = $match[1] ?? '';

    $out = [];
    foreach (explode(';', $policy) as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        $space = strpos($part, ' ');
        $name = $space === false ? $part : substr($part, 0, $space);
        $out[$name] = $space === false ? [] : preg_split('/\s+/', trim(substr($part, $space)));
    }

    return $out;
};

$fromDatabase = $cspDirectives($database);
$fromApache = $cspDirectives($apache);

check('the PHP policy was found', $fromDatabase !== []);
check('the Apache policy was found', $fromApache !== []);

// The one directive the two copies are known to disagree about, and why.
//
// Because mod_headers' "always set" filter runs after PHP has already emitted
// its own header, a browser receives both Content-Security-Policy headers and
// enforces the intersection of the two. The Apache copy is a strict subset of
// the PHP one, so the effective policy is the Apache one -- and the PHP copy's
// 'trusted-types eduportal' is therefore inert. That is not a regression from
// this work: trusted_types.js states outright that it does not rely on CSP
// enforcement, and require-trusted-types-for is absent from both copies.
//
// Fixing it properly is a separate decision, because turning Trusted Types on
// is a behaviour change for every page, not a CSP tidy-up. It is listed here
// so that it cannot be closed by accident: if the two copies are ever brought
// into full agreement, this entry has to be removed deliberately.
$knownCspDifferences = [
    'trusted-types' => 'PHP declares a Trusted Types policy the Apache copy omits; with both headers on the wire the intersection wins, so the PHP one is inert. trusted_types.js does not rely on enforcement.',
];

foreach ($knownCspDifferences as $directive => $why) {
    check('the known difference in ' . $directive . ' still exists', array_key_exists($directive, $fromDatabase) !== array_key_exists($directive, $fromApache));
    check('the known difference in ' . $directive . ' is the documented one', isset($fromDatabase[$directive]));
}

foreach ($fromDatabase as $directive => $sources) {
    if (array_key_exists($directive, $knownCspDifferences)) {
        continue;
    }

    check('both CSP copies declare ' . $directive, array_key_exists($directive, $fromApache));
    if (array_key_exists($directive, $fromApache)) {
        same('both CSP copies agree on ' . $directive, $fromApache[$directive], $sources);
    }
}

// Apache must not quietly add a directive the PHP copy does not have either,
// which would loosen the intersection from the other direction.
foreach ($fromApache as $directive => $sources) {
    check('the Apache copy adds no directive of its own: ' . $directive, array_key_exists($directive, $fromDatabase));
}

// ---------------------------------------------------------------------
// Trusted Types interaction
// ---------------------------------------------------------------------

// The portal ships a Trusted Types policy in trusted_types.js. Analytics is a
// new inline <script> in every page, and that policy -- if it were ever
// enforced -- would have to allow it, or every page would stop rendering its
// tag. Recorded here so that decision is made deliberately rather than
// discovered on a broken page.
$trustedTypes = (string) file_get_contents($root . '/assets/js/trusted_types.js');
check('Trusted Types is not enforced by the header', !str_contains($database, "require-trusted-types-for"));
check('the page-level policy does not depend on enforcement', str_contains($trustedTypes, 'require-trusted-types-for'));

// ---------------------------------------------------------------------

if ($failures === []) {
    echo "OK: {$checks} Google Analytics checks passed.\n";
    exit(0);
}

echo count($failures) . " of {$checks} Google Analytics checks FAILED:\n";
foreach ($failures as $failure) {
    echo "  - {$failure}\n";
}
exit(1);
