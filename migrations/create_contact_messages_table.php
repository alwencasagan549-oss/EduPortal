<?php
/**
 * Database Migration: Contact Messages Table
 *
 * Backs the public contact form on contact.php. The form is the School's
 * only working contact channel for a visitor who has no account, so it stores
 * rather than mails: MAIL_ENABLED is off by default in this deployment, and a
 * contact form that silently discards its submissions is worse than no form.
 * A row here is a message the school can read, answer and mark as dealt with.
 *
 * The message is retained only until the matter is resolved; see section 6 of
 * privacy.php. ip_address and user_agent are kept alongside it because an
 * unauthenticated public form is the most obvious spam target in the install,
 * and the pair is what makes an abuse pattern recognisable after the fact.
 */

require_once __DIR__ . '/../config/database.php';

$conn = getDBConnection();

$sql = "CREATE TABLE IF NOT EXISTS contact_messages (
    id SERIAL PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(255) NOT NULL,
    topic VARCHAR(60) NOT NULL,
    message TEXT NOT NULL,
    ip_address VARCHAR(45),
    user_agent VARCHAR(255),
    status VARCHAR(20) DEFAULT 'new',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";

if ($conn->exec($sql) !== false) {
    echo "Table 'contact_messages' created or already exists.\n";
} else {
    echo "Error creating table: " . $conn->getPDO()->errorInfo()[2] . "\n";
}

// The inbox view is "everything still unanswered, oldest first", which is
// (status, created_at) and not created_at alone -- an index that cannot serve
// the default filter is an index nobody uses.
$indexes = [
    'idx_contact_messages_status_created' => 'ON contact_messages (status, created_at)',
    'idx_contact_messages_email' => 'ON contact_messages (email)',
];

foreach ($indexes as $name => $clause) {
    try {
        $conn->exec("CREATE INDEX IF NOT EXISTS {$name} {$clause}");
        echo "Index {$name} ensured.\n";
    } catch (Throwable $exception) {
        // MySQL has no CREATE INDEX IF NOT EXISTS, so a second run lands here
        // on an index that already exists. That is a no-op, not a failure.
        echo "Index {$name} left as-is: " . $exception->getMessage() . "\n";
    }
}
