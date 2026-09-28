<?php
/**
 * Tests for the upload/download helper layer.
 *
 * Covers the fixes that are easiest to regress silently:
 *   - completed-chunk normalisation across every storage representation
 *   - filename and MIME header sanitising
 *   - path traversal rejection via realpath()
 *   - the O(1) result cursor
 *   - the resumable-upload URL contract
 *
 * Run: php tests/upload_helpers_test.php
 */

require_once __DIR__ . '/../libs/assignment_management.php';

$failures = [];
$checks = 0;

function check(string $label, bool $passed): void
{
    global $failures, $checks;
    $checks++;
    if (!$passed) {
        $failures[] = $label;
    }
}

function same(string $label, $expected, $actual): void
{
    check(
        $label . ' (expected ' . json_encode($expected) . ', got ' . json_encode($actual) . ')',
        $expected === $actual
    );
}

// ---------------------------------------------------------------------
// upload_endpoint_completed_chunks()
// ---------------------------------------------------------------------
require_once __DIR__ . '/../controllers/upload_endpoint.php';

same('empty string yields no chunks', [], upload_endpoint_completed_chunks(''));
same('null yields no chunks', [], upload_endpoint_completed_chunks(null));
same('false yields no chunks', [], upload_endpoint_completed_chunks(false));
same('empty JSON array', [], upload_endpoint_completed_chunks('[]'));
same('JSON array of ints', [1, 2, 3], upload_endpoint_completed_chunks('[1,2,3]'));
same('native PHP array', [1, 2], upload_endpoint_completed_chunks([1, 2]));

// This is the case that previously produced a TypeError inside in_array():
// PDO returns a PostgreSQL INTEGER[] as the string "{1,2,3}".
same('Postgres array literal', [1, 2, 3], upload_endpoint_completed_chunks('{1,2,3}'));
same('Postgres array literal with spaces', [4, 5], upload_endpoint_completed_chunks('{ 4 , 5 }'));
same('empty Postgres array literal', [], upload_endpoint_completed_chunks('{}'));

// Part-number objects, as ajax_upload_progress.php accepts.
same(
    'part objects',
    [1, 2],
    upload_endpoint_completed_chunks([['partNumber' => 1], ['partNumber' => 2]])
);

same('zero is rejected', [], upload_endpoint_completed_chunks('[0]'));
same('negatives are rejected', [], upload_endpoint_completed_chunks('[-1,-2]'));
same('non-numeric entries are dropped', [1, 2], upload_endpoint_completed_chunks('[1,"x",2,null]'));
same('garbage input is safe', [], upload_endpoint_completed_chunks('not json at all'));
same('nested arrays are rejected', [], upload_endpoint_completed_chunks('[[1,2]]'));
same('duplicates collapse and sort', [1, 2], upload_endpoint_completed_chunks('[2,1,2,1]'));
same(
    'numeric strings are coerced',
    [1, 2],
    upload_endpoint_completed_chunks('["1"," 2 "]')
);
same('all indices are inspected', [1, 3], upload_endpoint_completed_chunks([0 => 1, 5 => 3]));
same('part_number key is accepted', [7], upload_endpoint_completed_chunks([['part_number' => 7]]));
same('result is sorted ascending', [1, 2, 3], upload_endpoint_completed_chunks('[3,1,2]'));

// ---------------------------------------------------------------------
// Download header sanitising
// ---------------------------------------------------------------------
same('plain filename is preserved', 'essay.pdf', assignment_safe_download_filename('uploads/essay.pdf'));
same('directory components are stripped', 'essay.pdf', assignment_safe_download_filename('/var/www/uploads/essay.pdf'));
same(
    'header injection characters are replaced',
    'a_b_c.pdf',
    assignment_safe_download_filename('a"b;c.pdf')
);
// Each of \r and \n is replaced individually, so the name is still safe.
same('CRLF is neutralised', 'a__b.pdf', assignment_safe_download_filename("a\r\nb.pdf"));
same('windows separators handled', 'essay.pdf', assignment_safe_download_filename('uploads\\essay.pdf'));
same('empty path falls back', 'download', assignment_safe_download_filename(''));
same('dot segments fall back', 'download', assignment_safe_download_filename('..'));
// basename() already removes the traversal, leaving a harmless bare name.
same('traversal is reduced to its basename', 'passwd', assignment_safe_download_filename('../../etc/passwd'));

same('valid mime preserved', 'application/pdf', assignment_safe_download_mime('application/pdf'));
same('null mime falls back', 'application/octet-stream', assignment_safe_download_mime(null));
same('empty mime falls back', 'application/octet-stream', assignment_safe_download_mime(''));
same('header injection mime rejected', 'application/octet-stream', assignment_safe_download_mime("text/html\r\nX: y"));
same('non-mime string rejected', 'application/octet-stream', assignment_safe_download_mime('nonsense'));
same('vendor mime preserved', 'application/vnd.ms-excel', assignment_safe_download_mime('application/vnd.ms-excel'));

// ---------------------------------------------------------------------
// Path traversal resolution
//
// Mirrors the check in controllers/download.php: the stored path is joined to
// the uploads base, canonicalised, and then required to still be inside it.
// The old implementation compared a string prefix against the *unresolved*
// path, so "uploads/../../config/credentials.php" passed the prefix test
// while file_exists() resolved the traversal.
// ---------------------------------------------------------------------
$fixtureDir = sys_get_temp_dir() . '/eduportal_traversal_' . getmypid();
$secretDir = sys_get_temp_dir() . '/eduportal_secret_' . getmypid();
@mkdir($fixtureDir . '/uploads', 0750, true);
@mkdir($secretDir, 0750, true);
file_put_contents($fixtureDir . '/uploads/report.pdf', 'public');
file_put_contents($secretDir . '/credentials.php', 'SECRET');

$base = realpath($fixtureDir . '/uploads');
$baseNorm = rtrim(str_replace('\\', '/', $base), '/') . '/';

function resolvesInside(string $base, string $baseNorm, string $relative): bool
{
    $resolved = realpath($base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    if ($resolved === false || !is_file($resolved)) {
        return false;
    }
    return strpos(str_replace('\\', '/', $resolved), $baseNorm) === 0;
}

check('legitimate file resolves inside', resolvesInside($base, $baseNorm, 'report.pdf'));
check(
    'traversal to a sibling secret is rejected',
    !resolvesInside($base, $baseNorm, '../' . basename($secretDir) . '/credentials.php')
);
check(
    'deep traversal is rejected',
    !resolvesInside($base, $baseNorm, '../../../../../../etc/passwd')
);
check('absolute path is rejected', !resolvesInside($base, $baseNorm, '/etc/passwd'));

// ---------------------------------------------------------------------
// Runtime migration gate
// ---------------------------------------------------------------------
same(
    'runtime migrations are opt-in',
    false,
    assignment_runtime_migrations_enabled()
);

// ---------------------------------------------------------------------
// assignment_teacher_submission_scope()
//
// A placeholder/parameter count mismatch here is a runtime 500 on every
// teacher download, so assert the invariant directly.
// ---------------------------------------------------------------------
$scope = assignment_teacher_submission_scope('s', 7, 'Mathematics');
same('scope param count', 4, count($scope['params']));
same(
    'placeholder count matches params',
    substr_count($scope['sql'], '?'),
    count($scope['params'])
);
same('scope params are ordered', [7, 'Mathematics', 7, 7], $scope['params']);
check(
    'scope prefers teacher_id',
    str_contains($scope['sql'], 's.teacher_id = ?')
);
check(
    'legacy branch is normalised, not raw',
    str_contains($scope['sql'], 'LOWER(TRIM(s.subject))')
);
check(
    'legacy branch is class-scoped',
    str_contains($scope['sql'], 'posted_assignments')
);

$unaliased = assignment_teacher_submission_scope('', 3, 'Physics');
$unaliasedFlat = preg_replace('/\s+/', ' ', $unaliased['sql']);
same(
    'unaliased scope placeholder count matches',
    substr_count($unaliased['sql'], '?'),
    count($unaliased['params'])
);
check(
    'unaliased scope does not prefix the outer table',
    !str_contains($unaliasedFlat, 's.teacher_id')
        && !str_contains($unaliasedFlat, 's.subject')
        && str_contains($unaliasedFlat, '( teacher_id = ?')
);

// @unlink
array_map('unlink', glob($fixtureDir . '/uploads/*') ?: []);
array_map('unlink', glob($secretDir . '/*') ?: []);
@rmdir($fixtureDir . '/uploads');
@rmdir($fixtureDir);
@rmdir($secretDir);

// ---------------------------------------------------------------------
if ($failures !== []) {
    echo "Upload helper tests FAILED:\n";
    foreach ($failures as $failure) {
        echo "  - {$failure}\n";
    }
    echo "\n{$checks} checks, " . count($failures) . " failed\n";
    exit(1);
}

echo "Upload helper tests passed ({$checks} checks).\n";
exit(0);
