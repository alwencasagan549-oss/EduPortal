<?php
/**
 * EduPortal Professional SMTP Mailer
 * Standalone SMTP implementation for Gmail & InfinityFree.
 */

class SMTPMailer {
    public static function send($to, $subject, $body, $from_name, $from_email, $smtp_user, $smtp_pass) {
        $host = "ssl://smtp.gmail.com";
        $port = 465;
        $timeout = 30;

        $socket = fsockopen($host, $port, $errno, $errstr, $timeout);
        if (!$socket) return false;

        $response = fgets($socket, 515);
        if (substr($response, 0, 3) != "220") { fclose($socket); return false; }

        // HELO
        fwrite($socket, "EHLO eduportal\r\n");
        while ($line = fgets($socket, 515)) { if (substr($line, 3, 1) == " ") break; }

        // AUTH LOGIN
        fwrite($socket, "AUTH LOGIN\r\n");
        $response = fgets($socket, 515);
        if (substr($response, 0, 3) != "334") { fclose($socket); return false; }

        fwrite($socket, base64_encode($smtp_user) . "\r\n");
        $response = fgets($socket, 515);
        if (substr($response, 0, 3) != "334") { fclose($socket); return false; }

        fwrite($socket, base64_encode($smtp_pass) . "\r\n");
        $response = fgets($socket, 515);
        if (substr($response, 0, 3) != "235") { fclose($socket); return false; }

        // MAIL FROM
        fwrite($socket, "MAIL FROM: <$smtp_user>\r\n");
        $response = fgets($socket, 515);
        if (substr($response, 0, 3) != "250") { fclose($socket); return false; }

        // RCPT TO
        fwrite($socket, "RCPT TO: <$to>\r\n");
        $response = fgets($socket, 515);
        if (substr($response, 0, 3) != "250") { fclose($socket); return false; }

        // DATA
        fwrite($socket, "DATA\r\n");
        $response = fgets($socket, 515);
        if (substr($response, 0, 3) != "354") { fclose($socket); return false; }

        // Headers & Body
        $headers = "From: $from_name <$from_email>\r\n";
        $headers .= "To: $to\r\n";
        $headers .= "Subject: $subject\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $headers .= "X-Mailer: EduPortal SMTP\r\n";
        
        fwrite($socket, $headers . "\r\n" . $body . "\r\n.\r\n");
        $response = fgets($socket, 515);
        if (substr($response, 0, 3) != "250") { fclose($socket); return false; }

        // QUIT
        fwrite($socket, "QUIT\r\n");
        fclose($socket);
        return true;
    }
}
?>
