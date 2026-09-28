<?php
/**
 * Teacher account lifecycle helpers.
 *
 * Teachers are created by self-service registration, and by default they can
 * sign in immediately.
 *
 * An approval gate exists because `teachers.subject` is the de-facto
 * authorization key for submission visibility: an account that is approved for
 * a subject sees every submission filed under it across all sections. On a
 * deployment with a real administrator that gate is worth having. On one
 * without anybody to run it, it just means nobody can ever sign in, since
 * nothing would ever move a row out of 'pending'.
 *
 * So it is a setting rather than a hardcoded rule. TEACHER_APPROVAL_REQUIRED
 * defaults to off, which matches a school that trusts its own staff, and can be
 * switched on by setting it to 1 once an administrator exists.
 */

require_once __DIR__ . '/assignment_management.php';

/**
 * Whether a newly registered teacher must be approved before signing in.
 *
 * Off by default: a deployment with no administrator to run the queue would
 * otherwise lock every teacher out permanently.
 */
function teacher_approval_required(): bool
{
    return strtolower(trim((string) (getenv('TEACHER_APPROVAL_REQUIRED') ?: '0'))) === '1';
}

/**
 * The status a new registration starts in.
 */
function teacher_registration_status(): string
{
    return teacher_approval_required() ? 'pending' : 'approved';
}

function teacher_account_driver_name($conn): string
{
    try {
        if (method_exists($conn, 'getDriverName')) {
            return strtolower((string) $conn->getDriverName());
        }
    } catch (Throwable $exception) {
        return 'pgsql';
    }

    return 'pgsql';
}

function teacher_account_schema_expression($conn): string
{
    return teacher_account_driver_name($conn) === 'mysql' ? 'DATABASE()' : 'current_schema()';
}

/**
 * Cached per request: schema introspection is a round-trip we do not want to
 * repeat, but the answer cannot change mid-request.
 */
function teacher_account_column_exists($conn, string $column): bool
{
    static $known = null;

    if ($known === null) {
        $known = [];
        try {
            $schemaExpression = teacher_account_schema_expression($conn);
            $stmt = $conn->prepare(
                "SELECT column_name
                 FROM information_schema.columns
                 WHERE table_schema = {$schemaExpression}
                   AND table_name = 'teachers'"
            );
            $stmt->execute();
            while (($row = $stmt->fetch_assoc()) !== false) {
                $known[strtolower((string) $row['column_name'])] = true;
            }
        } catch (Throwable $exception) {
            error_log('EduPortal teacher schema check failed: ' . $exception->getMessage());
            $known = [];
        }
    }

    return isset($known[strtolower($column)]);
}

/**
 * Returns 'approved' | 'pending' | 'rejected' | 'unknown'.
 *
 * Databases that have not run the approval migration yet report 'approved' so
 * an un-migrated deployment is not locked out.
 */
function teacher_account_status($conn, $teacherId): string
{
    $teacherId = assignment_id($teacherId);
    if ($teacherId === null) {
        return 'unknown';
    }

    if (!teacher_account_column_exists($conn, 'status')) {
        return 'approved';
    }

    try {
        $stmt = $conn->prepare('SELECT status FROM teachers WHERE id = ?');
        $stmt->execute([$teacherId]);
        $status = $stmt->fetchColumn();
    } catch (Throwable $exception) {
        error_log('EduPortal teacher status lookup failed: ' . $exception->getMessage());
        return 'unknown';
    }

    if ($status === false || $status === null || trim((string) $status) === '') {
        return 'approved';
    }

    return strtolower(trim((string) $status));
}

function teacher_account_can_login($conn, $teacherId): bool
{
    // When approval is not in use, every account can sign in regardless of
    // whatever the status column holds. Otherwise a legacy 'pending' row left
    // over from before the setting changed would stay locked out forever with
    // no way to clear it.
    if (!teacher_approval_required()) {
        return true;
    }

    return teacher_account_status($conn, $teacherId) === 'approved';
}

function teacher_account_status_message(string $status): string
{
    switch ($status) {
        case 'pending':
            return 'Your teaching account is awaiting administrator approval. '
                . 'You will be able to sign in once an administrator activates it.';
        case 'rejected':
            return 'Your teaching account request was not approved. '
                . 'Please contact the portal administrator for more information.';
        default:
            return 'Your teaching account could not be verified. '
                . 'Please contact the portal administrator.';
    }
}
