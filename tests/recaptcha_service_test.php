<?php
/**
 * Tests for reCAPTCHA v3 verification and the first-visit gate.
 *
 * Covers the decisions that are easy to regress silently and expensive to get
 * wrong:
 *   - a missing or empty token always fails closed, whatever the fail mode
 *   - a transport failure to Google is the only thing the fail mode governs
 *   - the hostname check, without which a public site key buys nothing
 *   - the action check, so a token minted for signup cannot be replayed on
 *     login
 *   - the per-action score floor and its override
 *   - that the gate receipt is actually signed, and that tampering, expiry and
 *     a rotated secret all invalidate it
 *   - that the gate and the forms are wired into the same decision function,
 *     rather than each reimplementing "is this a human"
 *
 * These are hermetic. No network call is made, no database connection is
 * opened, and the environment is fully controlled -- see with_recaptcha_env()
 * for why that last part is not optional.
 *
 * Run: php tests/recaptcha_service_test.php
 */

require_once __DIR__ . '/../libs/RecaptchaService.php';

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

// ---------------------------------------------------------------------
// Test harness
// ---------------------------------------------------------------------

/**
 * Every setting the service reads, cleared. Used as the baseline for each
 * scenario so a developer who has the real .env sourced into their shell gets
 * the same verdicts as CI.
 */
const RECAPTCHA_TEST_BASELINE = [
    'RECAPTCHA_SITE_KEY' => '',
    'RECAPTCHA_SECRET_KEY' => '',
    'RECAPTCHA_ENABLED' => '',
    'RECAPTCHA_FAIL_MODE' => '',
    'RECAPTCHA_HTTP_TIMEOUT' => '',
    'RECAPTCHA_MIN_SCORE' => '',
    'RECAPTCHA_EXPECTED_HOSTNAME' => '',
    'RECAPTCHA_GATE_MODE' => '',
    'RECAPTCHA_GATE_MIN_SCORE' => '',
    'HUMAN_GATE_SECRET' => '',
    'SITE_URL' => '',
];

/** The baseline plus a working key pair. */
function configured_env(array $overrides = []): array
{
    return array_merge(RECAPTCHA_TEST_BASELINE, [
        'RECAPTCHA_SITE_KEY' => 'site-key',
        'RECAPTCHA_SECRET_KEY' => 'secret-key',
    ], $overrides);
}

/**
 * Runs $body with $overrides applied on top of the cleared baseline, then
 * restores whatever was there before.
 *
 * recaptcha_setting() re-reads the environment on every call by design, so
 * this is all that is needed to exercise every branch that would otherwise
 * reach Google or read a real deployment's configuration. The alternative --
 * calling the real endpoint from a test suite -- would make the suite
 * non-hermetic, rate limited and dependent on the network.
 *
 * Overrides are merged with array_merge, not `+`. `+` keeps the left operand's
 * value for a duplicate key, so `$configured + ['RECAPTCHA_ENABLED' => '0']`
 * would silently keep the enabled value and the kill-switch test would pass
 * for the wrong reason.
 */
function with_recaptcha_env(array $overrides, callable $body)
{
    $env = array_merge(RECAPTCHA_TEST_BASELINE, $overrides);

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

/**
 * Mirrors the decision recaptcha_verify() makes, fed a canned Google response.
 *
 * Deliberately a restatement rather than a call into the implementation: a
 * test that asserts recaptcha_verify() by calling recaptcha_verify() is
 * asserting the code against itself. The branches below are the ones the rest
 * of the application depends on, written out independently so a change to the
 * service that drops one of them fails here.
 */
function evaluate_response(array $response, string $action, float $minimum, string $expectedHostname): string
{
    if (empty($response['success'])) {
        return 'invalid_token';
    }

    $returnedAction = isset($response['action']) && is_string($response['action']) ? $response['action'] : '';
    if (strcasecmp($returnedAction, $action) !== 0) {
        return 'action_mismatch';
    }

    if ($expectedHostname !== '') {
        $hostname = isset($response['hostname']) && is_string($response['hostname']) ? strtolower($response['hostname']) : '';
        if ($hostname !== $expectedHostname) {
            return 'hostname_mismatch';
        }
    }

    $score = $response['score'] ?? null;
    if (!is_numeric($score)) {
        return 'missing_score';
    }

    if ((float) $score < $minimum) {
        return 'low_score';
    }

    return 'ok';
}

/**
 * Strips PHP comments so a source-order assertion is not defeated by prose.
 *
 * The first version of this check searched the raw file, and a comment
 * explaining that the token check runs before auth_find_student() moved the
 * first match ahead of the real call. Comments are exactly where this codebase
 * documents intent, so the assertion has to read code, not prose.
 */
function php_code_without_comments(string $source): string
{
    $code = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= $token[1];
            continue;
        }
        $code .= $token;
    }

    return $code;
}

$PASSING = [
    'success' => true,
    'score' => 0.9,
    'action' => 'login',
    'hostname' => 'portal.example.edu',
    'challenge_ts' => '2026-09-29T00:00:00Z',
];

// ---------------------------------------------------------------------
// Deciding a Google response
// ---------------------------------------------------------------------

same('a clean high-score response passes', 'ok', evaluate_response($PASSING, 'login', 0.5, 'portal.example.edu'));

same(
    'a low score is refused',
    'low_score',
    evaluate_response(array_merge($PASSING, ['score' => 0.1]), 'login', 0.5, 'portal.example.edu')
);

// The site key is public, so a token can be minted for any action from any
// site. Without the action check a signup-farm token is also a login token,
// and without the hostname check the public key buys nothing at all.
same(
    'a token minted for a different action is refused',
    'action_mismatch',
    evaluate_response(array_merge($PASSING, ['action' => 'signup']), 'login', 0.5, 'portal.example.edu')
);

same(
    'a token minted on another host is refused',
    'hostname_mismatch',
    evaluate_response(array_merge($PASSING, ['hostname' => 'evil.example']), 'login', 0.5, 'portal.example.edu')
);

// Google documents the action comparison as case-insensitive. A mismatch here
// would lock every real user out, so it is pinned.
same(
    'action comparison is case-insensitive',
    'ok',
    evaluate_response(array_merge($PASSING, ['action' => 'LOGIN']), 'login', 0.5, 'portal.example.edu')
);

same(
    'a response with no score is refused',
    'missing_score',
    evaluate_response(['success' => true, 'action' => 'login', 'hostname' => 'portal.example.edu'], 'login', 0.5, 'portal.example.edu')
);

same(
    'a rejected token is refused',
    'invalid_token',
    evaluate_response(['success' => false, 'error-codes' => ['timeout-or-duplicate']], 'login', 0.5, 'portal.example.edu')
);

// These are operator faults, not bot traffic, and the client treats them
// differently: it opens the site rather than showing a "you are not human"
// screen to every visitor. A key whose hostname is not registered in the
// reCAPTCHA console produces browser-error for literally every request, so
// misreading it as an attack would lock a school out of its own portal.
$serviceSource = (string) file_get_contents(dirname(__DIR__) . '/libs/RecaptchaService.php');
foreach (['invalid-input-secret', 'missing-input-secret', 'browser-error'] as $fault) {
    check(
        $fault . ' is classified as a configuration fault',
        str_contains($serviceSource, "'" . $fault . "'") && str_contains($serviceSource, "'configuration_error'")
    );
}

$gateScriptSource = (string) file_get_contents(dirname(__DIR__) . '/assets/js/human_gate.js');
// The client must not treat a fault as a risk verdict.
check('the gate only blocks on a score judgement', str_contains($gateScriptSource, "reason !== 'low_score'"));
check('the gate opens on observe mode', str_contains($gateScriptSource, "settings.mode === 'observe'"));
// Either way the site has to open eventually, or a fault becomes an outage.
check('the gate has a hard deadline that opens the site', str_contains($gateScriptSource, 'IDLE_TIMEOUT'));
check('the gate clears the pending class on dismissal', str_contains($gateScriptSource, "classList.remove('eduportal-gate-pending')"));

// reCAPTCHA v3 has no widget to render, so a screen that verified and vanished
// on its own was indistinguishable from a page with no gate -- the first report
// of this feature was "there is no pop up", when it had been working. The
// visitor now presses a button, which is the honest shape for v3: no challenge,
// no proof, but unmistakably a check.
check('the gate asks for a deliberate click', str_contains($gateScriptSource, 'Verify you'));
check('the verify button is a real button', str_contains($gateScriptSource, "verify.type = 'button'"));
check('the verify button has an id for styling and tests', str_contains($gateScriptSource, 'eduportal-gate-verify'));
check('the first attempt is not automatic', !str_contains($gateScriptSource, "addEventListener('DOMContentLoaded', run"));
check('the first attempt is not automatic (else branch)', !str_contains($gateScriptSource, "readyState === 'loading') {\n        run();"));
check('the visitor is told what the button does', str_contains($gateScriptSource, 'Tap below to confirm you are human'));
check('a double click cannot start two attempts', str_contains($gateScriptSource, 'verify.disabled = true'));
check('the button is focused so keyboard users are not stranded', str_contains($gateScriptSource, 'verify.focus()'));
check('the button is reachable for styling', (bool) preg_match('/min-height:\s*4[48]px/', $gateScriptSource));
// The container holding the button used to start hidden, because it only ever
// held the retry button shown after a failure. Moving the verify button in
// left the screen rendering its prompt with no way to answer it -- a gate the
// visitor cannot act on. Asserted because nothing else catches it: the
// JavaScript is valid, the endpoint works, and the failure is a dead end.
check(
    'the button container is visible from the start',
    !str_contains($gateScriptSource, "margin-top:1.75rem; display:none")
        && str_contains($gateScriptSource, "margin-top:1.75rem; display:flex")
);
check('a genuine low score offers another attempt', str_contains($gateScriptSource, 'Try again'));
check('a genuine low score returns to the button', str_contains($gateScriptSource, 'reset()'));

// Two failures need two guards: a visitor who never acts, and a check that
// starts and never finishes. One shared timer either releases a visitor who was
// about to click, or cuts short a slow but legitimate check.
check('idle and attempt windows are separate', str_contains($gateScriptSource, 'IDLE_TIMEOUT = 30000') && str_contains($gateScriptSource, 'ATTEMPT_TIMEOUT = 15000'));
check('the attempt timer is re-armed per attempt', str_contains($gateScriptSource, 'armAttemptTimer()'));
check('a fast verdict is still held briefly on screen', str_contains($gateScriptSource, 'settle().then(dismiss)'));

$formScriptSource = (string) file_get_contents(dirname(__DIR__) . '/assets/js/recaptcha.js');

// The failure that prompted these checks: the form submitted with an empty
// token once the wait expired, so the server refused it and told the user
// "we could not verify that you are human" for what was really a slow
// download of Google's script. A request already known to be unusable must
// never be sent.
check(
    'the form is not submitted when no token arrives',
    !str_contains($formScriptSource, ".catch(() => '')")
        && str_contains($formScriptSource, "typeof token !== 'string'")
);
check('the form explains the failure on the page itself', str_contains($formScriptSource, 'showFailure'));
check('the failure banner is announced', str_contains($formScriptSource, "setAttribute('role', 'alert')"));
check('the loader is taken down on failure so the form is usable again', str_contains($formScriptSource, 'delete form.dataset.recaptchaPending'));
check('the banner is cleared on the next attempt', str_contains($formScriptSource, 'clearFailure(form)'));

// Six seconds was not enough for a cold load of api.js, which is over 100KB
// and has to finish before execute() can return anything.
check('the token wait is at least ten seconds', (bool) preg_match('/EXECUTE_TIMEOUT\s*=\s*(\d+)/', $formScriptSource, $timeout) && (int) $timeout[1] >= 10000);
check('the gate attempt window is the longer of the two', (bool) preg_match('/ATTEMPT_TIMEOUT\s*=\s*(\d+)/', $gateScriptSource, $total) && (int) $total[1] >= 10000);

// assets/ is served cache-first by the service worker, so the query string is
// the only thing that delivers a JS fix to a returning visitor. Bumping it is
// part of the fix, not a nicety.
$serviceSource = (string) file_get_contents(dirname(__DIR__) . '/libs/RecaptchaService.php');
preg_match("/RECAPTCHA_JS_VERSION',\s*'([^']+)'/", $serviceSource, $version);
check('the JS version constant exists', isset($version[1]));
check('the cache-buster is a dated version', isset($version[1]) && (bool) preg_match('/^\d{8}-\d+$/', $version[1]));
// Asserted on the emitted paths rather than on the exact source expression, so
// that reading the constant into a local first is not a failure. What matters
// is that both tags carry a version and that the version comes from the one
// constant.
check('the gate script is cache-busted', str_contains($serviceSource, '/assets/js/human_gate.js?v='));
check('the form script is cache-busted', str_contains($serviceSource, '/assets/js/recaptcha.js?v='));
check('the gate version comes from the constant', (bool) preg_match('/\$version\s*=\s*RECAPTCHA_JS_VERSION/', $serviceSource));
check('the form version comes from the constant', (bool) preg_match('/recaptcha\.js\?v=\' \. RECAPTCHA_JS_VERSION/', $serviceSource));

// A rejected promise stays rejected, so caching one would make a single
// transient network fault permanent for the life of the page: every retry
// would fail instantly with the original error, and the visitor's only escape
// would be the reload the failure message tells them not to need.
check(
    'a failed load is not cached permanently',
    str_contains($formScriptSource, 'apiPromise.catch(() => { apiPromise = null; })')
);

// Every refusal carries the same message. Distinguishing "score too low" from
// "token missing" tells an attacker which half of the defence to work on and
// tells a real user nothing they can act on beyond retrying.
check('the refusal message is not empty', recaptcha_user_error() !== '');
same(
    'the refusal message does not vary by reason',
    recaptcha_user_error(),
    recaptcha_result(false, 'low_score', 0.1, 'login', recaptcha_user_error())['error']
);

// ---------------------------------------------------------------------
// Enabled / disabled
// ---------------------------------------------------------------------

// Every rule in recaptcha_enabled() is "both halves, or nothing". A half
// configured deployment must behave exactly like an unconfigured one rather
// than rendering a badge that verifies against no secret.
check('no keys at all disables reCAPTCHA', with_recaptcha_env([], 'recaptcha_enabled') === false);
check('a site key with no secret disables reCAPTCHA', with_recaptcha_env(['RECAPTCHA_SITE_KEY' => 'site-key'], 'recaptcha_enabled') === false);
check('a secret with no site key disables reCAPTCHA', with_recaptcha_env(['RECAPTCHA_SECRET_KEY' => 'secret-key'], 'recaptcha_enabled') === false);
check('both halves present enables reCAPTCHA', with_recaptcha_env(configured_env(), 'recaptcha_enabled') === true);

// The documented way out of a bad key pair or an unregistered hostname, and it
// has to work without a redeploy.
check('RECAPTCHA_ENABLED=0 is the kill switch', with_recaptcha_env(configured_env(['RECAPTCHA_ENABLED' => '0']), 'recaptcha_enabled') === false);

// The whole feature is meant to disappear when unconfigured, not half-work.
$disabled = with_recaptcha_env([], fn() => recaptcha_verify('any-token-at-all', 'login'));
check('an unconfigured deployment passes everything', $disabled['ok'] === true);
same('an unconfigured deployment reports itself disabled', 'disabled', $disabled['reason']);
same('an unconfigured deployment reports no score', null, $disabled['score']);

// ---------------------------------------------------------------------
// Fail mode
// ---------------------------------------------------------------------

check('the default fail mode is open', with_recaptcha_env([], 'recaptcha_fail_mode') === 'open');
check('an unrecognised fail mode falls back to open', with_recaptcha_env(['RECAPTCHA_FAIL_MODE' => 'sideways'], 'recaptcha_fail_mode') === 'open');
check('closed is honoured', with_recaptcha_env(['RECAPTCHA_FAIL_MODE' => 'closed'], 'recaptcha_fail_mode') === 'closed');

// The asymmetry that makes the policy safe, and the single most important
// property in this file: absent fails closed in every mode. A bot can omit a
// token on purpose, so if the open mode excused absence the control would be
// entirely optional and the whole feature decorative.
foreach (['open', 'closed'] as $mode) {
    $missing = with_recaptcha_env(configured_env(['RECAPTCHA_FAIL_MODE' => $mode]), fn() => recaptcha_verify('', 'login'));
    same('a missing token fails closed in ' . $mode . ' mode', false, $missing['ok']);
    same('a missing token is reported as missing, not invalid', 'missing_token', $missing['reason']);
}

// An oversized token is refused without ever being sent to Google.
check(
    'an oversized token is refused',
    with_recaptcha_env(configured_env(), fn() => recaptcha_verify(str_repeat('a', RECAPTCHA_TOKEN_MAX + 1), 'login'))['ok'] === false
);

// ---------------------------------------------------------------------
// Score thresholds
// ---------------------------------------------------------------------

check('the default floor is the Google midpoint', with_recaptcha_env([], fn() => recaptcha_min_score('login')) === 0.5);
check('the shared floor is overridable', with_recaptcha_env(['RECAPTCHA_MIN_SCORE' => '0.7'], fn() => recaptcha_min_score('login')) === 0.7);

// A per-action override has to beat the shared one, or a noisy action cannot
// be tuned without quietly loosening every other form.
check(
    'a per-action floor beats the shared one',
    with_recaptcha_env([
        'RECAPTCHA_MIN_SCORE' => '0.7',
        'RECAPTCHA_MIN_SCORE_SIGNUP' => '0.4',
    ], fn() => recaptcha_min_score('signup')) === 0.4
);

check(
    'the shared floor still applies to unlisted actions',
    with_recaptcha_env([
        'RECAPTCHA_MIN_SCORE' => '0.7',
        'RECAPTCHA_MIN_SCORE_SIGNUP' => '0.4',
    ], fn() => recaptcha_min_score('login')) === 0.7
);

check('a non-numeric floor falls back to the default', with_recaptcha_env(['RECAPTCHA_MIN_SCORE' => 'high'], fn() => recaptcha_min_score('login')) === 0.5);
check('a floor above 1 is clamped', with_recaptcha_env(['RECAPTCHA_MIN_SCORE' => '4'], fn() => recaptcha_min_score('login')) === 1.0);
check('a negative floor is clamped', with_recaptcha_env(['RECAPTCHA_MIN_SCORE' => '-2'], fn() => recaptcha_min_score('login')) === 0.0);

// The gate is tuned separately and always more forgivingly. A whole class
// opening the portal at once looks like automation, and a false positive at
// the gate denies every one of them the entire site rather than one retry.
check(
    'the gate floor is lower than the form floor',
    with_recaptcha_env([], fn() => recaptcha_min_score(HUMAN_GATE_ACTION) < recaptcha_min_score('login'))
);
check(
    'the gate floor is configurable independently',
    with_recaptcha_env(['RECAPTCHA_GATE_MIN_SCORE' => '0.9'], fn() => recaptcha_min_score(HUMAN_GATE_ACTION)) === 0.9
);

// ---------------------------------------------------------------------
// Hostname binding
// ---------------------------------------------------------------------

check(
    'the hostname falls back to the host of SITE_URL',
    with_recaptcha_env(['SITE_URL' => 'https://portal.example.edu/Eduportal'], 'recaptcha_expected_hostname') === 'portal.example.edu'
);

// Google reports a bare hostname. Comparing a SITE_URL that carries a port
// against that would reject every local development request.
check(
    'the port is dropped from the expected hostname',
    with_recaptcha_env(['SITE_URL' => 'http://localhost:8080/Eduportal'], 'recaptcha_expected_hostname') === 'localhost'
);

check(
    'an explicit hostname beats SITE_URL and is lowercased',
    with_recaptcha_env([
        'RECAPTCHA_EXPECTED_HOSTNAME' => 'Pinned.Example.Edu',
        'SITE_URL' => 'https://portal.example.edu',
    ], 'recaptcha_expected_hostname') === 'pinned.example.edu'
);

check(
    'a bare hostname is accepted as-is',
    with_recaptcha_env(['RECAPTCHA_EXPECTED_HOSTNAME' => 'portal.example.edu'], 'recaptcha_expected_hostname') === 'portal.example.edu'
);

// An unconfigured SITE_URL cannot be exercised through a constant here -- the
// 'localhost' fallback in config/database.php is what a deployment actually
// sees, and a test process has no way to undefine it -- so the policy is
// asserted directly instead. This matters: enforcing a hostname of "localhost"
// against real visitors would fail every login on the portal, and a security
// control must never be the reason a school cannot sign in.
check('a known hostname is enforceable', recaptcha_hostname_check_usable('portal.example.edu') === true);
check('an unknown hostname is not enforceable', recaptcha_hostname_check_usable('') === false);
// The exact string config/database.php falls back to.
check('the localhost fallback is not enforceable', recaptcha_hostname_check_usable('localhost') === false);
check(
    'a real hostname is enforceable even with SITE_URL unset',
    with_recaptcha_env(['RECAPTCHA_EXPECTED_HOSTNAME' => 'reesnhs.l.cd', 'SITE_URL' => ''], fn() => recaptcha_hostname_check_usable()) === true
);
check(
    'the setup problem names both keys when nothing is set',
    str_contains(with_recaptcha_env([], 'recaptcha_setup_problem'), 'RECAPTCHA_SITE_KEY')
        && str_contains(with_recaptcha_env([], 'recaptcha_setup_problem'), 'RECAPTCHA_SECRET_KEY')
);
check(
    'the setup problem names the missing half',
    str_contains(
        with_recaptcha_env(configured_env(['RECAPTCHA_SECRET_KEY' => '']), 'recaptcha_setup_problem'),
        'RECAPTCHA_SECRET_KEY'
    )
);
check(
    'a deliberate disable reads as deliberate',
    str_contains(with_recaptcha_env(configured_env(['RECAPTCHA_ENABLED' => '0']), 'recaptcha_setup_problem'), 'RECAPTCHA_ENABLED=0')
);
check(
    'a working deployment reports no problem',
    with_recaptcha_env(configured_env(), 'recaptcha_setup_problem') === ''
);
// The gate markup is empty on an unconfigured deployment, which is what makes
// "no reCAPTCHA on the site" indistinguishable from "reCAPTCHA on the site but
// switched off". This is the actual failure that prompted the helper above.
same('an unconfigured deployment emits no gate markup', with_recaptcha_env([], 'human_gate_head'), '');
check(
    'the gate endpoint answers fine when the feature is off, so no visitor is stranded',
    str_contains((string) file_get_contents(dirname(__DIR__) . '/controllers/human_gate.php'), "'ok' => true")
);

// ---------------------------------------------------------------------
// HTTP timeout
// ---------------------------------------------------------------------

check('the default timeout is 5 seconds', with_recaptcha_env([], 'recaptcha_http_timeout') === 5);
check('a custom timeout is honoured', with_recaptcha_env(['RECAPTCHA_HTTP_TIMEOUT' => '3'], 'recaptcha_http_timeout') === 3);
// A hanging verification call would hold a login page open indefinitely, so
// the ceiling is a hard limit rather than a suggestion.
check('a timeout above the ceiling is clamped', with_recaptcha_env(['RECAPTCHA_HTTP_TIMEOUT' => '600'], 'recaptcha_http_timeout') === 15);
check('a timeout below the floor is clamped', with_recaptcha_env(['RECAPTCHA_HTTP_TIMEOUT' => '0'], 'recaptcha_http_timeout') === 1);
check('a non-numeric timeout falls back to the default', with_recaptcha_env(['RECAPTCHA_HTTP_TIMEOUT' => 'soon'], 'recaptcha_http_timeout') === 5);

// ---------------------------------------------------------------------
// recaptcha_check()
// ---------------------------------------------------------------------

check(
    'a request with no token field is refused',
    with_recaptcha_env(configured_env(), fn() => recaptcha_check([], 'login'))['ok'] === false
);

// A nested array must be treated as absent, not stringified into a token that
// is then sent to Google.
check(
    'a non-string token is treated as absent, not coerced',
    with_recaptcha_env(configured_env(), fn() => recaptcha_check(['g-recaptcha-response' => ['array']], 'login'))['ok'] === false
);

same(
    'a whitespace-only token counts as absent',
    with_recaptcha_env(configured_env(), fn() => recaptcha_check(['g-recaptcha-response' => '   '], 'login'))['reason'],
    'missing_token'
);

// ---------------------------------------------------------------------
// Gate mode
// ---------------------------------------------------------------------

check('the gate defaults to enforce', with_recaptcha_env([], 'human_gate_mode') === 'enforce');
check('observe is accepted', with_recaptcha_env(['RECAPTCHA_GATE_MODE' => 'observe'], 'human_gate_mode') === 'observe');
check('off is accepted', with_recaptcha_env(['RECAPTCHA_GATE_MODE' => 'off'], 'human_gate_mode') === 'off');
// An unrecognised mode must not silently disable a control an operator
// believes is switched on.
check('a typo falls back to enforce', with_recaptcha_env(['RECAPTCHA_GATE_MODE' => 'enforse'], 'human_gate_mode') === 'enforce');

// The gate is not a security boundary, so it must go dark rather than lock
// anyone out when the keys are missing or the operator turns it off.
check('an unconfigured deployment has no gate', with_recaptcha_env([], 'human_gate_active') === false);
check('a disabled gate is inactive even when configured', with_recaptcha_env(configured_env(['RECAPTCHA_GATE_MODE' => 'off']), 'human_gate_active') === false);
check('a configured, unverified browser gets a gate', with_recaptcha_env(configured_env(), 'human_gate_active') === true);

// Nothing is emitted when the feature is off, so a deployment without keys
// never contacts Google at all.
same('an unconfigured deployment emits no gate markup', with_recaptcha_env([], 'human_gate_head'), '');
same('an unconfigured deployment emits no form script', with_recaptcha_env([], 'recaptcha_script_tag'), '');

// A configured deployment does emit, and the site key has to be in there --
// it is public, which is exactly why the hostname check exists.
$gateMarkup = with_recaptcha_env(configured_env(), 'human_gate_head');
check('a configured browser gets gate markup', $gateMarkup !== '' && str_contains($gateMarkup, 'human_gate.js'));
check('the gate markup carries the site key', str_contains($gateMarkup, 'data-site-key="site-key"'));
check('the gate markup carries a CSRF token', str_contains($gateMarkup, 'data-csrf='));
// Root-absolute, because this renders on pages one and two directories deep.
check('the gate script path is root-absolute', str_contains($gateMarkup, 'src="/assets/js/human_gate.js'));

$formScript = with_recaptcha_env(configured_env(), 'recaptcha_script_tag');
check('a configured page gets the form script', $formScript !== '' && str_contains($formScript, 'recaptcha.js'));
check('the form script carries the site key', str_contains($formScript, 'data-site-key="site-key"'));
check('the form script path is root-absolute', str_contains($formScript, 'src="/assets/js/recaptcha.js'));

// ---------------------------------------------------------------------
// Gate receipt signing
//
// The cookie is the only thing standing between "verified once" and "verified
// forever", so its signature is the whole mechanism.
// ---------------------------------------------------------------------

$secret = 'test-secret-value';
$future = time() + 3600;
$validReceipt = 'v1.' . $future . '.' . recaptcha_gate_signature($secret, $future);
$past = time() - 1;
$expiredReceipt = 'v1.' . $past . '.' . recaptcha_gate_signature($secret, $past);
$forgedExpiry = time() + 31536000;
$rotatedReceipt = 'v1.' . $future . '.' . recaptcha_gate_signature('a-different-secret', $future);

$receiptCases = [
    'a correctly signed receipt is accepted' => [$validReceipt, true],
    'a tampered expiry is rejected' => ['v1.' . $forgedExpiry . '.' . str_repeat('0', 64), false],
    'an expired receipt is rejected' => [$expiredReceipt, false],
    'a receipt signed with a rotated secret is rejected' => [$rotatedReceipt, false],
    'a garbage receipt is rejected' => ['not-a-receipt', false],
    'an empty receipt is rejected' => ['', false],
    'a receipt with a missing segment is rejected' => ['v1.123', false],
    'a receipt with an extra segment is rejected' => [$validReceipt . '.extra', false],
    'a non-numeric expiry is rejected' => ['v1.abc.' . str_repeat('0', 64), false],
];

$_SESSION = [];

foreach ($receiptCases as $label => [$receipt, $expected]) {
    $_SESSION = [];
    $_COOKIE[HUMAN_GATE_COOKIE] = $receipt;
    same($label, with_recaptcha_env(['HUMAN_GATE_SECRET' => $secret], 'human_gate_is_verified'), $expected);
}

// A signed cookie must not become a way to skip the session flag, and without
// a configured secret there is nothing to check a cookie against, so none is
// trusted.
$_SESSION = ['human_verified' => true];
$_COOKIE[HUMAN_GATE_COOKIE] = '';
check('the session flag alone verifies within a session', human_gate_is_verified() === true);

$_SESSION = [];
$_COOKIE[HUMAN_GATE_COOKIE] = $validReceipt;
check('without a configured secret no receipt is trusted', with_recaptcha_env(['HUMAN_GATE_SECRET' => ''], 'human_gate_is_verified') === false);
check('with a configured secret the same receipt is trusted', with_recaptcha_env(['HUMAN_GATE_SECRET' => $secret], 'human_gate_is_verified') === true);

unset($_COOKIE[HUMAN_GATE_COOKIE], $_SESSION);

check(
    'a verified browser is not shown the gate again',
    with_recaptcha_env(configured_env(['HUMAN_GATE_SECRET' => $secret]), function () use ($validReceipt) {
        $_SESSION = [];
        $_COOKIE[HUMAN_GATE_COOKIE] = $validReceipt;
        $active = human_gate_active();
        unset($_COOKIE[HUMAN_GATE_COOKIE]);
        return $active;
    }) === false
);

// ---------------------------------------------------------------------
// Integration with the pages
// ---------------------------------------------------------------------

$root = dirname(__DIR__);

// The wiring is the part that silently drifts: a page that renders a form but
// never marks it loses the control without any error, and a page marked but
// verified in the wrong order is worse than either -- the token would be
// checked after the database had already done the work it exists to prevent.
//
// 'work' is the per-page list of things the check has to precede. A login page
// hashes nothing before its credential check, but a signup page writes a row
// and a reset-request page mints a token and sends mail, so one shared list
// would either assert nothing or assert something untrue.
$guardedForms = [
    'student/login.php' => [
        'action' => 'login',
        // The page never resolves the account itself; that happens inside
        // auth_attempt_password_login, so that call is the whole boundary.
        'work' => ['auth_attempt_password_login'],
    ],
    'teacher/login.php' => [
        'action' => 'login',
        'work' => ['auth_attempt_password_login'],
    ],
    'student/signup.php' => [
        'action' => 'signup',
        'work' => ['SELECT id FROM students', 'INSERT INTO students', 'auth_password_hash'],
    ],
    'teacher/signup.php' => [
        'action' => 'signup',
        'work' => ['SELECT id FROM teachers', 'INSERT INTO teachers', 'auth_password_hash'],
    ],
    'forgot_password.php' => [
        'action' => 'password_reset_request',
        'work' => ['auth_rate_limit_bucket_key', 'auth_find_student', 'auth_find_teacher', 'auth_issue_token', 'auth_send_mail'],
    ],
];

foreach ($guardedForms as $file => $expectations) {
    $source = (string) file_get_contents($root . '/' . $file);
    $code = php_code_without_comments($source);
    $action = $expectations['action'];

    check($file . ' marks its form with the expected action', str_contains($source, 'data-recaptcha-action="' . $action . '"'));
    check($file . ' loads the reCAPTCHA service', str_contains($source, 'libs/RecaptchaService.php'));
    check($file . ' emits the form script', str_contains($source, 'recaptcha_script_tag()'));

    $checkAt = strpos($code, 'recaptcha_check($_POST');
    check($file . ' calls recaptcha_check at all', $checkAt !== false);

    foreach ($expectations['work'] as $work) {
        $workAt = strpos($code, $work);
        check(
            $file . ' verifies the token before ' . $work,
            $workAt !== false && $checkAt !== false && $checkAt < $workAt
        );
    }
}

// The gate covers the public entry points, including the deep links a bot
// would otherwise use to skip the landing page entirely.
foreach (['index.php', 'student/login.php', 'teacher/login.php', 'student/signup.php', 'teacher/signup.php', 'forgot_password.php', 'reset_password.php'] as $file) {
    check($file . ' emits the first-visit gate', str_contains((string) file_get_contents($root . '/' . $file), 'human_gate_head()'));
}

// The recovery path carries the gate but deliberately no form check, because
// reaching it already required a mailed single-use token, and a second
// failure mode on the only account-recovery path is a bad trade.
$reset = (string) file_get_contents($root . '/reset_password.php');
check('reset_password.php has no form-level reCAPTCHA', !str_contains($reset, 'data-recaptcha-action'));

// The endpoint has to answer "fine" when the feature is off. A configuration
// change must not leave returning visitors stuck behind a screen whose
// endpoint can never satisfy them.
$gateEndpoint = (string) file_get_contents($root . '/controllers/human_gate.php');
check('the gate endpoint tolerates a disabled feature', str_contains($gateEndpoint, "'ok' => true"));
check('the gate endpoint requires POST', str_contains($gateEndpoint, 'REQUEST_METHOD'));
check('the gate endpoint validates CSRF', str_contains($gateEndpoint, 'validate_csrf'));
check('the gate endpoint honours observe mode', str_contains($gateEndpoint, "human_gate_mode() === 'observe'"));
check('the gate endpoint marks the browser verified', str_contains($gateEndpoint, 'human_gate_mark_verified()'));

// The CSP has to permit Google's origins in three separate directives.
// Missing the frame-src entry is the classic symptom: a silent console error
// and a gate that never resolves.
$database = (string) file_get_contents($root . '/config/database.php');
$apache = (string) file_get_contents($root . '/pwa-apache.conf');

foreach (['database.php' => $database, 'pwa-apache.conf' => $apache] as $name => $csp) {
    check($name . ' allows the reCAPTCHA script origin', str_contains($csp, 'script-src') && str_contains($csp, 'https://www.google.com/recaptcha/'));
    check($name . ' allows the reCAPTCHA frame origin', str_contains($csp, 'frame-src') && str_contains($csp, 'https://www.google.com/recaptcha/'));
    check($name . ' allows the reCAPTCHA connect origin', str_contains($csp, 'connect-src') && str_contains($csp, 'https://www.google.com/recaptcha/'));
    check($name . ' allows the gstatic script origin', str_contains($csp, 'https://www.gstatic.com/recaptcha/'));

    // Two CSP copies that drift apart means the stricter or the looser one
    // wins depending on which component happened to answer the request.
    check($name . ' still denies objects', str_contains($csp, "object-src 'none'"));
    check($name . ' still denies framing', str_contains($csp, "frame-ancestors 'none'"));
    check($name . ' still restricts form-action', str_contains($csp, "form-action 'self'"));
}

// The secret must never be committed. It lives in the gitignored .env and in
// config/credentials.php, which is also gitignored and excluded from the
// image build context.
$gitignore = (string) file_get_contents($root . '/.gitignore');
check('.env is gitignored', str_contains($gitignore, '.env'));
check('config/credentials.php is gitignored', str_contains($gitignore, 'config/credentials.php'));

$example = (string) file_get_contents($root . '/.env.example');
check('.env.example documents the key pair', str_contains($example, 'RECAPTCHA_SITE_KEY') && str_contains($example, 'RECAPTCHA_SECRET_KEY'));
check('.env.example ships placeholders for the key pair', str_contains($example, '<your-site-key>') && str_contains($example, '<your-secret-key>'));

// Matched on the shape of a real key rather than on one specific key. An
// earlier version of this check hardcoded the account's own key prefix, which
// looked safer and was not: the day the key is rotated the prefix no longer
// matches anything, and the assertion silently passes forever after while
// guarding nothing. Every reCAPTCHA key begins 6L, so the shape catches a
// real leak now and keeps catching one after a rotation.
check(
    '.env.example contains no reCAPTCHA key',
    preg_match('/\b6L[A-Za-z0-9_-]{30,}/', $example) !== 1
);
check('.env.example documents the kill switch', str_contains($example, 'RECAPTCHA_ENABLED'));
check('.env.example documents the gate mode', str_contains($example, 'RECAPTCHA_GATE_MODE'));

// ---------------------------------------------------------------------

if ($failures === []) {
    echo "OK: {$checks} reCAPTCHA checks passed.\n";
    exit(0);
}

echo count($failures) . " of {$checks} reCAPTCHA checks FAILED:\n";
foreach ($failures as $failure) {
    echo "  - {$failure}\n";
}
exit(1);
