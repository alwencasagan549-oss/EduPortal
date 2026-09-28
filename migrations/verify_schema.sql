-- =====================================================================
-- EduPortal LMS - schema verification
-- =====================================================================
-- Run this AFTER migrations/aiven_bootstrap.sql. It changes nothing: it is
-- read-only, so it is always safe to re-run.
--
-- Split into 2 Runs because PG Studio caps a single Run at 10 queries.
-- =====================================================================


-- =====================================================================
-- ==== CHECK 1 of 2 - STRUCTURE ====
-- 3 queries. Every table must say ok, every column row must say ok, and the
-- row counts confirm your data is intact.
-- =====================================================================

-- 1a. Does every table the application needs exist?
WITH expected(name) AS (
    VALUES ('students'), ('teachers'), ('submissions'), ('posted_assignments'),
           ('notifications'), ('jobs'), ('upload_sessions'),
           ('submission_deletion_audit'), ('admin'),
           ('auth_events'), ('auth_rate_limits'), ('auth_tokens'),
           ('passkeys'), ('webauthn_challenges')
)
SELECT e.name AS table_name,
       CASE WHEN to_regclass('public.' || e.name) IS NULL THEN 'MISSING'
            ELSE 'ok'
       END AS status,
       CASE WHEN to_regclass('public.' || e.name) IS NULL THEN NULL
            ELSE (SELECT COUNT(*) FROM information_schema.columns c
                   WHERE c.table_schema = 'public' AND c.table_name = e.name)
       END AS column_count
  FROM expected e
 ORDER BY status, e.name;

-- 1b. Does every table have every column the application selects or writes?
--     Anything not 'ok' here is a column the code will fail on.
WITH expected(tbl, col) AS (
    VALUES
      ('students','id'), ('students','lrn'), ('students','name'), ('students','email'),
      ('students','grade_level'), ('students','section'), ('students','strand'),
      ('students','password'), ('students','created_at'), ('students','email_verified_at'),
      ('students','passkey_user_handle'),
      ('teachers','id'), ('teachers','name'), ('teachers','email'), ('teachers','subject'),
      ('teachers','password'), ('teachers','status'), ('teachers','created_at'),
      ('teachers','email_verified_at'), ('teachers','passkey_user_handle'),
      ('submissions','id'), ('submissions','student_id'), ('submissions','assignment_id'),
      ('submissions','teacher_id'), ('submissions','student_name'), ('submissions','subject'),
      ('submissions','file_path'), ('submissions','file_content'), ('submissions','file_type'),
      ('submissions','marks'), ('submissions','remarks'), ('submissions','submission_date'),
      ('submissions','submitted_at'),
      ('posted_assignments','id'), ('posted_assignments','teacher_id'),
      ('posted_assignments','teacher_name'), ('posted_assignments','subject'),
      ('posted_assignments','title'), ('posted_assignments','description'),
      ('posted_assignments','file_path'), ('posted_assignments','file_content'),
      ('posted_assignments','file_type'), ('posted_assignments','grade_level'),
      ('posted_assignments','section'), ('posted_assignments','strand'),
      ('posted_assignments','created_at'),
      ('notifications','id'), ('notifications','user_id'), ('notifications','type'),
      ('notifications','title'), ('notifications','message'), ('notifications','data'),
      ('notifications','is_read'), ('notifications','created_at'),
      ('jobs','id'), ('jobs','type'), ('jobs','payload'), ('jobs','status'),
      ('jobs','error_message'), ('jobs','created_at'), ('jobs','updated_at'),
      ('upload_sessions','id'), ('upload_sessions','upload_id'), ('upload_sessions','user_id'),
      ('upload_sessions','user_role'), ('upload_sessions','original_filename'),
      ('upload_sessions','stored_filename'), ('upload_sessions','file_size'),
      ('upload_sessions','mime_type'), ('upload_sessions','chunk_size'),
      ('upload_sessions','total_chunks'), ('upload_sessions','completed_chunks'),
      ('upload_sessions','presigned_urls'), ('upload_sessions','object_key'),
      ('upload_sessions','s3_upload_id'), ('upload_sessions','status'),
      ('upload_sessions','error_message'), ('upload_sessions','expires_at'),
      ('upload_sessions','created_at'), ('upload_sessions','updated_at'),
      ('submission_deletion_audit','id'), ('submission_deletion_audit','event'),
      ('submission_deletion_audit','reason'), ('submission_deletion_audit','submission_id'),
      ('submission_deletion_audit','student_id'), ('submission_deletion_audit','assignment_id'),
      ('submission_deletion_audit','subject'), ('submission_deletion_audit','file_path'),
      ('submission_deletion_audit','file_removed'),       ('submission_deletion_audit','actor_id'),
      ('submission_deletion_audit','created_at'),
      ('auth_events','event'), ('auth_events','outcome'), ('auth_events','user_role'),
      ('auth_events','user_id'), ('auth_events','identifier'), ('auth_events','ip_address'),
      ('auth_events','user_agent'), ('auth_events','detail'), ('auth_events','created_at'),
      ('auth_rate_limits','bucket_key'), ('auth_rate_limits','attempt_count'),
      ('auth_rate_limits','window_started_at'), ('auth_rate_limits','locked_until'),
      ('auth_rate_limits','updated_at'),
      ('auth_tokens','token_hash'), ('auth_tokens','purpose'), ('auth_tokens','user_role'),
      ('auth_tokens','user_id'), ('auth_tokens','expires_at'), ('auth_tokens','consumed_at'),
      ('auth_tokens','request_ip'), ('auth_tokens','created_at'),
      ('passkeys','id'), ('passkeys','user_role'), ('passkeys','user_id'),
      ('passkeys','user_handle'), ('passkeys','credential_id'), ('passkeys','credential_record'),
      ('passkeys','aaguid'), ('passkeys','transports'), ('passkeys','sign_count'),
      ('passkeys','backup_eligible'), ('passkeys','backup_status'), ('passkeys','label'),
      ('passkeys','created_at'), ('passkeys','last_used_at'), ('passkeys','revoked_at'),
      ('webauthn_challenges','id'), ('webauthn_challenges','challenge'),
      ('webauthn_challenges','purpose'), ('webauthn_challenges','user_role'),
      ('webauthn_challenges','user_id'), ('webauthn_challenges','request_ip'),
      ('webauthn_challenges','created_at'), ('webauthn_challenges','expires_at'),
      ('webauthn_challenges','consumed_at')
)
SELECT e.tbl AS table_name, e.col AS column_name,
       CASE WHEN c.column_name IS NULL THEN 'MISSING' ELSE 'ok' END AS status
  FROM expected e
  LEFT JOIN information_schema.columns c
         ON c.table_schema = 'public'
        AND c.table_name  = e.tbl
        AND c.column_name = e.col
 WHERE c.column_name IS NULL
 ORDER BY e.tbl, e.col;

-- 1c. Row counts. Compare these to what you expect to be in the database.
SELECT 'students' AS table_name, COUNT(*) FROM students
UNION ALL SELECT 'teachers', COUNT(*) FROM teachers
UNION ALL SELECT 'submissions', COUNT(*) FROM submissions
UNION ALL SELECT 'posted_assignments', COUNT(*) FROM posted_assignments
UNION ALL SELECT 'notifications', COUNT(*) FROM notifications
UNION ALL SELECT 'jobs', COUNT(*) FROM jobs
UNION ALL SELECT 'upload_sessions', COUNT(*) FROM upload_sessions
UNION ALL SELECT 'submission_deletion_audit', COUNT(*) FROM submission_deletion_audit
UNION ALL SELECT 'auth_events', COUNT(*) FROM auth_events
UNION ALL SELECT 'auth_rate_limits', COUNT(*) FROM auth_rate_limits
UNION ALL SELECT 'auth_tokens', COUNT(*) FROM auth_tokens
UNION ALL SELECT 'passkeys', COUNT(*) FROM passkeys
UNION ALL SELECT 'webauthn_challenges', COUNT(*) FROM webauthn_challenges;


-- =====================================================================
-- ==== CHECK 2 of 2 - INDEXES, CONSTRAINTS, INTEGRITY ====
-- 5 queries. Confirms the performance indexes landed, the foreign keys
-- exist, there are no broken references, and no teacher is stranded.
-- =====================================================================

-- 2a. Every index the application relies on, with its size. An index of
--     0 bytes on a table with rows means it was not built properly.
SELECT tablename, indexname, pg_size_pretty(pg_relation_size(indexname::regclass)) AS size
  FROM pg_indexes
 WHERE schemaname = 'public'
    AND tablename IN ('submissions','posted_assignments','notifications',
                      'students','teachers','jobs','upload_sessions',
                      'auth_events','auth_rate_limits','auth_tokens',
                      'passkeys','webauthn_challenges')
    AND (indexname ILIKE '%unique%' OR indexname LIKE 'idx_passkeys%'
         OR indexname LIKE 'idx_webauthn%' OR indexname LIKE 'idx_auth%')
 ORDER BY tablename, indexname;

-- 2b. Foreign keys. Expect 3 on submissions, 1 on posted_assignments,
--     1 on notifications, and 0 on students.
--     ORDER BY repeats the full expression rather than the alias: PostgreSQL
--     resolves ORDER BY names against input columns first, so `on_table::text`
--     failed with 'column on_table does not exist'.
SELECT conrelid::regclass AS on_table, conname,
       pg_get_constraintdef(oid) AS definition
  FROM pg_constraint
 WHERE connamespace = 'public'::regnamespace AND contype = 'f'
 ORDER BY conrelid::regclass::text, conname;

-- 2c. Data integrity. Every count must be 0.
SELECT 'submissions.student_id' AS check_name, COUNT(*) AS must_be_zero
  FROM submissions s LEFT JOIN students st ON st.id = s.student_id
 WHERE s.student_id IS NOT NULL AND st.id IS NULL
UNION ALL
SELECT 'submissions.teacher_id', COUNT(*)
  FROM submissions s LEFT JOIN teachers t ON t.id = s.teacher_id
 WHERE s.teacher_id IS NOT NULL AND t.id IS NULL
UNION ALL
SELECT 'submissions.assignment_id', COUNT(*)
  FROM submissions s LEFT JOIN posted_assignments pa ON pa.id = s.assignment_id
 WHERE s.assignment_id IS NOT NULL AND pa.id IS NULL
UNION ALL
SELECT 'posted_assignments.teacher_id', COUNT(*)
  FROM posted_assignments pa LEFT JOIN teachers t ON t.id = pa.teacher_id
 WHERE pa.teacher_id IS NOT NULL AND t.id IS NULL
UNION ALL
SELECT 'notifications.user_id', COUNT(*)
  FROM notifications n LEFT JOIN students st ON st.id = n.user_id
 WHERE n.user_id IS NOT NULL AND st.id IS NULL
UNION ALL
SELECT 'duplicate student+assignment', COUNT(*)
  FROM (SELECT 1 FROM submissions WHERE assignment_id IS NOT NULL
         GROUP BY student_id, assignment_id HAVING COUNT(*) > 1) d;

-- 2d. Teacher approval state. No teacher may be blank, or they cannot log in.
SELECT status, COUNT(*) AS teachers FROM teachers GROUP BY status ORDER BY status;

-- 2e. Proves the new index is actually being used. This is the exact query
--     the teacher dashboard runs; it should NOT say "Seq Scan".
EXPLAIN (COSTS OFF)
SELECT s.id FROM submissions s
 WHERE s.teacher_id = 1
    OR (s.teacher_id IS NULL AND LOWER(TRIM(s.subject)) = LOWER(TRIM('Mathematics')))
 ORDER BY s.submitted_at DESC
 LIMIT 200;
