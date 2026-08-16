<?php
/**
 * --------------------------------------------------------------------------------
 * EDUPORTAL LMS - ASYNCHRONOUS MAIL ENGINE
 * --------------------------------------------------------------------------------
 * @author    Alwen T. Casagan
 * @role      Web Developer / Systems Architect
 * @copyright 2026 Alwen T. Casagan. All rights reserved.
 * 
 * PROPRIETARY AND CONFIDENTIAL:
 * Unauthorized copying, modification, or distribution is prohibited.
 * --------------------------------------------------------------------------------
 */
/**
 * EduPortal Queue Manager
 * Handles background jobs and concurrent task processing.
 */

require_once __DIR__ . '/../config/database.php';

class QueueManager {
    public static function push($type, $payload) {
        $conn = getDBConnection();
        $payload_json = json_encode($payload);
        
        $stmt = $conn->prepare("INSERT INTO jobs (type, payload, status) VALUES (?, ?, 'pending')");
        $stmt->execute([$type, $payload_json]);
        $job_id = $conn->getPDO()->lastInsertId();

        return $job_id;
    }

    public static function getStatus($job_id) {
        $conn = getDBConnection();
        $stmt = $conn->prepare("SELECT status, error_message FROM jobs WHERE id = ?");
        $stmt->execute([$job_id]);
        $result = $stmt->get_result()->fetch_assoc();

        return $result;
    }

    public static function processNext() {
        $conn = getDBConnection();
        
        // Find next pending job
        $stmt = $conn->prepare("SELECT id, type, payload FROM jobs WHERE status = 'pending' ORDER BY created_at ASC LIMIT 1");
        $stmt->execute();
        $job = $stmt->get_result()->fetch_assoc();

        if (!$job) {
            return null;
        }

        // Set status to processing
        $stmt = $conn->prepare("UPDATE jobs SET status = 'processing' WHERE id = ?");
        $stmt->execute([$job['id']]);

        $payload = json_decode($job['payload'], true);
        $result = false;
        $error = "";

        try {
            if ($job['type'] === 'email') {
                require_once __DIR__ . '/SMTPMailer.php';
                $result = SMTPMailer::send(
                    $payload['to'],
                    $payload['subject'],
                    $payload['message'],
                    $payload['from_name'],
                    $payload['from_email'],
                    $payload['smtp_user'],
                    $payload['smtp_pass']
                );
                if (!$result) {
                    $error = "SMTP delivery failed to {$payload['to']}. Check SMTP credentials and outbound port access.";
                }
            }
        } catch (Exception $e) {
            $error = "Exception: " . $e->getMessage();
        }

        // Finalize job status
        $status = $result ? 'completed' : 'failed';
        $stmt = $conn->prepare("UPDATE jobs SET status = ?, error_message = ? WHERE id = ?");
        $stmt->execute([$status, $error, $job['id']]);

        return ['id' => $job['id'], 'status' => $status];
    }
}
?>
