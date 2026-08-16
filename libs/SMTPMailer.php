<?php
/**
 * EduPortal Email Mailer
 * Supports Brevo (HTTP API) for production.
 */

class SMTPMailer {
    public static function send($to, $subject, $body, $from_name, $from_email, $smtp_user, $smtp_pass) {
        $provider = getenv('EMAIL_PROVIDER') ?: 'smtp';

        if ($provider === 'brevo') {
            return self::sendViaBrevo($to, $subject, $body, $from_name, $from_email);
        }

        return self::sendViaSMTP($to, $subject, $body, $from_name, $from_email, $smtp_user, $smtp_pass);
    }

    private static function sendViaBrevo($to, $subject, $body, $from_name, $from_email) {
        $api_key = getenv('BREVO_API_KEY');
        $sender_email = getenv('SENDER_EMAIL') ?: $from_email;
        $sender_name = getenv('SENDER_NAME') ?: $from_name;

        if (!$api_key) {
            error_log('EduPortal Mail Error: BREVO_API_KEY not configured');
            return false;
        }

        $payload = json_encode([
            'sender' => ['email' => $sender_email, 'name' => $sender_name],
            'to' => [['email' => $to, 'name' => $to]],
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

        $error_msg = "Brevo API error (HTTP $http_code): " . substr($response, 0, 200);
        error_log('EduPortal Mail Error: ' . $error_msg);
        return false;
    }

    private static function sendViaSMTP($to, $subject, $body, $from_name, $from_email, $smtp_user, $smtp_pass) {
        $host = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
        $port = (int)(getenv('SMTP_PORT') ?: 465);
        $timeout = 30;

        $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if (!$socket) {
            error_log("EduPortal Mail Error: Cannot connect to SMTP $host:$port - $errstr ($errno)");
            return false;
        }

        $response = fgets($socket, 515);
        if (substr($response, 0, 3) != "220") { fclose($socket); return false; }

        fwrite($socket, "EHLO eduportal\r\n");
        while ($line = fgets($socket, 515)) { if (substr($line, 3, 1) == " ") break; }

        fwrite($socket, "AUTH LOGIN\r\n");
        $response = fgets($socket, 515);
        if (substr($response, 0, 3) != "334") { fclose($socket); return false; }

        fwrite($socket, base64_encode($smtp_user) . "\r\n");
        $response = fgets($socket, 515);
        if (substr($response, 0, 3) != "334") { fclose($socket); return false; }

        fwrite($socket, base64_encode($smtp_pass) . "\r\n");
        $response = fgets($socket, 515);
        if (substr($response, 0, 3) != "235") { fclose($socket); return false; }

        fwrite($socket, "MAIL FROM: <$smtp_user>\r\n");
        $response = fgets($socket, 515);
        if (substr($response, 0, 3) != "250") { fclose($socket); return false; }

        fwrite($socket, "RCPT TO: <$to>\r\n");
        $response = fgets($socket, 515);
        if (substr($response, 0, 3) != "250") { fclose($socket); return false; }

        fwrite($socket, "DATA\r\n");
        $response = fgets($socket, 515);
        if (substr($response, 0, 3) != "354") { fclose($socket); return false; }

        $headers = "From: $from_name <$from_email>\r\n";
        $headers .= "To: $to\r\n";
        $headers .= "Subject: $subject\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $headers .= "X-Mailer: EduPortal\r\n";

        fwrite($socket, $headers . "\r\n" . $body . "\r\n.\r\n");
        $response = fgets($socket, 515);
        if (substr($response, 0, 3) != "250") { fclose($socket); return false; }

        fwrite($socket, "QUIT\r\n");
        fclose($socket);
        return true;
    }
}
