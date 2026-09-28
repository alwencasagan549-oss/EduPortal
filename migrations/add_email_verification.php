<?php
/**
 * Migration: Email ownership proof on student and teacher accounts
 *
 * The portal had no concept of a verified email address. students.email is
 * nullable and was never checked, so "this student owns that mailbox" was not
 * a fact the system could record.
 *
 * This column records that proof. It is deliberately NOT a gate: password
 * reset works without it, because the reset link itself is the proof of
 * control. Making it a precondition would lock out every account that signed
 * up before this migration. Completing a reset sets it.
 *
 * Kept as informational so an operator can tell a self-registered account
 * from one whose mailbox has been confirmed.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../libs/AuthService.php';

$conn = getDBConnection();

$failed = false;

foreach (['students', 'teachers'] as $table) {
    if (auth_column_exists($conn, $table, 'email_verified_at')) {
        echo "  = {$table}.email_verified_at already present\n";
        continue;
    }

    try {
        $conn->exec("ALTER TABLE {$table} ADD COLUMN email_verified_at TIMESTAMP NULL");
        echo "  + {$table}.email_verified_at added\n";
    } catch (Throwable $exception) {
        $failed = true;
        echo "  ! {$table}.email_verified_at could not be created: " . $exception->getMessage() . "\n";
    }
}

echo $failed ? "Migration failed.\n" : "Email verification migration complete.\n";
exit($failed ? 1 : 0);
