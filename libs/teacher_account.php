<?php
/**
 * Teacher account lifecycle helpers.
 *
 * Teacher accounts are created by self-service registration but must be
 * approved before they can sign in: `teachers.subject` is the de-facto
 * authorization key for submission visibility, so an unapproved account
 * would otherwise grant read access to every submission filed under that
 * subject across all sections.
 */

require_once __DIR__ . '/assignment_management.php';

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
