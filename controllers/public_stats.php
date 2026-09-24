<?php

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60, stale-while-revalidate=300');

try {
    $conn = getDBConnection();
    $result = $conn->query(
        'SELECT (SELECT COUNT(*) FROM submissions) AS submissions,
                (SELECT COUNT(*) FROM teachers) AS teachers,
                (SELECT COUNT(*) FROM students) AS students'
    );
    $stats = $result ? $result->fetch_assoc() : [];
    echo json_encode([
        'submissions' => (int) ($stats['submissions'] ?? 0),
        'students' => (int) ($stats['students'] ?? 0),
        'teachers' => (int) ($stats['teachers'] ?? 0)
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    http_response_code(503);
    echo json_encode(['error' => 'Statistics are temporarily unavailable.']);
}
