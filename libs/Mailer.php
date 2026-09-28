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

function auth_mail_configured(): bool
{
    if (!auth_mail_enabled()) {
        return false;
    }

    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        return false;
    }

    return (string) (getenv('SMTP_HOST') ?: '') !== ''
        && (string) (getenv('SMTP_FROM') ?: '') !== '';
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
        auth_record_mail_error('mail is not configured: check MAIL_ENABLED, SMTP_HOST and the Composer install');
        error_log('EduPortal mail skipped: ' . auth_last_mail_error());
        return false;
    }

    $to = auth_normalise_email_address($to);

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        auth_record_mail_error('invalid recipient address: ' . $to);
        error_log('EduPortal mail skipped: ' . auth_last_mail_error());
        return false;
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
