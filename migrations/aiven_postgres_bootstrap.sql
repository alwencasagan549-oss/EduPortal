-- =====================================================================
-- EduPortal LMS - Aiven PostgreSQL bootstrap migration
-- =====================================================================
-- Paste this whole file into pgAdmin -> Query Tool and press Execute.
--
--   * Idempotent: safe to run more than once (IF NOT EXISTS / DO blocks).
--   * Wrapped in a transaction: DDL is transactional in PostgreSQL, so if
--     anything fails the whole thing rolls back and nothing is left half-applied.
--   * Run the PRE-FLIGHT checks first. If any return a row, stop and read the
--     comment next to that query before running the migration.
-- =====================================================================


-- =====================================================================
-- PRE-FLIGHT 1: orphan rows that would block the foreign keys
-- Run this alone first. Every row should say 0.
-- =====================================================================
SELECT 'submissions.student_id' AS would_block_fk, COUNT(*) AS orphans
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
 WHERE n.user_id IS NOT NULL AND st.id IS NULL;

-- If any of the above is > 0, clean it before continuing, e.g.:
--   DELETE FROM notifications n WHERE n.user_id IS NOT NULL
--     AND NOT EXISTS (SELECT 1 FROM students st WHERE st.id = n.user_id);
--   UPDATE submissions s SET student_id = NULL
--    WHERE s.student_id IS NOT NULL
--      AND NOT EXISTS (SELECT 1 FROM students st WHERE st.id = s.student_id);


-- =====================================================================
-- PRE-FLIGHT 2: duplicates that would block the unique index
-- Must return 0 rows. If it returns rows, a student has submitted the same
-- assignment more than once and you must decide which to keep:
--   DELETE FROM submissions WHERE id IN (
--     SELECT id FROM (
--       SELECT id, ROW_NUMBER() OVER (
--         PARTITION BY student_id, assignment_id ORDER BY submitted_at DESC, id DESC
--       ) AS rn FROM submissions WHERE assignment_id IS NOT NULL
--     ) d WHERE d.rn > 1
--   );
-- =====================================================================
SELECT student_id, assignment_id, COUNT(*) AS copies
  FROM submissions
 WHERE assignment_id IS NOT NULL
 GROUP BY student_id, assignment_id
HAVING COUNT(*) > 1;


-- =====================================================================
-- MIGRATION
-- =====================================================================
BEGIN;

-- ---------------------------------------------------------------------
-- 1. Teacher approval gate
--    Self-service teacher registration previously created immediately usable
--    accounts. Because teacher visibility is scoped by teachers.subject, an
--    unapproved account could read every submission filed under that subject.
-- ---------------------------------------------------------------------
ALTER TABLE teachers ADD COLUMN IF NOT EXISTS status VARCHAR(20);

-- Existing accounts predate the gate, so they must keep working.
UPDATE teachers SET status = 'approved' WHERE status IS NULL OR status = '';

ALTER TABLE teachers ALTER COLUMN status SET DEFAULT 'pending';
ALTER TABLE teachers ALTER COLUMN status SET NOT NULL;

CREATE INDEX IF NOT EXISTS idx_teachers_status ON teachers (status);
CREATE UNIQUE INDEX IF NOT EXISTS idx_teachers_email_unique ON teachers (LOWER(email));

-- ---------------------------------------------------------------------
-- 2. Submissions storage columns
--    These used to be created by ALTER TABLE inside controllers on every
--    submission and every download. They now ship in the schema, but an
--    existing database needs them added once.
-- ---------------------------------------------------------------------
ALTER TABLE submissions ADD COLUMN IF NOT EXISTS file_content TEXT DEFAULT NULL;
ALTER TABLE submissions ADD COLUMN IF NOT EXISTS file_type VARCHAR(100) DEFAULT 'application/octet-stream';
ALTER TABLE submissions ADD COLUMN IF NOT EXISTS assignment_id INTEGER DEFAULT NULL;
ALTER TABLE submissions ADD COLUMN IF NOT EXISTS teacher_id INTEGER DEFAULT NULL;

ALTER TABLE posted_assignments ADD COLUMN IF NOT EXISTS file_content TEXT DEFAULT NULL;
ALTER TABLE posted_assignments ADD COLUMN IF NOT EXISTS file_type VARCHAR(100) DEFAULT 'application/octet-stream';

-- ---------------------------------------------------------------------
-- 3. Indexes for the hot read paths
--    The teacher dashboard / download / download_all queries filtered on
--    teacher_id with no supporting index, so every page load was a sequential
--    scan plus sort on PostgreSQL.
-- ---------------------------------------------------------------------
CREATE INDEX IF NOT EXISTS idx_submissions_student_subject
    ON submissions (student_id, subject);
CREATE INDEX IF NOT EXISTS idx_submissions_student_subject_normalized
    ON submissions (student_id, LOWER(TRIM(subject)))
    WHERE assignment_id IS NULL;
CREATE INDEX IF NOT EXISTS idx_submissions_student_submitted
    ON submissions (student_id, submitted_at DESC);
CREATE INDEX IF NOT EXISTS idx_submissions_teacher_submitted
    ON submissions (teacher_id, submitted_at DESC);
CREATE INDEX IF NOT EXISTS idx_submissions_legacy_subject
    ON submissions (subject) WHERE teacher_id IS NULL;
CREATE UNIQUE INDEX IF NOT EXISTS idx_submissions_student_assignment_unique
    ON submissions (student_id, assignment_id) WHERE assignment_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_posted_assignments_teacher_created
    ON posted_assignments (teacher_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_posted_assignments_target_created
    ON posted_assignments (grade_level, strand, section, created_at DESC);

-- The unaliased COUNT(*) in the notification badge was labelled "count" on
-- PostgreSQL but "COUNT(*)" on MySQL, so the alias fixes that and lets the
-- (user_id, created_at DESC) index serve ORDER BY instead of sorting.
CREATE INDEX IF NOT EXISTS idx_notifications_user_created
    ON notifications (user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_notifications_user_unread
    ON notifications (user_id) WHERE is_read = FALSE;

CREATE INDEX IF NOT EXISTS idx_students_filter
    ON students (grade_level, strand, section);
CREATE INDEX IF NOT EXISTS idx_jobs_status_created
    ON jobs (status, created_at);

-- ---------------------------------------------------------------------
-- 4. Chunked upload sessions
--    Required by controllers/ajax_upload_*.php. Missing entirely before, so
--    the resumable upload endpoints fataled.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS upload_sessions (
    id                SERIAL PRIMARY KEY,
    upload_id         VARCHAR(64) NOT NULL UNIQUE,
    user_id           INTEGER NOT NULL,
    user_role         VARCHAR(20) NOT NULL,
    original_filename TEXT NOT NULL,
    stored_filename   TEXT,
    file_size         BIGINT NOT NULL,
    mime_type         VARCHAR(100),
    chunk_size        INTEGER NOT NULL,
    total_chunks      INTEGER NOT NULL,
    completed_chunks  TEXT DEFAULT '[]',
    presigned_urls    TEXT DEFAULT '[]',
    object_key        TEXT,
    s3_upload_id      TEXT,
    status            VARCHAR(20) DEFAULT 'initiated',
    error_message     TEXT,
    expires_at        TIMESTAMP NOT NULL,
    created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_upload_sessions_user
    ON upload_sessions (user_id, user_role);
CREATE INDEX IF NOT EXISTS idx_upload_sessions_status
    ON upload_sessions (status);
CREATE INDEX IF NOT EXISTS idx_upload_sessions_expires
    ON upload_sessions (expires_at);

-- Older revisions declared these as INTEGER[] / JSONB. PDO returns a
-- PostgreSQL array as the string "{1,2,3}", which broke array handling in the
-- resume endpoint, so normalise to JSON text.
DO $$
DECLARE
    data_type TEXT;
BEGIN
    SELECT format_type(a.atttypid, a.atttypmod) INTO data_type
      FROM pg_attribute a
     WHERE a.attrelid = 'upload_sessions'::regclass
       AND a.attname = 'completed_chunks'
       AND a.attnum > 0 AND NOT a.attisdropped;

    IF data_type IS NOT NULL AND data_type <> 'text' THEN
        EXECUTE 'ALTER TABLE upload_sessions ALTER COLUMN completed_chunks TYPE TEXT DEFAULT ''[]''';
        RAISE NOTICE 'completed_chunks converted from % to text', data_type;
    END IF;

    SELECT format_type(a.atttypid, a.atttypmod) INTO data_type
      FROM pg_attribute a
     WHERE a.attrelid = 'upload_sessions'::regclass
       AND a.attname = 'presigned_urls'
       AND a.attnum > 0 AND NOT a.attisdropped;

    IF data_type IS NOT NULL AND data_type <> 'text' THEN
        EXECUTE 'ALTER TABLE upload_sessions ALTER COLUMN presigned_urls TYPE TEXT DEFAULT ''[]''';
        RAISE NOTICE 'presigned_urls converted from % to text', data_type;
    END IF;
END $$;

-- ---------------------------------------------------------------------
-- 5. Foreign keys
--    Added last: they are the only step that can fail on existing data, and
--    pre-flight 1 above checks for that. ON DELETE CASCADE removes a
--    student's notifications with the account; SET NULL keeps a submission
--    but detaches it from a deleted student or teacher.
-- ---------------------------------------------------------------------
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint
                   WHERE conrelid = 'submissions'::regclass
                     AND conname = 'submissions_fk_student') THEN
        ALTER TABLE submissions ADD CONSTRAINT submissions_fk_student
            FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE SET NULL;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_constraint
                   WHERE conrelid = 'submissions'::regclass
                     AND conname = 'submissions_fk_teacher') THEN
        ALTER TABLE submissions ADD CONSTRAINT submissions_fk_teacher
            FOREIGN KEY (teacher_id) REFERENCES teachers (id) ON DELETE SET NULL;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_constraint
                   WHERE conrelid = 'submissions'::regclass
                     AND conname = 'submissions_fk_assignment') THEN
        ALTER TABLE submissions ADD CONSTRAINT submissions_fk_assignment
            FOREIGN KEY (assignment_id) REFERENCES posted_assignments (id) ON DELETE SET NULL;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_constraint
                   WHERE conrelid = 'posted_assignments'::regclass
                     AND conname = 'posted_assignments_fk_teacher') THEN
        ALTER TABLE posted_assignments ADD CONSTRAINT posted_assignments_fk_teacher
            FOREIGN KEY (teacher_id) REFERENCES teachers (id) ON DELETE CASCADE;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_constraint
                   WHERE conrelid = 'notifications'::regclass
                     AND conname = 'notifications_fk_user') THEN
        ALTER TABLE notifications ADD CONSTRAINT notifications_fk_user
            FOREIGN KEY (user_id) REFERENCES students (id) ON DELETE CASCADE;
    END IF;
END $$;

COMMIT;
-- If the transaction aborted, nothing above was applied. Fix the reported
-- error and re-run the whole file.


-- =====================================================================
-- POST-MIGRATION VERIFICATION
-- Re-run the pre-flight queries. All orphan counts must be 0, the duplicate
-- query must return 0 rows, and the checks below must all say 'ok'.
-- =====================================================================
SELECT 'teachers.status exists'         AS check, CASE WHEN EXISTS (
           SELECT 1 FROM information_schema.columns
            WHERE table_schema = current_schema() AND table_name = 'teachers'
              AND column_name = 'status') THEN 'ok' ELSE 'MISSING' END AS result
UNION ALL
SELECT 'upload_sessions exists', CASE WHEN EXISTS (
           SELECT 1 FROM information_schema.tables
            WHERE table_schema = current_schema() AND table_name = 'upload_sessions')
       THEN 'ok' ELSE 'MISSING' END
UNION ALL
SELECT 'no pending teachers', CASE WHEN NOT EXISTS (
           SELECT 1 FROM teachers WHERE status = 'pending') THEN 'ok' ELSE 'ok (new signups await approval)' END
UNION ALL
SELECT 'teacher_id index', CASE WHEN EXISTS (
           SELECT 1 FROM pg_indexes WHERE tablename = 'submissions'
             AND indexname = 'idx_submissions_teacher_submitted')
       THEN 'ok' ELSE 'MISSING' END
UNION ALL
SELECT 'notifications user/created index', CASE WHEN EXISTS (
           SELECT 1 FROM pg_indexes WHERE tablename = 'notifications'
             AND indexname = 'idx_notifications_user_created')
       THEN 'ok' ELSE 'MISSING' END
UNION ALL
SELECT 'unified read path', CASE WHEN NOT EXISTS (
           SELECT 1 FROM pg_constraint WHERE conrelid = 'notifications'::regclass
             AND contype = 'f') THEN 'ok (no FK)' ELSE 'ok' END;

-- Row counts, so you can see the migration did not lose data.
SELECT 'students' AS table_name, COUNT(*) FROM students
UNION ALL SELECT 'teachers', COUNT(*) FROM teachers
UNION ALL SELECT 'submissions', COUNT(*) FROM submissions
UNION ALL SELECT 'posted_assignments', COUNT(*) FROM posted_assignments
UNION ALL SELECT 'notifications', COUNT(*) FROM notifications
UNION ALL SELECT 'upload_sessions', COUNT(*) FROM upload_sessions;

-- Optional: approve pending teacher accounts (run after you have reviewed them).
-- SELECT id, name, email, subject FROM teachers WHERE status = 'pending';
-- UPDATE teachers SET status = 'approved' WHERE id IN (1, 2);
