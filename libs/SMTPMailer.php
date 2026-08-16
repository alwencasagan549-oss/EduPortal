<?php
/**
 * EduPortal Email Mailer
 * Supports Brevo SMTP relay (port 587 STARTTLS) for production.
 */

require_once __DIR__ . '/../vendor/autoload.php';

class SMTPMailer {
    public static function send($to, $subject, $body, $from_name, $from_email, $smtp_user, $smtp_pass) {
        $provider = getenv('EMAIL_PROVIDER') ?: 'smtp';

        if ($provider === 'brevo') {
            return self::sendViaBrevoApi($to, $subject, $body, $from_name, $from_email);
        }

        return self::sendViaSMTP($to, $subject, $body, $from_name, $from_email, $smtp_user, $smtp_pass);
    }

    private static function sendViaBrevoApi($to, $subject, $body, $from_name, $from_email) {
        $api_key = getenv('BREVO_API_KEY');
        $sender_email = getenv('SENDER_EMAIL') ?: $from_email;
        $sender_name = getenv('SENDER_NAME') ?: $from_name;

        if (!$api_key) {
            error_log('EduPortal Mail Error: BREVO_API_KEY not configured');
            return false;
        }

        $payload = json_encode([
            'sender' => ['email' => $sender_email, 'name' => $sender_name],
            'to' => [['email' => $to]],
            'subject' => $subject,
            'content' => [['type' => 'text/plain', 'value' => $body]]
        ]);

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'api-key: ' . $api_key
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code === 201 || $http_code === 200) {
            return true;
        }

        error_log('EduPortal Mail Error: Brevo API HTTP ' . $http_code . ' - ' . substr($response, 0, 200));
        return false;
    }

    private static function sendViaSMTP($to, $subject, $body, $from_name, $from_email, $smtp_user, $smtp_pass) {
        $host = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
        $port = (int)(getenv('SMTP_PORT') ?: 465);
        $timeout = 15;

        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $host;
            $mail->Port = $port;
            $mail->SMTPAuth = true;
            $mail->Username = $smtp_user;
            $mail->Password = $smtp_pass;
            $mail->setFrom($from_email ?: $smtp_user, $from_name ?: 'EduPortal');
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->isHTML(false);
            $mail->Timeout = $timeout;

            // Auto-detect encryption based on port
            if ($port === 465) {
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($port === 587) {
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            }

            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log('EduPortal Mail Error: ' . $e->getMessage());
            return false;
        }
    }
}
