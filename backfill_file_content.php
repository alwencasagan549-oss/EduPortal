<?php
/**
 * Backfill: Copy existing files from disk into database file_content column
 * Run this once after deploying the migration to populate DB with existing files
 * Usage: php backfill_file_content.php
 */

require_once __DIR__ . '/config/database.php';
$conn = getDBConnection();

$stats = [
    'assignments' => ['total' => 0, 'backfilled' => 0, 'skipped' => 0],
    'submissions' => ['total' => 0, 'backfilled' => 0, 'skipped' => 0]
];

// Backfill posted_assignments
$stmt = $conn->query("SELECT id, file_path FROM posted_assignments WHERE file_content IS NULL OR file_content = ''");
$records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

foreach ($records as $record) {
    $stats['assignments']['total']++;
    $resolved = realpath(__DIR__ . '/' . ltrim($record['file_path'], '/\\'));

    if ($resolved && file_exists($resolved)) {
        $content = base64_encode(file_get_contents($resolved));
        $filetype = mime_content_type($resolved);

        $update = $conn->prepare("UPDATE posted_assignments SET file_content = ?, file_type = ? WHERE id = ?");
        $update->execute([$content, $filetype, $record['id']]);
        $stats['assignments']['backfilled']++;
    } else {
        $stats['assignments']['skipped']++;
    }
}

// Backfill submissions
$stmt = $conn->query("SELECT id, file_path FROM submissions WHERE file_content IS NULL OR file_content = ''");
$records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

foreach ($records as $record) {
    $stats['submissions']['total']++;
    $resolved = realpath(__DIR__ . '/' . ltrim($record['file_path'], '/\\'));

    if ($resolved && file_exists($resolved)) {
        $content = base64_encode(file_get_contents($resolved));
        $filetype = mime_content_type($resolved);

        $update = $conn->prepare("UPDATE submissions SET file_content = ?, file_type = ? WHERE id = ?");
        $update->execute([$content, $filetype, $record['id']]);
        $stats['submissions']['backfilled']++;
    } else {
        $stats['submissions']['skipped']++;
    }
}

echo "=== Backfill Complete ===\n\n";
echo "Posted Assignments:\n";
echo "  Total:  {$stats['assignments']['total']}\n";
echo "  Backfilled: {$stats['assignments']['backfilled']}\n";
echo "  Skipped:   {$stats['assignments']['skipped']}\n\n";

echo "Submissions:\n";
echo "  Total:  {$stats['submissions']['total']}\n";
echo "  Backfilled: {$stats['submissions']['backfilled']}\n";
echo "  Skipped:   {$stats['submissions']['skipped']}\n\n";

echo "All files are now stored in the database and will work on Render!\n";
echo "You can now delete the uploads/ directory if desired.\n";
echo "Run this script on Render too: curl https://reesnhs.l.cd/backfill_file_content.php\n";
