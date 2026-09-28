<?php
/**
 * Outbound transactional mail.
 *
 * Password reset is the only recovery path the portal has, so it needs real
 * delivery. The application previously had no mailer at all: the SMTP settings
 * in the jobs payload and the SMTPMailer class described in the planning
 * documents do not exist in this tree.
 *
 * Delivery uses PHPMailer (Composer) rather than a hand-rolled SMTP client.
 * Hand-rolling STARTTLS, AUTH, MIME and multipart encoding is the kind of
 * thing that works in testing and fails against real relays.
 *
 * Configuration (environment):
 *   MAIL_ENABLED      '1' to send. Anything else keeps mail fully disabled.
 *   SMTP_HOST         e.g. smtp.postmarkapp.com
 *   SMTP_PORT         usually 587, or 465 for implicit TLS
 *   SMTP_USER         username or API key
 *   SMTP_PASS         password or API secret
 *   SMTP_ENCRYPTION   'tls', 'ssl' or 'none'
 *   SMTP_FROM         verified sender address
 *   SMTP_FROM_NAME    display name
 *
 *   MAIL_TRANSPORT    'smtp' (default) or 'api'
 *   BREVO_API_KEY     Brevo v3 API key, required by the 'api' transport
 *
 * Two transports exist because of a hosting constraint, not a preference.
 * Render's free web services block outbound traffic to SMTP ports 25, 465 and
 * 587 (changelog, 16 September 2025), so an SMTP relay simply cannot be
 * reached from a free instance -- the connection fails before any
 * authentication is attempted. Their REST API is reached over HTTPS on 443,
 * which is permitted, so the same provider and the same credentials are
 * reachable by switching transport. SMTP remains the default so nothing
 * changes for a host that allows it, and a paid Render instance can go back
 * to it by setting MAIL_TRANSPORT=smtp.
 *
 * When mail is disabled, auth_send_mail() returns false rather than silently
 * succeeding, and the reset flow reports the failure instead of leaking the
 * token into the response.
 */

// The mailer owns its dependency. forgot_password.php requires this file
// without touching WebAuthnService.php, so nothing else registered
// Composer's autoloader on that path -- which made the class_exists() probe
// in auth_mail_configured() return false and report "not configured" even
// when SMTP was set up correctly.
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

function auth_mail_enabled(): bool
{
    return strtolower((string) (getenv('MAIL_ENABLED') ?: '0')) === '1';
}

/**
 * 'api' or 'smtp'. Anything unrecognised falls back to smtp rather than
 * silently doing nothing.
 */
function auth_mail_transport(): string
{
    $transport = strtolower(trim((string) (getenv('MAIL_TRANSPORT') ?: 'smtp')));
    return $transport === 'api' ? 'api' : 'smtp';
}

function auth_mail_configured(): bool
{
    if (!auth_mail_enabled()) {
        return false;
    }

    $from = (string) (getenv('SMTP_FROM') ?: '');
    if ($from === '') {
        return false;
    }

    if (auth_mail_transport() === 'api') {
        // No Composer dependency and no relay host: just the API key.
        return (string) (getenv('BREVO_API_KEY') ?: '') !== '';
    }

    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        return false;
    }

    return (string) (getenv('SMTP_HOST') ?: '') !== '';
}

/**
 * The relay's response to the most recent send attempt on this request.
 *
 * auth_send_mail() keeps its boolean return so callers stay simple, but a
 * boolean alone leaves an operator guessing. Exposing the reason lets the
 * reset flow record it in the audit log, where it is queryable, instead of
 * only reaching error_log() on a host whose log UI may be hard to reach.
 */
function auth_last_mail_error(): string
{
    return $GLOBALS['auth_mail_error'] ?? '';
}

function auth_record_mail_error(string $message): void
{
    // A relay response can echo the recipient or a login address back at us.
    // Redact anything address-shaped before it can reach the audit table.
    $redacted = preg_replace('/\S+@\S+/', '[email]', $message) ?? '';
    $GLOBALS['auth_mail_error'] = substr(trim($redacted), 0, 300);
}

/**
 * Normalises an address that was pasted out of a provider UI.
 *
 * Bracketed forms such as "<someone@example.com>" are common copy artefacts,
 * and filter_var() rejects them outright. Left unhandled, a single teacher
 * account stored that way makes password reset fail for that user with only a
 * generic "could not send" on screen. The underlying data should still be
 * corrected, but the mail path should not be defeated by whitespace.
 */
function auth_normalise_email_address(string $address): string
{
    return strtolower(trim(trim(trim($address)), '<>'));
}

/**
 * @return bool true when the message was handed to the relay.
 */
function auth_send_mail(string $to, string $subject, string $html, string $text): bool
{
    if (!auth_mail_configured()) {
        // Name the variables that actually apply to the selected transport.
        // Telling an operator to check SMTP_HOST while they are running the
        // API transport sends them to the wrong place entirely.
        $hint = auth_mail_transport() === 'api'
            ? 'mail is not configured: check MAIL_ENABLED, SMTP_FROM and BREVO_API_KEY'
            : 'mail is not configured: check MAIL_ENABLED, SMTP_HOST, SMTP_FROM and the Composer install';

        auth_record_mail_error($hint);
        error_log('EduPortal mail skipped: ' . auth_last_mail_error());
        return false;
    }

    $to = auth_normalise_email_address($to);

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        auth_record_mail_error('invalid recipient address: ' . $to);
        error_log('EduPortal mail skipped: ' . auth_last_mail_error());
        return false;
    }

    if (auth_mail_transport() === 'api') {
        return auth_send_mail_via_api($to, $subject, $html, $text);
    }

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);

        $mail->isSMTP();
        $mail->Host = (string) getenv('SMTP_HOST');
        $mail->Port = (int) (getenv('SMTP_PORT') ?: 587);

        // PHPMailer defaults to a 300 second timeout. If the host's outbound
        // path to the relay stalls -- a blocked port 587 is the usual cause on
        // a PaaS -- the request hangs for five minutes with the form's loader
        // spinning. A password reset must fail fast and say so instead.
        $mail->Timeout = (int) (getenv('SMTP_TIMEOUT') ?: 10);
        $mail->SMTPKeepAlive = false;

        // PHPMailer 6.12 folded the ENCRYPTION_* constants onto the PHPMailer
        // class itself; there is no PHPMailer\SMTPSecure class in that release.
        // Written the old way this fatals inside the catch below, which
        // surfaces as a generic "could not send" rather than an obvious error.
        $encryption = strtolower((string) (getenv('SMTP_ENCRYPTION') ?: 'tls'));
        if ($encryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($encryption === 'tls') {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }

        $user = (string) (getenv('SMTP_USER') ?: '');
        if ($user !== '') {
            $mail->SMTPAuth = true;
            $mail->Username = $user;
            $mail->Password = (string) (getenv('SMTP_PASS') ?: '');
        }

        $mail->CharSet = 'UTF-8';
        $mail->setFrom(
            (string) getenv('SMTP_FROM'),
            (string) (getenv('SMTP_FROM_NAME') ?: 'EduPortal LMS')
        );
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = $text;

        return $mail->send();
    } catch (Throwable $exception) {
        // PHPMailer's exception message is a bare "Error". ErrorInfo carries
        // the relay's actual response -- 535 for bad credentials, 550/553 for a
        // rejected sender, and so on -- which is the difference between an
        // operator fixing this in one step and guessing.
        $detail = isset($mail) && is_object($mail) && $mail->ErrorInfo !== null
            ? (string) $mail->ErrorInfo
            : $exception->getMessage();

        auth_record_mail_error($detail);
        error_log('EduPortal mail send failed: ' . auth_last_mail_error());
        return false;
    }
}

/**
 * Sends through Brevo's REST API instead of its SMTP relay.
 *
 * Exists because a free Render instance cannot reach SMTP ports at all, and
 * the same failure looks identical to a credential problem from the outside.
 * The API is plain HTTPS on 443, so it is reachable, and it reports errors
 * in a JSON body rather than an SMTP reply line.
 */
function auth_send_mail_via_api(string $to, string $subject, string $html, string $text): bool
{
    $apiKey = trim((string) (getenv('BREVO_API_KEY') ?: ''));
    if ($apiKey === '') {
        auth_record_mail_error('MAIL_TRANSPORT=api but BREVO_API_KEY is not set');
        error_log('EduPortal mail skipped: ' . auth_last_mail_error());
        return false;
    }

    if (!function_exists('curl_init')) {
        auth_record_mail_error('the curl extension is not available for the API transport');
        error_log('EduPortal mail skipped: ' . auth_last_mail_error());
        return false;
    }

    $payload = [
        'sender' => [
            'name' => (string) (getenv('SMTP_FROM_NAME') ?: 'EduPortal LMS'),
            'email' => (string) getenv('SMTP_FROM'),
        ],
        'to' => [['email' => $to]],
        'subject' => $subject,
        'htmlContent' => $html,
        'textContent' => $text,
    ];

    $handle = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'accept: application/json',
            'api-key: ' . $apiKey,
        ],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
    ]);

    $body = curl_exec($handle);
    $transportError = curl_error($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);

    if ($body === false) {
        auth_record_mail_error('Brevo API unreachable: ' . $transportError);
        error_log('EduPortal mail send failed: ' . auth_last_mail_error());
        return false;
    }

    if ($status < 200 || $status >= 300) {
        // The body names the field that failed, which is far more useful than
        // a bare status code: "unauthorized" and "sender not verified" look
        // identical from the outside.
        auth_record_mail_error('Brevo API returned ' . $status . ': ' . (string) $body);
        error_log('EduPortal mail send failed: ' . auth_last_mail_error());
        return false;
    }

    return true;
}
