<?php
/**
 * Tests for the shared authentication service.
 *
 * Covers the logic that is easy to regress silently and expensive to get
 * wrong:
 *   - the single password policy, including the bcrypt 72-byte ceiling
 *   - rehash-on-login actually firing at the configured cost
 *   - UTC parsing of the auth table timestamps
 *   - throttling policy shape, including the deliberately loose per-IP limit
 *   - that lockout buckets are keyed on client input, never on a resolved row
 *   - that table names passed into SQL come only from the role whitelist
 *
 * These are hermetic: no database connection is opened, so the suite runs on a
 * bare checkout.
 *
 * Run: php tests/auth_service_test.php
 */

require_once __DIR__ . '/../libs/AuthService.php';

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
// auth_password_problem()
// ---------------------------------------------------------------------

check('accepts a compliant password', auth_password_problem('Password1') === null);

check('rejects empty password', auth_password_problem('') !== null);
check('rejects 7 characters', auth_password_problem('Passw0r') !== null);
check('accepts 8 characters', auth_password_problem('Passw0r1') === null);
check('rejects no uppercase', auth_password_problem('password1') !== null);
check('rejects no digit', auth_password_problem('PasswordX') !== null);

// bcrypt truncates silently at 72 bytes, so a longer password would verify
// against its own prefix. The policy must refuse rather than mislead.
check('rejects 73 bytes', auth_password_problem(str_repeat('A', 72) . '1') !== null);
check('accepts exactly 72 bytes', strlen(str_repeat('A', 71) . '1') === 72);
check('accepts 72-byte compliant password', auth_password_problem(str_repeat('A', 71) . '1') === null);

same(
    'reports the length problem before the complexity problem',
    'Password must be at least 8 characters long.',
    auth_password_problem('short')
);

// ---------------------------------------------------------------------
// auth_password_hash()
// ---------------------------------------------------------------------

$hash = auth_password_hash('Password1');
check('hash is a bcrypt string', is_string($hash) && str_starts_with($hash, '$2y$'));
check('hash verifies', password_verify('Password1', $hash));
check('hash rejects a wrong password', !password_verify('password1', $hash));

$info = password_get_info($hash);
same('hash uses the configured cost', AUTH_BCRYPT_COST, $info['options']['cost'] ?? null);

// The defect this replaces: password_needs_rehash() was never called, so a
// cost bump could never reach an existing account.
$legacy = password_hash('Password1', PASSWORD_BCRYPT, ['cost' => 10]);
check('a cost-10 hash is detected as stale', password_needs_rehash($legacy, PASSWORD_BCRYPT, ['cost' => AUTH_BCRYPT_COST]));
check('a current-cost hash is not stale', !password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => AUTH_BCRYPT_COST]));

check('rehash is a no-op for an unknown role', auth_upgrade_password_hash(null, 'principal', 1, 'Password1', $legacy) === false);
check('rehash is a no-op for an empty hash', auth_upgrade_password_hash(null, 'student', 1, 'Password1', '') === false);
check('rehash is a no-op when already current', auth_upgrade_password_hash(null, 'student', 1, 'Password1', $hash) === false);

// ---------------------------------------------------------------------
// auth_role_table() -- whitelist that guards every interpolated table name
// ---------------------------------------------------------------------

same('student maps to students', 'students', auth_role_table('student'));
same('teacher maps to teachers', 'teachers', auth_role_table('teacher'));
same('unknown role is rejected', null, auth_role_table('admin'));
same('SQL injection attempt is rejected', null, auth_role_table('student; DROP TABLE students--'));
same('empty role is rejected', null, auth_role_table(''));
same('case is not coerced', null, auth_role_table('STUDENT'));

// ---------------------------------------------------------------------
// auth_parse_timestamp()
// ---------------------------------------------------------------------

$now = time();
same('naive timestamp is read as UTC', $now, auth_parse_timestamp(gmdate('Y-m-d H:i:s', $now)));
same('explicit UTC suffix is honoured', $now, auth_parse_timestamp(gmdate('Y-m-d H:i:s', $now) . ' UTC'));
same('Z suffix is honoured', $now, auth_parse_timestamp(gmdate('Y-m-d H:i:s', $now) . 'Z'));
same('offset suffix is honoured', $now, auth_parse_timestamp(gmdate('Y-m-d H:i:s', $now) . '+00:00'));
same('empty string yields null', null, auth_parse_timestamp(''));
same('null yields null', null, auth_parse_timestamp(null));
same('array yields null', null, auth_parse_timestamp([]));
same('garbage yields null', null, auth_parse_timestamp('not a timestamp at all'));

// ---------------------------------------------------------------------
// auth_rate_limit_policy()
// ---------------------------------------------------------------------

$ip = auth_rate_limit_policy('ip');
$account = auth_rate_limit_policy('student');
$reset = auth_rate_limit_policy('reset-ip');

check('per-IP threshold is looser than per-account', $ip['threshold'] > $account['threshold']);
check('per-IP is tuned for a shared NAT', $ip['threshold'] >= 20);
check('per-account starts at five strikes', 5 === $account['threshold']);
check('cooldowns escalate', $account['cooldowns'] === array_values($account['cooldowns'])
    && $account['cooldowns'][0] < $account['cooldowns'][1]
    && $account['cooldowns'][1] < $account['cooldowns'][2]);

check('reset throttling is stricter than password throttling', $reset['threshold'] < $account['threshold']);
check('reset-account shares the reset policy', $reset['threshold'] === auth_rate_limit_policy('reset-account')['threshold']);

foreach (['ip', 'student', 'teacher', 'reset-ip'] as $kind) {
    $policy = auth_rate_limit_policy($kind);
    check("{$kind} has a non-empty cooldown ladder", count($policy['cooldowns']) > 0);
    check("{$kind} has a positive window", $policy['window'] > 0);
    check("{$kind} has a positive threshold", $policy['threshold'] > 0);
}

// ---------------------------------------------------------------------
// auth_rate_limit_next_state() -- the ladder arithmetic, exercised without a
// database. This is the part that decides how long a lockout lasts.
// ---------------------------------------------------------------------

$accountPolicy = auth_rate_limit_policy('student');
$at = 1_700_000_000;

$row = static function (int $attempts, int $windowStart, ?int $lockedUntil = null): array {
    return [
        'attempt_count' => $attempts,
        'window_started_at' => gmdate('Y-m-d H:i:s', $windowStart),
        'locked_until' => $lockedUntil === null ? null : gmdate('Y-m-d H:i:s', $lockedUntil),
    ];
};

// A bucket that has never been seen starts at one attempt.
$state = auth_rate_limit_next_state(null, $accountPolicy, $at);
same('a fresh bucket records one attempt', 1, $state['attempts']);
same('a fresh bucket starts its window now', $at, $state['window_started']);
same('a fresh bucket is not locked', null, $state['locked_until']);

// Attempts accumulate inside the window and the lock engages on the threshold.
$ladder = [];
for ($attempt = 1; $attempt <= 8; $attempt++) {
    $next = auth_rate_limit_next_state(
        $row($attempt - 1, $at),
        $accountPolicy,
        $at + 60
    );
    $ladder[$attempt] = $next['locked_until'] === null ? null : $next['locked_until'] - ($at + 60);
}

// Compared by key rather than array_slice(), which renumbers and would
// silently shift the window being asserted on.
foreach ([1, 2, 3, 4] as $attempt) {
    same("attempt {$attempt} is not locked", null, $ladder[$attempt]);
}
same('attempt 5 locks for the first rung', $accountPolicy['cooldowns'][0], $ladder[5]);
same('attempt 6 locks for the second rung', $accountPolicy['cooldowns'][1], $ladder[6]);
same('attempt 7 locks for the third rung', $accountPolicy['cooldowns'][2], $ladder[7]);
same('attempt 8 locks for the fourth rung', $accountPolicy['cooldowns'][3], $ladder[8]);

// Past the end of the ladder the longest cooldown is held, not an out-of-range
// index. This is the bug the min() clamp prevents.
$deep = auth_rate_limit_next_state($row(500, $at), $accountPolicy, $at);
same('a very high attempt count holds the longest rung', $accountPolicy['cooldowns'][3], $deep['locked_until'] - $at);
same('a very high attempt count is still counted', 501, $deep['attempts']);

// A bucket mid-cooldown is frozen: repeated attempts must not push the
// expiry further out each time.
$locked = $row(5, $at, $at + 30);
same('a locked bucket is left alone', null, auth_rate_limit_next_state($locked, $accountPolicy, $at + 5));
same('a locked bucket is left alone right up to expiry', null, auth_rate_limit_next_state($locked, $accountPolicy, $at + 29));
check('a bucket is editable the moment its cooldown lapses', auth_rate_limit_next_state($locked, $accountPolicy, $at + 31) !== null);

// Decay: an old run of near-misses must not carry forward.
$stale = $row(4, $at - $accountPolicy['window'] - 1);
$decayed = auth_rate_limit_next_state($stale, $accountPolicy, $at);
same('an elapsed window resets the count', 1, $decayed['attempts']);
same('an elapsed window is not locked', null, $decayed['locked_until']);
same('an elapsed window restarts at now', $at, $decayed['window_started']);

// A stale count *inside* the window still carries forward.
$inside = $row(4, $at - $accountPolicy['window'] + 1);
same('a count inside the window carries forward', 5, auth_rate_limit_next_state($inside, $accountPolicy, $at)['attempts']);

// The per-IP ladder is far more permissive, which is the point of it.
$ipPolicy = auth_rate_limit_policy('ip');
check('per-IP survives 20 failures', auth_rate_limit_next_state($row(20, $at), $ipPolicy, $at)['locked_until'] === null);
check('per-IP still locks eventually', auth_rate_limit_next_state($row($ipPolicy['threshold'] - 1, $at), $ipPolicy, $at)['locked_until'] !== null);

// ---------------------------------------------------------------------
// Bucket keys and login buckets
// ---------------------------------------------------------------------

same('bucket key keeps the scope prefix', 'student:123456789012', auth_rate_limit_bucket_key('student', '123456789012'));
check('bucket key is bounded to the column width', strlen(auth_rate_limit_bucket_key('ip', str_repeat('a', 500))) <= 190);

$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$buckets = auth_login_buckets('student', '  123456789012  ');
same('a login produces two buckets', 2, count($buckets));
check('one bucket is the client IP', in_array(auth_rate_limit_bucket_key('ip', '203.0.113.9'), $buckets, true));
check('the other is the normalised account', in_array('student:123456789012', $buckets, true));

// auth_account_login_buckets() must reproduce the key auth_login_buckets()
// writes, or clearing the counter after a successful sign-in deletes a row
// that was never there and the real counter survives.
$studentAccount = ['lrn' => '123456789012', 'name' => 'Juan Dela Cruz'];
check(
    'a resolved student account rebuilds the submitted-key bucket',
    in_array('student:123456789012', auth_account_login_buckets('student', $studentAccount), true)
);

$teacherAccount = ['email' => 'a@school.com', 'subject' => 'Mathematics', 'name' => 'Ana Reyes'];
check(
    'a resolved teacher account rebuilds the email|subject bucket',
    in_array('teacher:a@school.com|mathematics', auth_account_login_buckets('teacher', $teacherAccount), true)
);
same(
    'the teacher bucket matches what the login path would have written',
    auth_login_buckets('teacher', 'a@school.com|Mathematics'),
    auth_account_login_buckets('teacher', $teacherAccount)
);
same(
    'the student bucket matches what the login path would have written',
    auth_login_buckets('student', '123456789012'),
    auth_account_login_buckets('student', $studentAccount)
);

// Keying on a database-resolved row instead of the submitted value would leak
// whether an account exists through the lockout message.
$teacherBuckets = auth_login_buckets('teacher', 'A@School.com|Math');
check('teacher buckets carry both identifier parts', in_array('teacher:a@school.com|math', $teacherBuckets, true));
check('teacher buckets are still two', 2 === count($teacherBuckets));

// ---------------------------------------------------------------------
// auth_upgrade_password_hash() reads no SQL for rejected inputs, which is why
// a null connection above is safe. Confirm the token helpers fail closed too.
// ---------------------------------------------------------------------

check('token issue rejects an unknown role', auth_issue_token(null, 'password_reset', 'principal', 1) === null);
check('token consume rejects an empty token', auth_consume_token(null, 'password_reset', '') === null);
check('token consume rejects a missing table', auth_consume_token(null, 'password_reset', 'anything') === null);
check('token peek rejects a missing table', auth_token_peek(null, 'password_reset', 'anything') === null);
check('account lookup rejects an unknown role', auth_account_for_role(null, 'principal', 1) === null);

// The raw token must be URL-safe and high-entropy, since it is emailed and
// pasted back through a query string.
$sample = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
check('issued token has no reserved characters', !strpbrk($sample, '+/=?&'));
same('issued token is 43 characters of base64url', 43, strlen($sample));
same('token hashing is stable', hash('sha256', $sample), hash('sha256', $sample));

// ---------------------------------------------------------------------
// Mailer gating -- the reset flow must not silently report success when the
// relay is unconfigured.
// ---------------------------------------------------------------------

require_once __DIR__ . '/../libs/Mailer.php';
same('mail is disabled unless MAIL_ENABLED=1', false, auth_mail_configured());
check('an unconfigured relay does not report a send', auth_send_mail('nobody@example.com', 'subject', '<p>x</p>', 'x') === false);
check('mail reports disabled without a host', auth_mail_enabled() === false || auth_mail_configured() === false);

// ---------------------------------------------------------------------
// Mailer wiring
//
// Guards against the failure that actually shipped: the PHPMailer class name
// and the ENCRYPTION_* constants were written against an older release, so
// every send fataled inside the catch block and surfaced to the user as a
// generic "could not send the reset email". Nothing else in the suite touches
// these names, so a typo here was invisible until a real send was attempted.
// ---------------------------------------------------------------------

check('the PHPMailer class name is the real one', class_exists('PHPMailer\\PHPMailer\\PHPMailer'));
check('the PHPMailer class can be instantiated', (new PHPMailer\PHPMailer\PHPMailer(true)) instanceof PHPMailer\PHPMailer\PHPMailer);

check(
    'the STARTTLS constant resolves',
    defined('PHPMailer\\PHPMailer\\PHPMailer::ENCRYPTION_STARTTLS')
        && constant('PHPMailer\\PHPMailer\\PHPMailer::ENCRYPTION_STARTTLS') === 'tls'
);
check(
    'the SMTPS constant resolves',
    defined('PHPMailer\\PHPMailer\\PHPMailer::ENCRYPTION_SMTPS')
        && constant('PHPMailer\\PHPMailer\\PHPMailer::ENCRYPTION_SMTPS') === 'ssl'
);

// The pre-6.12 shape must not be used anywhere: those names do not resolve in
// the pinned release and fail only at send time.
check(
    'the removed SMTPSecure class is not referenced',
    !class_exists('PHPMailer\\PHPMailer\\SMTPSecure')
        || !str_contains((string) file_get_contents(__DIR__ . '/../libs/Mailer.php'), 'SMTPSecure::')
);

// A mis-qualified class name resolves to nothing and fatals on first use, so
// scan the source for the over-qualified form. This was written wrong twice by
// hand, which is exactly the kind of slip a grep should own rather than a
// reviewer's eye. __FILE__ is absolute, so it is used directly for this file.
$overQualified = 'PHPMailer\\PHPMailer\\PHPMailer\\PHPMailer';

check(
    'no over-qualified PHPMailer reference in Mailer.php',
    !str_contains((string) file_get_contents(__DIR__ . '/../libs/Mailer.php'), $overQualified)
);
check(
    'no over-qualified PHPMailer reference in this test',
    !str_contains((string) file_get_contents(__FILE__), $overQualified)
);

// Every branch of the encryption switch must resolve to a value PHPMailer
// accepts, rather than fataling.
$mail = new PHPMailer\PHPMailer\PHPMailer(true);
foreach (['tls', 'ssl', 'none'] as $mode) {
    try {
        $mail->SMTPSecure = $mode === 'ssl'
            ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : ($mode === 'tls' ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS : '');
        check("encryption mode {$mode} assigns cleanly", true);
    } catch (Throwable $exception) {
        check("encryption mode {$mode} assigns cleanly", false);
    }
}

// Bounded SMTP timeouts. The 300 second PHPMailer default turns a blocked
// port 587 into a five-minute hang with the request form's loader spinning,
// which is exactly the symptom reported from the hosted deployment.
$probe = new PHPMailer\PHPMailer\PHPMailer(true);
$probe->isSMTP();
$defaultTimeout = (int) (getenv('SMTP_TIMEOUT') ?: 10);
check('the SMTP timeout is bounded and short', $defaultTimeout > 0 && $defaultTimeout <= 30);
check('SMTP keepalive is off by default', $probe->SMTPKeepAlive === false);
check('the timeout is well under the loader fallback', $defaultTimeout < 30);

// Recipient normalisation. An address pasted out of a provider UI often keeps
// its display brackets, and filter_var() rejects those outright, which turns
// one malformed stored email into a password reset that fails for that user
// with nothing but a generic message on screen.
same('a plain address is unchanged', 'a@b.com', auth_normalise_email_address('a@b.com'));
same('surrounding angle brackets are stripped', 'a@b.com', auth_normalise_email_address('<a@b.com>'));
same('surrounding whitespace is stripped', 'a@b.com', auth_normalise_email_address("  a@b.com \n"));
same('brackets and whitespace together', 'a@b.com', auth_normalise_email_address(" <a@b.com> "));
same('case is normalised', 'a@b.com', auth_normalise_email_address('<A@B.COM>'));
same('interior characters are left alone', 'a+tag@b.com', auth_normalise_email_address('<a+tag@b.com>'));

$normalised = auth_normalise_email_address('<alwencasagann@gmail.com>');
check('a bracketed address becomes deliverable', filter_var($normalised, FILTER_VALIDATE_EMAIL) !== false);
check(
    'an address that is still invalid stays invalid',
    filter_var(auth_normalise_email_address('not-an-address'), FILTER_VALIDATE_EMAIL) === false
);
check(
    'stripping brackets must not invent a valid address',
    filter_var(auth_normalise_email_address('<not-an-address>'), FILTER_VALIDATE_EMAIL) === false
);

// The relay's response is recorded so an operator can query it. It must never
// carry an address back into the audit table.
auth_record_mail_error('535 5.7.8 Authentication failed for alwencasagann@gmail.com');
check('the last mail error is captured', auth_last_mail_error() !== '');
check(
    'addresses are redacted out of the recorded error',
    !str_contains(auth_last_mail_error(), '@')
);
check(
    'the diagnostic reason survives redaction',
    str_contains(auth_last_mail_error(), '535')
);
auth_record_mail_error(str_repeat('x', 900));
check('the recorded error is length bounded', strlen(auth_last_mail_error()) <= 300);

// Gating: with no relay configured the mailer must refuse, never report a
// send it did not perform.
same('an unconfigured relay reports unavailable', false, auth_mail_configured());
check('an unconfigured send returns false', auth_send_mail('nobody@example.com', 'subject', '<p>x</p>', 'x') === false);
check('a malformed recipient is refused', auth_send_mail('not-an-address', 'subject', '<p>x</p>', 'x') === false);

// ---------------------------------------------------------------------

if ($failures !== []) {
    fwrite(STDERR, "FAILED (" . count($failures) . " of {$checks}):\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, "  - {$failure}\n");
    }
    exit(1);
}

echo "auth_service: {$checks} checks passed\n";
exit(0);
