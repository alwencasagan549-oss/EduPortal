<?php
/**
 * EduPortal SMTP Mailer (PHPMailer-based)
 * Provides reliable SMTP delivery with detailed error logging.
 */

require_once __DIR__ . '/../vendor/autoload.php';

class SMTPMailer {
    public static function send($to, $subject, $body, $from_name, $from_email, $smtp_user, $smtp_pass) {
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);

            // Server settings
            $mail->isSMTP();
            $mail->Host = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = $smtp_user;
            $mail->Password = $smtp_pass;
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port = (int)(getenv('SMTP_PORT') ?: 465);
            $mail->Timeout = 15;

            // Recipients
            $mail->setFrom($from_email ?: $smtp_user, $from_name ?: 'EduPortal');
            $mail->addAddress($to);

            // Content
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->isHTML(false);

            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("EduPortal Mail Error: " . $e->getMessage());
            return false;
        }
    }
}
