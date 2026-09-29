<?php
/**
 * AJAX: first-visit human check.
 *
 * Verifies a reCAPTCHA v3 token minted for the gate action and, on success,
 * marks the browser as verified. Two verdicts, and the difference matters:
 *
 *   - a real low score in 'enforce' mode is a refusal, and the visitor is held
 *     at the gate;
 *   - in 'observe' mode the score is recorded and the visitor is waved
 *     through, which is the setting to reach for when a shared campus address
 *     starts producing false positives.
 *
 * Either way the browser is marked verified, because both answers mean "this
 * browser is a real one, let it work". Recording a refusal without marking it
 * would make every subsequent page load pay for another round trip to Google
 * to reach the same verdict.
 *
 * The receipt is set here rather than client-side on purpose. A cookie written
 * by script proves nothing -- anyone can set one -- so the mark of a verified
 * browser is an HttpOnly cookie signed with a server secret, alongside the
 * session flag.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../libs/AuthService.php';
require_once __DIR__ . '/../libs/RecaptchaService.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['ok' => false, 'reason' => 'method', 'error' => 'Method not allowed']);
    exit();
}

if (!recaptcha_enabled() || human_gate_mode() === 'off') {
    // Nothing to verify. Answering "fine" is the only correct move: the client
    // is holding a gate open, and a configuration change that turned reCAPTCHA
    // off must not leave returning visitors stuck behind a screen whose
    // endpoint can never satisfy them.
    echo json_encode(['ok' => true, 'reason' => 'disabled']);
    exit();
}

if (!validate_csrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'reason' => 'csrf_failure', 'error' => 'Invalid security token.']);
    exit();
}

$conn = recaptcha_audit_connection();

$action = HUMAN_GATE_ACTION;
$result = recaptcha_verify((string) ($_POST['g-recaptcha-response'] ?? ''), $action);

$observe = human_gate_mode() === 'observe';

if ($result['ok'] || $observe) {
    if (!$result['ok']) {
        // Recorded, not forgiven by accident. The audit row is the only
        // artefact distinguishing "observed and let in" from "passed", so it
        // is written explicitly rather than left to the ok branch.
        recaptcha_audit($result, ['conn' => $conn]);
    }

    human_gate_mark_verified();

    echo json_encode([
        'ok' => true,
        'reason' => $observe && !$result['ok'] ? 'observed' : $result['reason'],
    ]);
    exit();
}

recaptcha_audit($result, ['conn' => $conn]);

http_response_code(403);
echo json_encode([
    'ok' => false,
    'reason' => $result['reason'],
    'error' => $result['error'],
]);
