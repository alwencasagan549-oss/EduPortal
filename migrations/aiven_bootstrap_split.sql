-- =====================================================================
-- EduPortal LMS - Aiven PostgreSQL bootstrap, split for PG Studio
-- =====================================================================
-- Aiven PG Studio runs at most 10 queries per "Run", so this is split into
-- 8 parts of at most 8 statements.
--
-- HOW TO USE
--   1. Click in the editor, Ctrl+A, Delete, paste this whole file.
--   2. Highlight ONLY the block between two ==== PART markers.
--   3. Press Run. Wait for "success".
--   4. Move to the next part.
--
-- Each part is independent and idempotent (IF NOT EXISTS / guarded UPDATE), so
-- it is safe to re-run a part that failed. There is no BEGIN/COMMIT across
-- parts, because PG Studio commits per Run.
--
-- Parts 1-7 are the migration. Part 8 verifies. If any part other than 1
-- fails, re-run that part after fixing the cause.
-- =====================================================================


-- =====================================================================
-- ==== PART 1 of 8 - PRE-FLIGHT (read-only, changes nothing) ====
-- Run this FIRST. If either result is not clean, stop and read the comment
-- under that result before running Part 2.
-- =====================================================================

-- 1a. Confirms this is the EduPortal database and not another one.
DO $migration$
DECLARE
    required TEXT[] := ARRAY['students', 'teachers', 'submissions',
                             'posted_assignments', 'notifications', 'jobs'];
    missing  TEXT;
BEGIN
    SELECT string_agg(t, ', ' ORDER BY t) INTO missing
      FROM unnest(required) AS t
     WHERE to_regclass('public.' || t) IS NULL;

    IF missing IS NOT NULL THEN
        RAISE EXCEPTION 'WRONG DATABASE - missing table(s): %.', missing;
    END IF;
END
$migration$;

-- 1b. Orphan rows that would block the foreign keys in Part 7.
--     EVERY COUNT MUST BE 0.
--     If not, run the commented cleanup, then re-run this part.
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

-- 1c. Duplicate submissions that would block the unique index in Part 4.
--     MUST RETURN 0 ROWS.
--     If it returns rows, a student submitted the same assignment twice.
--     Keep only the newest of each, then re-run this part:
--
--   DELETE FROM submissions WHERE id IN (
--       SELECT id FROM (
--           SELECT id, ROW_NUMBER() OVER (
--               PARTITION BY student_id, assignment_id
--               ORDER BY submitted_at DESC, id DESC
--           ) AS rn
--             FROM submissions
--            WHERE assignment_id IS NOT NULL
--       ) d WHERE d.rn > 1
--   );
SELECT student_id, assignment_id, COUNT(*) AS copies
  FROM submissions
 WHERE assignment_id IS NOT NULL
 GROUP BY student_id, assignment_id
HAVING COUNT(*) > 1;


-- =====================================================================
-- ==== PART 2 of 8 - TEACHER APPROVAL GATE ====
-- Self-service teacher signup used to hand out working accounts. Teacher
-- visibility is scoped by teachers.subject, so claiming an unused subject
-- exposed every submission filed under it.
-- Existing teachers are set to 'approved', so nobody is locked out. Only
-- NEW signups will land on 'pending'.
-- =====================================================================
ALTER TABLE teachers ADD COLUMN IF NOT EXISTS status VARCHAR(20);
UPDATE teachers SET status = 'approved' WHERE status IS NULL OR status = '';
ALTER TABLE teachers ALTER COLUMN status SET DEFAULT 'pending';
ALTER TABLE teachers ALTER COLUMN status SET NOT NULL;
CREATE INDEX IF NOT EXISTS idx_teachers_status ON teachers (status);
CREATE UNIQUE INDEX IF NOT EXISTS idx_teachers_email_unique ON teachers (LOWER(email));


-- =====================================================================
-- ==== PART 3 of 8 - STORAGE COLUMNS ====
-- These used to be created by ALTER TABLE inside the controllers on every
-- submission and every download: an information_schema round-trip plus a
-- table lock on the hottest paths in the app.
-- =====================================================================
ALTER TABLE submissions ADD COLUMN IF NOT EXISTS file_content TEXT DEFAULT NULL;
ALTER TABLE submissions ADD COLUMN IF NOT EXISTS file_type VARCHAR(100) DEFAULT 'application/octet-stream';
ALTER TABLE submissions ADD COLUMN IF NOT EXISTS assignment_id INTEGER DEFAULT NULL;
ALTER TABLE submissions ADD COLUMN IF NOT EXISTS teacher_id INTEGER DEFAULT NULL;
ALTER TABLE posted_assignments ADD COLUMN IF NOT EXISTS file_content TEXT DEFAULT NULL;
ALTER TABLE posted_assignments ADD COLUMN IF NOT EXISTS file_type VARCHAR(100) DEFAULT 'application/octet-stream';


-- =====================================================================
-- ==== PART 4 of 8 - SUBMISSION AND ASSIGNMENT INDEXES ====
-- The teacher dashboard, download.php and download_all.php all filter on
-- teacher_id. With no index that was a sequential scan plus a sort on every
-- page load against the production database.
-- =====================================================================
CREATE INDEX IF NOT EXISTS idx_submissions_student_subject
    ON submissions (student_id, subject);
CREATE INDEX IF NOT EXISTS idx_submissions_student_subject_normalized
    ON submissions (student_id, LOWER(TRIM(subject))) WHERE assignment_id IS NULL;
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


-- =====================================================================
-- ==== PART 5 of 8 - NOTIFICATION, STUDENT AND JOB INDEXES ====
-- The notification badge polls every 30s per open tab. (user_id, created_at
-- DESC) lets that query be served by an index instead of sorting every time;
-- the old boolean-only index had almost no selectivity.
-- =====================================================================
CREATE INDEX IF NOT EXISTS idx_notifications_user_created
    ON notifications (user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_notifications_user_unread
    ON notifications (user_id) WHERE is_read = FALSE;
CREATE INDEX IF NOT EXISTS idx_students_filter
    ON students (grade_level, strand, section);
CREATE INDEX IF NOT EXISTS idx_jobs_status_created
    ON jobs (status, created_at);


-- =====================================================================
-- ==== PART 6 of 8 - CHUNKED UPLOAD SESSIONS TABLE ====
-- Queried by every ajax_upload_* endpoint and absent from the schema
-- entirely, so the resumable upload feature was hard-failing in production.
-- =====================================================================
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


-- =====================================================================
-- ==== PART 7 of 8 - COLUMN NORMALISATION AND FOREIGN KEYS ====
-- Older revisions declared completed_chunks as INTEGER[] and presigned_urls
-- as JSONB. PDO returns a PostgreSQL array as the string "{1,2,3}", which made
-- in_array() throw a TypeError on every resume attempt, so both become text.
-- Then add the foreign keys the MariaDB schema always had and PostgreSQL
-- was missing. Part 1b already proved no rows will block these.
-- =====================================================================
DO $migration$
DECLARE
    col_type TEXT;
BEGIN
    SELECT format_type(a.atttypid, a.atttypmod) INTO col_type
      FROM pg_attribute a
     WHERE a.attrelid = 'upload_sessions'::regclass
       AND a.attname = 'completed_chunks' AND a.attnum > 0 AND NOT a.attisdropped;
    IF col_type IS NOT NULL AND col_type <> 'text' THEN
        EXECUTE 'ALTER TABLE upload_sessions ALTER COLUMN completed_chunks TYPE TEXT DEFAULT ''[]''';
    END IF;

    SELECT format_type(a.atttypid, a.atttypmod) INTO col_type
      FROM pg_attribute a
     WHERE a.attrelid = 'upload_sessions'::regclass
       AND a.attname = 'presigned_urls' AND a.attnum > 0 AND NOT a.attisdropped;
    IF col_type IS NOT NULL AND col_type <> 'text' THEN
        EXECUTE 'ALTER TABLE upload_sessions ALTER COLUMN presigned_urls TYPE TEXT DEFAULT ''[]''';
    END IF;
END
$migration$;

DO $migration$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint
                   WHERE conrelid = 'submissions'::regclass AND conname = 'submissions_fk_student') THEN
        ALTER TABLE submissions ADD CONSTRAINT submissions_fk_student
            FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE SET NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint
                   WHERE conrelid = 'submissions'::regclass AND conname = 'submissions_fk_teacher') THEN
        ALTER TABLE submissions ADD CONSTRAINT submissions_fk_teacher
            FOREIGN KEY (teacher_id) REFERENCES teachers (id) ON DELETE SET NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint
                   WHERE conrelid = 'submissions'::regclass AND conname = 'submissions_fk_assignment') THEN
        ALTER TABLE submissions ADD CONSTRAINT submissions_fk_assignment
            FOREIGN KEY (assignment_id) REFERENCES posted_assignments (id) ON DELETE SET NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint
                   WHERE conrelid = 'posted_assignments'::regclass AND conname = 'posted_assignments_fk_teacher') THEN
        ALTER TABLE posted_assignments ADD CONSTRAINT posted_assignments_fk_teacher
            FOREIGN KEY (teacher_id) REFERENCES teachers (id) ON DELETE CASCADE;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint
                   WHERE conrelid = 'notifications'::regclass AND conname = 'notifications_fk_user') THEN
        ALTER TABLE notifications ADD CONSTRAINT notifications_fk_user
            FOREIGN KEY (user_id) REFERENCES students (id) ON DELETE CASCADE;
    END IF;
END
$migration$;


-- =====================================================================
-- ==== PART 8 of 8 - VERIFICATION ====
-- EVERY RESULT MUST SAY 'ok'. If one says FAILED, re-run that part.
-- =====================================================================
SELECT 'teachers.status column' AS check, CASE WHEN EXISTS (
           SELECT 1 FROM information_schema.columns WHERE table_schema = 'public'
            AND table_name = 'teachers' AND column_name = 'status')
       THEN 'ok' ELSE 'FAILED' END AS result
UNION ALL SELECT 'upload_sessions table', CASE WHEN to_regclass('public.upload_sessions') IS NOT NULL
       THEN 'ok' ELSE 'FAILED' END
UNION ALL SELECT 'submissions.teacher_id index', CASE WHEN EXISTS (
           SELECT 1 FROM pg_indexes WHERE tablename = 'submissions'
             AND indexname = 'idx_submissions_teacher_submitted') THEN 'ok' ELSE 'FAILED' END
UNION ALL SELECT 'unique student+assignment', CASE WHEN EXISTS (
           SELECT 1 FROM pg_indexes WHERE tablename = 'submissions'
             AND indexname = 'idx_submissions_student_assignment_unique') THEN 'ok' ELSE 'FAILED' END
UNION ALL SELECT 'notifications user/created index', CASE WHEN EXISTS (
           SELECT 1 FROM pg_indexes WHERE tablename = 'notifications'
             AND indexname = 'idx_notifications_user_created') THEN 'ok' ELSE 'FAILED' END
UNION ALL SELECT '3 foreign keys on submissions', CASE WHEN (
           SELECT COUNT(*) FROM pg_constraint
            WHERE conrelid = 'submissions'::regclass AND contype = 'f') = 3
       THEN 'ok' ELSE 'FAILED' END
UNION ALL SELECT 'no teacher has a blank status', CASE WHEN NOT EXISTS (
           SELECT 1 FROM teachers WHERE status IS NULL OR status = '')
       THEN 'ok' ELSE 'FAILED' END
UNION ALL SELECT 'pending signups', CASE WHEN NOT EXISTS (
           SELECT 1 FROM teachers WHERE status = 'pending')
       THEN 'ok' ELSE 'ok - new signups await approval' END;

-- Row counts, to confirm nothing was lost.
SELECT 'students' AS table_name, COUNT(*) FROM students
UNION ALL SELECT 'teachers', COUNT(*) FROM teachers
UNION ALL SELECT 'submissions', COUNT(*) FROM submissions
UNION ALL SELECT 'posted_assignments', COUNT(*) FROM posted_assignments
UNION ALL SELECT 'notifications', COUNT(*) FROM notifications
UNION ALL SELECT 'upload_sessions', COUNT(*) FROM upload_sessions;

-- Optional, run separately once you have reviewed them:
-- SELECT id, name, email, subject, status FROM teachers ORDER BY id;
-- UPDATE teachers SET status = 'approved' WHERE id IN (...);
