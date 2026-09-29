<?php
/**
 * reCAPTCHA v3 verification.
 *
 * Two independent controls live here.
 *
 * 1. Form checks on the public auth pages (login, signup, password-reset
 *    request). These are a *hard* control: a request without a valid token is
 *    refused before it can reach the credential check, the rate limiter, or
 *    the database. That ordering is the whole point. Bot traffic is expensive
 *    precisely because it is cheap for the attacker to generate and expensive
 *    for the portal to absorb, so the token has to gate the work, not decorate
 *    it.
 *
 * 2. The first-visit gate (human_gate). This is a *friction* control, not an
 *    access control, and the distinction matters. A cookie is readable and
 *    clearable by anyone, so a determined bot skips the splash by not running
 *    the script at all. What the gate actually buys is one verified round trip
 *    to Google for every cold visitor, which is what makes a scripted signup
 *    farm or a password-spray run expensive to operate. It must never be
 *    relied on as the thing that keeps an attacker out; that is control 1.
 *
 * Design notes worth knowing before editing:
 *
 *   - A missing or rejected token fails CLOSED. A transport failure to Google
 *     fails according to RECAPTCHA_FAIL_MODE, which defaults to OPEN. The
 *     asymmetry is deliberate: a bot can trivially omit the token, so treating
 *     absence as "probably fine" would remove the control entirely, whereas
 *     treating a Google outage as "probably a bot" would lock an entire school
 *     out of its portal at 8am on enrolment day. An attacker cannot trigger
 *     the open path for themselves -- reaching it requires controlling this
 *     host's network egress to google.com.
 *   - The hostname returned by Google is checked whenever one can be derived
 *     from SITE_URL. The site key is public, so a token minted for action
 *     "login" can be generated on any site at all; the hostname is the only
 *     thing binding it back to this deployment.
 *   - Configuration is read from the environment, with a matching PHP constant
 *     honoured first so config/credentials.php works the same way the rest of
 *     the database configuration does.
 *   - Every database timestamp written here is UTC via gmdate(), matching
 *     AuthService.
 */

define('RECAPTCHA_VERIFY_URL', 'https://www.google.com/recaptcha/api/siteverify');
define('RECAPTCHA_TOKEN_MAX', 4096);

// Bump whenever recaptcha.js or human_gate.js changes behaviour, so a
// returning visitor whose browser cached the old copy is not silently running
// the previous version against a newly deployed server. assets/ is served
// cache-first by the service worker, so without this a fix does not reach
// anyone until the shell cache name itself changes -- and the symptom is a
// fix that appears to do nothing, because the browser never fetched it.
define('RECAPTCHA_JS_VERSION', '20260929-2');

// Google documents a score of 0.0 (very likely a bot) to 1.0 (very likely a
// human). 0.5 is the commonly cited midpoint.
define('RECAPTCHA_DEFAULT_MIN_SCORE', 0.5);

// A more forgiving floor than the forms use, and deliberately so. A school
// campus egresses through a single NAT address, and a whole class opening the
// portal at the same moment is a signal reCAPTCHA has no way to tell apart
// from automation. Here a false positive denies the portal to all of them at
// once; on a login form it costs one person one retry.
define('RECAPTCHA_GATE_DEFAULT_MIN_SCORE', 0.3);

// The gate action name. Google caps actions at alphanumerics plus _ and -, and
// the name has to match on both sides: a token minted for a different action
// is rejected by recaptcha_verify().
define('HUMAN_GATE_ACTION', 'homepage_gate');
define('HUMAN_GATE_COOKIE', 'EDUPORTAL_HUMAN');

// 30 days. Long enough that a returning student is not asked again mid-term,
// short enough that a cleared or stolen cookie self-heals reasonably fast.
define('HUMAN_GATE_TTL', 2592000);

/**
 * Reads a reCAPTCHA setting from the environment, falling back to a PHP
 * constant of the same name.
 *
 * The constant wins so that config/credentials.php keeps working as the
 * single local-override file it already is for the database and mailer.
 */
function recaptcha_setting(string $name, string $default = ''): string
{
    if (defined($name)) {
        $value = trim((string) constant($name));
        if ($value !== '') {
            return $value;
        }
    }

    // getenv() returns false for "unset", which is why this cannot use ??. The
    // explicit emptiness test also keeps a deliberate "0" from being read as
    // absent -- RECAPTCHA_ENABLED=0 and RECAPTCHA_MIN_SCORE=0 are both real.
    $value = getenv($name);
    if ($value === false || trim((string) $value) === '') {
        return $default;
    }

    return trim((string) $value);
}

function recaptcha_site_key(): string
{
    return recaptcha_setting('RECAPTCHA_SITE_KEY');
}

function recaptcha_secret_key(): string
{
    return recaptcha_setting('RECAPTCHA_SECRET_KEY');
}

/**
 * Why reCAPTCHA is not running, in a sentence an operator can act on. Returns
 * '' when it is running.
 *
 * This exists because "reCAPTCHA is not working" and "reCAPTCHA is switched
 * off" look identical from outside: both serve a site with no tag and no
 * gate, no error, and no failed request to notice. On a deployment where the
 * feature is simply unconfigured that is correct and intended -- but it is also
 * exactly what a forgotten environment variable looks like, and there is no
 * other signal that the difference exists.
 *
 * Kept out of any response body. It names configuration, not data, but a
 * public endpoint has no business describing its own deployment.
 */
function recaptcha_setup_problem(): string
{
    if (recaptcha_enabled()) {
        return '';
    }

    if (recaptcha_setting('RECAPTCHA_ENABLED', '1') === '0') {
        return 'reCAPTCHA is disabled by RECAPTCHA_ENABLED=0.';
    }

    if (recaptcha_site_key() === '' && recaptcha_secret_key() === '') {
        return 'reCAPTCHA is not configured: neither RECAPTCHA_SITE_KEY nor RECAPTCHA_SECRET_KEY is set. '
            . 'Set both in the deployment environment, or set RECAPTCHA_ENABLED=0 to make the absence deliberate.';
    }

    return 'reCAPTCHA is not configured: '
        . (recaptcha_site_key() === '' ? 'RECAPTCHA_SITE_KEY' : 'RECAPTCHA_SECRET_KEY')
        . ' is missing. Both halves of the key pair are required; with either one absent the feature '
        . 'switches itself off rather than rendering a badge that verifies against no secret.';
}

/**
 * reCAPTCHA is active only when both halves of the key pair are present.
 * A site key without a secret would render a badge that verifies nothing; a
 * secret without a site key is a credential the server has no way to use.
 */
function recaptcha_enabled(): bool
{
    if (recaptcha_setting('RECAPTCHA_ENABLED', '1') === '0') {
        return false;
    }

    return recaptcha_site_key() !== '' && recaptcha_secret_key() !== '';
}

/**
 * 'open' or 'closed'. Applies to a failure reaching Google, never to a token
 * that is missing or that Google rejected.
 */
function recaptcha_fail_mode(): string
{
    return recaptcha_setting('RECAPTCHA_FAIL_MODE', 'open') === 'closed' ? 'closed' : 'open';
}

/**
 * Per-action score floor. RECAPTCHA_MIN_SCORE_<ACTION> wins over the shared
 * RECAPTCHA_MIN_SCORE, so a noisier action can be loosened without weakening
 * the rest -- for example RECAPTCHA_MIN_SCORE_SIGNUP=0.4.
 */
function recaptcha_min_score(string $action): float
{
    // The first-visit gate carries its own floor, which is not the shared one:
    // refusing a real visitor at the splash denies them the whole portal, not
    // just one form, so it is tuned separately.
    if ($action === HUMAN_GATE_ACTION) {
        return human_gate_min_score();
    }

    $namespaced = 'RECAPTCHA_MIN_SCORE_' . strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $action));

    $value = recaptcha_setting($namespaced);
    if ($value === '') {
        $value = recaptcha_setting('RECAPTCHA_MIN_SCORE');
    }
    if ($value === '') {
        return RECAPTCHA_DEFAULT_MIN_SCORE;
    }

    if (!is_numeric($value)) {
        return RECAPTCHA_DEFAULT_MIN_SCORE;
    }

    return max(0.0, min(1.0, (float) $value));
}

/**
 * The host this deployment answers to, or '' when it cannot be determined.
 * The port is dropped because Google reports a bare hostname.
 */
function recaptcha_expected_hostname(): string
{
    $configured = recaptcha_setting('RECAPTCHA_EXPECTED_HOSTNAME');

    if ($configured !== '') {
        $source = $configured;
    } elseif (defined('SITE_URL')) {
        // config/database.php already resolved SITE_URL from the environment
        // or credentials.php, so this is the authoritative answer in a
        // deployed app.
        $source = (string) SITE_URL;
    } else {
        // Reachable only when this file is used without database.php, which
        // is the test suite. Reading the raw environment keeps the fallback
        // testable instead of only being reachable in production.
        $source = recaptcha_setting('SITE_URL');
    }

    if ($source === '') {
        return '';
    }

    $host = parse_url($source, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        $host = $configured;
    }

    return strtolower(trim($host));
}

/**
 * Whether a hostname check can be enforced at all.
 *
 * False when this deployment's own host is unknown, which is what a missing
 * SITE_URL looks like: config/database.php falls back to
 * 'http://localhost/Eduportal', so the derived hostname is 'localhost' on a
 * host that is plainly not localhost. Enforcing that would fail every login
 * on the portal. A security control must never be the reason a school cannot
 * sign in, so the check steps aside and says so in the log rather than
 * quietly protecting nothing.
 */
function recaptcha_hostname_check_usable(?string $expected = null): bool
{
    $expected = $expected ?? recaptcha_expected_hostname();

    return $expected !== '' && $expected !== 'localhost';
}

function recaptcha_client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return is_string($ip) && $ip !== '' ? substr($ip, 0, 45) : '';
}

/**
 * Seconds allowed for the siteverify round trip.
 *
 * Deliberately short. This sits in front of a login, so waiting on it is
 * waiting on a human; a slow call is also the signature of a network that
 * cannot reach Google, and the caller is better served by falling through to
 * the fail-mode policy than by holding the page open.
 */
function recaptcha_http_timeout(): int
{
    $value = recaptcha_setting('RECAPTCHA_HTTP_TIMEOUT', '5');

    if (!is_numeric($value)) {
        return 5;
    }

    return max(1, min(15, (int) $value));
}

/**
 * POSTs to the siteverify endpoint.
 *
 * Returns ['ok' => bool, 'body' => array|null, 'error' => string]. `ok` is a
 * transport verdict only: it is false when the request never produced a
 * readable JSON document, which is what the fail-mode policy keys on.
 */
function recaptcha_call_siteverify(array $fields): array
{
    $body = http_build_query($fields);
    $timeout = recaptcha_http_timeout();
    $raw = null;

    if (function_exists('curl_init')) {
        $handle = curl_init(RECAPTCHA_VERIFY_URL);
        if ($handle === false) {
            return ['ok' => false, 'body' => null, 'error' => 'curl_init failed'];
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // The target is a compile-time constant, so a redirect could only
            // come from a compromised TLS path. Following one anyway would
            // post the secret key somewhere the URL did not name.
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $raw = curl_exec($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($raw === false || $error !== '') {
            return ['ok' => false, 'body' => null, 'error' => $error !== '' ? $error : 'request failed'];
        }
        if ($status < 200 || $status >= 300) {
            return ['ok' => false, 'body' => null, 'error' => 'siteverify returned HTTP ' . $status];
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $body,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $raw = @file_get_contents(RECAPTCHA_VERIFY_URL, false, $context);

        if ($raw === false) {
            return ['ok' => false, 'body' => null, 'error' => 'stream request failed'];
        }
    }

    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'body' => null, 'error' => 'siteverify returned unreadable JSON'];
    }

    return ['ok' => true, 'body' => $decoded, 'error' => ''];
}

/**
 * Verifies a single token against one action.
 *
 * Result shape:
 *   ok       bool    whether the request may proceed
 *   reason   string  machine-readable verdict, also used as the audit outcome
 *   score    float|null
 *   action   string
 *   error    string  user-facing message, '' when ok
 *
 * The message is identical for every failure on purpose. Distinguishing
 * "your score was too low" from "your token was missing" tells an attacker
 * which half of the defence to work on, and tells a real user nothing they can
 * act on beyond retrying.
 */
function recaptcha_verify(string $token, string $action): array
{
    if (!recaptcha_enabled()) {
        return recaptcha_result(true, 'disabled', null, $action, '');
    }

    $action = preg_replace('/[^A-Za-z0-9_-]+/', '_', $action) ?: 'request';

    if ($token === '' || strlen($token) > RECAPTCHA_TOKEN_MAX) {
        return recaptcha_result(false, 'missing_token', null, $action, recaptcha_user_error());
    }

    $fields = [
        'secret' => recaptcha_secret_key(),
        'response' => $token,
    ];

    $remoteIp = recaptcha_client_ip();
    if ($remoteIp !== '') {
        $fields['remoteip'] = $remoteIp;
    }

    $response = recaptcha_call_siteverify($fields);

    if (!$response['ok']) {
        // Could not reach Google. Policy decides, and the audit records which
        // way it went so a misconfigured deployment is visible in the log
        // rather than only in a support ticket.
        $ok = recaptcha_fail_mode() === 'open';
        error_log('EduPortal reCAPTCHA verification unavailable (' . $action . '): ' . $response['error']);

        return recaptcha_result($ok, $ok ? 'unavailable_allowed' : 'unavailable_blocked', null, $action, $ok ? '' : recaptcha_user_error());
    }

    $data = $response['body'];

    if (empty($data['success'])) {
        $codes = isset($data['error-codes']) && is_array($data['error-codes'])
            ? array_map('strval', $data['error-codes'])
            : [];

        // Three of these mean the deployment is misconfigured rather than
        // that the visitor is a bot, and they need a completely different
        // response: a key whose hostname is not registered in the reCAPTCHA
        // console produces "browser-error" for every single visitor, and
        // treating that as bot traffic would fill the audit log with noise and
        // (under a stricter gate mode) lock the school out of its own portal.
        if (array_intersect($codes, ['invalid-input-secret', 'missing-input-secret', 'browser-error']) !== []) {
            error_log('EduPortal reCAPTCHA configuration error (' . $action . '): ' . implode(',', $codes)
                . '. Check that the site key and secret belong together and that this host is registered in the reCAPTCHA console.');

            return recaptcha_result(false, 'configuration_error', null, $action, recaptcha_user_error());
        }

        // "timeout-or-duplicate" is the replay response: a token is single
        // use and short lived, so this is either a double submit or a replay.
        $reason = in_array('timeout-or-duplicate', $codes, true) ? 'duplicate_token' : 'invalid_token';
        error_log('EduPortal reCAPTCHA rejected token (' . $action . '): ' . ($codes !== [] ? implode(',', $codes) : 'unspecified'));

        return recaptcha_result(false, $reason, null, $action, recaptcha_user_error());
    }

    $returnedAction = isset($data['action']) && is_string($data['action']) ? $data['action'] : '';
    if (strcasecmp($returnedAction, $action) !== 0) {
        return recaptcha_result(false, 'action_mismatch', null, $action, recaptcha_user_error());
    }

    // The site key is public, so a token can be minted for any action from any
    // site. The hostname is the only binding back to this deployment, so a
    // mismatch is treated as a rejection rather than a warning.
    if (recaptcha_hostname_check_usable()) {
        $expected = recaptcha_expected_hostname();
        $hostname = isset($data['hostname']) && is_string($data['hostname']) ? strtolower($data['hostname']) : '';
        if ($hostname !== $expected) {
            return recaptcha_result(false, 'hostname_mismatch', null, $action, recaptcha_user_error());
        }
    } elseif (recaptcha_setting('RECAPTCHA_EXPECTED_HOSTNAME') === '') {
        error_log(
            'EduPortal reCAPTCHA hostname check skipped for ' . $action . ': this deployment\'s own host is '
            . 'not known. Set RECAPTCHA_EXPECTED_HOSTNAME, or SITE_URL, so tokens can be bound to it.'
        );
    }

    $rawScore = $data['score'] ?? null;
    $score = is_numeric($rawScore) ? (float) $rawScore : null;

    if ($score === null) {
        return recaptcha_result(false, 'missing_score', null, $action, recaptcha_user_error());
    }

    $minimum = recaptcha_min_score($action);
    if ($score < $minimum) {
        return recaptcha_result(false, 'low_score', $score, $action, recaptcha_user_error());
    }

    return recaptcha_result(true, 'ok', $score, $action, '');
}

function recaptcha_user_error(): string
{
    return 'We could not verify that you are human. Please reload the page and try again.';
}

function recaptcha_result(bool $ok, string $reason, ?float $score, string $action, string $error): array
{
    return [
        'ok' => $ok,
        'reason' => $reason,
        'score' => $score,
        'action' => $action,
        'error' => $error,
    ];
}

/**
 * Pulls g-recaptcha-response out of a request body and verifies it.
 *
 * $context is optional audit metadata. Pass 'conn' to have a refusal written
 * to auth_events alongside the existing login and reset events, so a bot
 * campaign is visible in the same trail as the credential guessing it is
 * usually a prelude to.
 */
function recaptcha_check(array $source, string $action, array $context = []): array
{
    $token = isset($source['g-recaptcha-response']) && is_string($source['g-recaptcha-response'])
        ? trim($source['g-recaptcha-response'])
        : '';

    $result = recaptcha_verify($token, $action);

    if (!$result['ok'] && isset($context['conn'])) {
        recaptcha_audit($result, $context);
    }

    return $result;
}

/**
 * Never throws. An audit write failure must not turn a refused request into a
 * 500, which would leak a stack trace to the very client being blocked, and a
 * refused request must not depend on the database being reachable at all --
 * auth_record_event() already fails open for the same reason.
 */
function recaptcha_audit(array $result, array $context = []): void
{
    $conn = $context['conn'] ?? null;
    if (!is_object($conn)) {
        return;
    }

    if (!function_exists('auth_record_event') && file_exists(__DIR__ . '/AuthService.php')) {
        require_once __DIR__ . '/AuthService.php';
    }
    if (!function_exists('auth_record_event')) {
        return;
    }

    $detail = 'action=' . $result['action'];
    if ($result['score'] !== null) {
        $detail .= ' score=' . $result['score'];
    }
    $detail .= ' reason=' . $result['reason'];

    $audit = ['detail' => $detail];
    foreach (['user_role', 'user_id', 'identifier'] as $key) {
        if (isset($context[$key])) {
            $audit[$key] = $context[$key];
        }
    }

    try {
        auth_record_event($conn, 'recaptcha_check', $result['reason'], $audit);
    } catch (Throwable $exception) {
        error_log('EduPortal reCAPTCHA audit write failed: ' . $exception->getMessage());
    }
}

/**
 * A connection for the audit write, or null when one is not available.
 *
 * The first-visit gate is the reason this exists. It is the first thing a new
 * visitor hits, so opening a database connection on its critical path turns a
 * database blip into "the whole portal is unavailable" for every cold browser
 * on the site -- when the gate's actual decision needs nothing but the token
 * and a call to Google. A missing audit row is a much smaller problem than a
 * missing portal, and the same reasoning auth_record_event() already applies
 * to its own writes.
 */
function recaptcha_audit_connection()
{
    if (!function_exists('getDBConnection')) {
        return null;
    }

    try {
        return getDBConnection();
    } catch (Throwable $exception) {
        error_log('EduPortal reCAPTCHA audit connection unavailable: ' . $exception->getMessage());
        return null;
    }
}

// ---------------------------------------------------------------------
// First-visit gate
// ---------------------------------------------------------------------

/**
 * 'enforce' blocks a low score, 'observe' records it and waves the visitor
 * through, and 'off' disables the gate entirely.
 *
 * 'observe' exists because a shared campus address is a genuine false-positive
 * risk. If a class is being turned away, switching to it restores access
 * immediately without a redeploy -- and the audit trail still records who
 * scored badly, so the decision is informed rather than blind.
 */
function human_gate_mode(): string
{
    $mode = strtolower(recaptcha_setting('RECAPTCHA_GATE_MODE', 'enforce'));

    return in_array($mode, ['enforce', 'observe', 'off'], true) ? $mode : 'enforce';
}

function human_gate_min_score(): float
{
    $value = recaptcha_setting('RECAPTCHA_GATE_MIN_SCORE');
    if ($value === '' || !is_numeric($value)) {
        return RECAPTCHA_GATE_DEFAULT_MIN_SCORE;
    }

    return max(0.0, min(1.0, (float) $value));
}

function human_gate_secret(): string
{
    return recaptcha_setting('HUMAN_GATE_SECRET');
}

/**
 * The gate is only worth rendering when it is both switched on and has not
 * already been satisfied for this browser.
 */
function human_gate_active(): bool
{
    if (!recaptcha_enabled() || human_gate_mode() === 'off') {
        return false;
    }

    return !human_gate_is_verified();
}

function human_gate_is_verified(): bool
{
    // The ?? rather than a bare index, so this is safe on a request that
    // included this file before a session was started.
    if (!empty($_SESSION['human_verified'] ?? null)) {
        return true;
    }

    $secret = human_gate_secret();
    if ($secret === '') {
        return false;
    }

    $receipt = $_COOKIE[HUMAN_GATE_COOKIE] ?? '';
    if (!is_string($receipt) || $receipt === '') {
        return false;
    }

    $parts = explode('.', $receipt);
    if (count($parts) !== 3 || $parts[0] !== 'v1') {
        return false;
    }

    $expiry = $parts[1];
    if (!ctype_digit($expiry) || (int) $expiry <= time()) {
        return false;
    }

    return hash_equals(recaptcha_gate_signature($secret, (int) $expiry), $parts[2]);
}

function recaptcha_gate_signature(string $secret, int $expiry): string
{
    return hash_hmac('sha256', 'eduportal-human-gate|v1|' . $expiry, $secret);
}

function human_gate_mark_verified(): void
{
    $_SESSION['human_verified'] = true;

    $secret = human_gate_secret();
    if ($secret === '') {
        // Session-only. Without a configured secret there is nothing durable
        // to sign a receipt with, and inventing one per request would produce
        // a cookie that never validates on the next page load.
        return;
    }

    $expiry = time() + HUMAN_GATE_TTL;
    $receipt = 'v1.' . $expiry . '.' . recaptcha_gate_signature($secret, $expiry);

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    setcookie(HUMAN_GATE_COOKIE, $receipt, [
        'expires' => $expiry,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Emits the <head> block for the first-visit gate: preconnects plus the
 * script itself.
 *
 * Emitting it from here rather than repeating it on every page keeps the
 * script list, the cache-buster, and the enabled/verified decision in one
 * place. A page that adds a form and forgets the gate, or vice versa, is the
 * kind of drift this codebase has already been bitten by.
 *
 * Call it as early in <head> as possible -- the script is deliberately not
 * deferred, because the overlay has to exist before the first paint. A visitor
 * who sees the site and then has it covered has already read it.
 *
 * Root-absolute paths, matching requireLogin()'s redirects: this runs on pages
 * one and two directories deep.
 */
function human_gate_head(): string
{
    // Only emitted when the gate actually has work to do. A verified visitor
    // pays nothing for the script, not even a conditional request.
    if (!human_gate_active()) {
        return '';
    }

    $config = [
        // Strings, not booleans. The HTML parser lowercases attribute names, so
        // 'siteKey' would arrive in the DOM as data-sitekey and never reach
        // dataset.siteKey -- and a boolean true stringifies to "1", which the
        // strict === 'true' on the client would reject. Kebab-case keys and
        // explicit string values are the only safe pairing here.
        'enabled' => 'true',
        'site-key' => recaptcha_site_key(),
        'endpoint' => '/controllers/human_gate.php',
        'mode' => human_gate_mode(),
        // The gate is a POST, so it carries a CSRF token like every other
        // state-changing request in the application. Rendered here rather than
        // read from a global so the page that emits the script owns it.
        'csrf' => function_exists('csrf_token') ? csrf_token() : '',
    ];

    $attributes = '';
    foreach ($config as $name => $value) {
        $attributes .= ' data-' . $name . '="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '"';
    }

    $version = RECAPTCHA_JS_VERSION;

    return '<link rel="preconnect" href="https://www.google.com">' . "\n"
        . '<link rel="preconnect" href="https://www.gstatic.com" crossorigin>' . "\n"
        . '<script src="/assets/js/human_gate.js?v=' . $version . '"' . $attributes . '></script>' . "\n";
}

/**
 * Emits the form-token script. Goes at the end of <body>, before
 * system_loader.js is irrelevant but before nothing else cares.
 *
 * Emits nothing when reCAPTCHA is unconfigured, so a deployment without keys
 * neither requests Google's script nor attaches a submit interceptor.
 */
function recaptcha_script_tag(): string
{
    if (!recaptcha_enabled()) {
        return '';
    }

    return '<script src="/assets/js/recaptcha.js?v=' . RECAPTCHA_JS_VERSION . '"'
        . ' data-site-key="' . htmlspecialchars(recaptcha_site_key(), ENT_QUOTES, 'UTF-8') . '"></script>' . "\n";
}
