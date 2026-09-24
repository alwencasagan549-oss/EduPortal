<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../libs/assignment_management.php';

$conn = getDBConnection();
$submissionSchemaReady = assignment_ensure_submission_schema($conn);
$auditSchemaReady = assignment_ensure_audit_schema($conn);

if (!$submissionSchemaReady || !$auditSchemaReady) {
    fwrite(STDERR, "Submission deduplication migration could not be completed.\n");
    exit(1);
}

echo "Submission deduplication migration is ready.\n";
