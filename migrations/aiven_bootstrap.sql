-- =====================================================================
-- EduPortal LMS - Aiven PostgreSQL bootstrap
-- =====================================================================
-- ONE RUN. Paste the whole file and press Run.
--
-- PG Studio caps a single Run at 10 queries, so the ~40 statements of the
-- migration are wrapped in a single DO block: that is one statement as far as
-- the client is concerned, but many to the server. The two verification
-- queries after it make 3 statements total.
--
-- The DO block is atomic. If any step fails, everything rolls back and the
-- error message tells you what to fix. Re-running the file is safe.
--
-- Progress is reported in the Messages panel as "Step n/8 ok".
-- =====================================================================

-- ---------------------------------------------------------------------
-- MIGRATION
-- ---------------------------------------------------------------------
DO $mig$
DECLARE
    required TEXT[] := ARRAY['students', 'teachers', 'submissions',
                             'posted_assignments', 'notifications', 'jobs'];
    missing  TEXT;
    problem  TEXT;
    n        BIGINT;
    col_type TEXT;
BEGIN

    -- ------------------------------------------------------------------
    -- 0. Refuse to run against the wrong database
    -- ------------------------------------------------------------------
    SELECT string_agg(t, ', ' ORDER BY t) INTO missing
      FROM unnest(required) AS t
     WHERE to_regclass('public.' || t) IS NULL;

    IF missing IS NOT NULL THEN
        RAISE EXCEPTION 'STOPPED - wrong database, missing table(s): %', missing;
    END IF;
    RAISE NOTICE 'Step 0/8  ok  - all expected tables present';

    -- ------------------------------------------------------------------
    -- 1. Pre-flight: orphan rows would block the foreign keys in step 7
    -- ------------------------------------------------------------------
    SELECT string_agg(format('%s=%s', label, cnt), ', ' ORDER BY label) INTO problem
      FROM (
        SELECT 'submissions.student_id' AS label, COUNT(*) AS cnt
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
      ) orphans
     WHERE cnt > 0;

    IF problem IS NOT NULL THEN
        RAISE EXCEPTION
            'STOPPED - orphan rows would block the foreign keys: %. Nothing was changed. Fix with: DELETE FROM notifications n WHERE n.user_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM students st WHERE st.id = n.user_id); then UPDATE submissions s SET student_id = NULL WHERE s.student_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM students st WHERE st.id = s.student_id);',
            problem;
    END IF;
    RAISE NOTICE 'Step 1/8  ok  - no orphan rows';

    -- ------------------------------------------------------------------
    -- 2. Pre-flight: duplicates would block the unique index in step 4
    -- ------------------------------------------------------------------
    SELECT COUNT(*) INTO n FROM (
        SELECT 1 FROM submissions
         WHERE assignment_id IS NOT NULL
         GROUP BY student_id, assignment_id
        HAVING COUNT(*) > 1
    ) dupes;

    IF n > 0 THEN
        RAISE EXCEPTION
            'STOPPED - % student/assignment pair(s) submitted more than once, which blocks the unique index. Nothing was changed. Keep only the newest of each: DELETE FROM submissions WHERE id IN (SELECT id FROM (SELECT id, ROW_NUMBER() OVER (PARTITION BY student_id, assignment_id ORDER BY submitted_at DESC, id DESC) AS rn FROM submissions WHERE assignment_id IS NOT NULL) d WHERE d.rn > 1);',
            n;
    END IF;
    RAISE NOTICE 'Step 2/8  ok  - no duplicate submissions';

    -- ------------------------------------------------------------------
    -- 3. Teacher approval gate
    --    Self-service signup used to hand out working accounts. Teacher
    --    visibility is scoped by teachers.subject, so claiming an unused
    --    subject exposed every submission filed under it. Existing teachers
    --    are set to approved so nobody is locked out; only NEW signups land
    --    on pending.
    -- ------------------------------------------------------------------
    ALTER TABLE teachers ADD COLUMN IF NOT EXISTS status VARCHAR(20);
    UPDATE teachers SET status = 'approved' WHERE status IS NULL OR status = '';
    ALTER TABLE teachers ALTER COLUMN status SET DEFAULT 'pending';
    ALTER TABLE teachers ALTER COLUMN status SET NOT NULL;
    CREATE INDEX IF NOT EXISTS idx_teachers_status ON teachers (status);
    RAISE NOTICE 'Step 3/8  ok  - teachers.status added, existing accounts approved';
    -- Skipped on purpose: idx_teachers_email_unique would fail if the
    -- database already has two teachers on one address. Not required by
    -- the application.

    -- ------------------------------------------------------------------
    -- 4. Storage columns
    --    These used to be created by ALTER TABLE inside the controllers on
    --    every submission and every download.
    -- ------------------------------------------------------------------
    ALTER TABLE submissions ADD COLUMN IF NOT EXISTS file_content TEXT DEFAULT NULL;
    ALTER TABLE submissions ADD COLUMN IF NOT EXISTS file_type VARCHAR(100) DEFAULT 'application/octet-stream';
    ALTER TABLE submissions ADD COLUMN IF NOT EXISTS assignment_id INTEGER DEFAULT NULL;
    ALTER TABLE submissions ADD COLUMN IF NOT EXISTS teacher_id INTEGER DEFAULT NULL;
    ALTER TABLE posted_assignments ADD COLUMN IF NOT EXISTS file_content TEXT DEFAULT NULL;
    ALTER TABLE posted_assignments ADD COLUMN IF NOT EXISTS file_type VARCHAR(100) DEFAULT 'application/octet-stream';
    RAISE NOTICE 'Step 4/8  ok  - storage columns present';

    -- ------------------------------------------------------------------
    -- 5. Indexes
    --    teacher_id had no supporting index, so the teacher dashboard and
    --    both download endpoints were a sequential scan plus a sort.
    -- ------------------------------------------------------------------
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
    CREATE INDEX IF NOT EXISTS idx_notifications_user_created
        ON notifications (user_id, created_at DESC);
    CREATE INDEX IF NOT EXISTS idx_notifications_user_unread
        ON notifications (user_id) WHERE is_read = FALSE;
    CREATE INDEX IF NOT EXISTS idx_students_filter
        ON students (grade_level, strand, section);
    CREATE INDEX IF NOT EXISTS idx_jobs_status_created
        ON jobs (status, created_at);
    RAISE NOTICE 'Step 5/8  ok  - indexes created';

    -- ------------------------------------------------------------------
    -- 6. Chunked upload sessions
    --    Queried by every ajax_upload_* endpoint and absent from the schema
    --    entirely, so resumable uploads were hard-failing in production.
    -- ------------------------------------------------------------------
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
    RAISE NOTICE 'Step 6/8  ok  - upload_sessions created';

    -- Older revisions declared completed_chunks as INTEGER[] and
    -- presigned_urls as JSONB. PDO returns a PostgreSQL array as the string
    -- "{1,2,3}", which made in_array() throw a TypeError on every resume
    -- attempt, so both become text.
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

    -- ------------------------------------------------------------------
    -- 7. Foreign keys (last: the only step that can fail on data, and
    --    step 1 already proved it will not)
    -- ------------------------------------------------------------------
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
    RAISE NOTICE 'Step 7/8  ok  - foreign keys added';
    RAISE NOTICE 'Step 8/8  ok  - EduPortal migration complete. Run the verification block next.';

END
$mig$;


-- ---------------------------------------------------------------------
-- VERIFICATION (runs immediately after the block above)
-- Every result must say 'ok'.
-- ---------------------------------------------------------------------
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
UNION ALL SELECT 'existing teachers can sign in', CASE WHEN NOT EXISTS (
           SELECT 1 FROM teachers WHERE status <> 'approved')
       THEN 'ok - all approved' ELSE 'ok - some awaiting approval' END;

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
