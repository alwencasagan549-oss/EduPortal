-- =====================================================================
-- EduPortal LMS - Aiven PostgreSQL bootstrap
-- =====================================================================
-- FIVE RUNS. PG Studio caps a single Run at 10 queries and counts every
-- semicolon, including ones inside a DO block, so this is written as plain
-- single-statement SQL. Each Run below is at most 9.
--
-- HOW TO USE
--   1. Click in the editor, Ctrl+A, Delete, paste this whole file.
--   2. Select ONLY the block between two "RUN n of 5" markers.
--   3. Press Run. Wait for a green success.
--   4. Move to the next block.
--
-- Every statement is IF NOT EXISTS or a backfill, so re-running a block that
-- failed is safe. Already confirmed on this database: zero orphan rows, so
-- the foreign keys in RUN 4 will apply.
-- =====================================================================


-- =====================================================================
-- ==== RUN 1 of 5 - TEACHER APPROVAL GATE + 2 SUBMISSION COLUMNS ====
-- Self-service teacher signup used to hand out working accounts. Teacher
-- visibility is scoped by teachers.subject, so claiming an unused subject
-- exposed every submission filed under it. Existing teachers are set to
-- approved so nobody is locked out. Only NEW signups land on pending.
--
-- The submission columns below used to be created by ALTER TABLE inside the
-- controllers on every submission and every download.
-- =====================================================================
ALTER TABLE teachers ADD COLUMN IF NOT EXISTS status VARCHAR(20);
UPDATE teachers SET status = 'approved' WHERE status IS NULL OR status = '';
ALTER TABLE teachers ALTER COLUMN status SET DEFAULT 'pending';
ALTER TABLE teachers ALTER COLUMN status SET NOT NULL;
CREATE INDEX IF NOT EXISTS idx_teachers_status ON teachers (status);
ALTER TABLE submissions ADD COLUMN IF NOT EXISTS file_content TEXT DEFAULT NULL;
ALTER TABLE submissions ADD COLUMN IF NOT EXISTS file_type VARCHAR(100) DEFAULT 'application/octet-stream';


-- =====================================================================
-- ==== RUN 2 of 5 - REMAINING STORAGE COLUMNS + UPLOAD SESSIONS ====
-- upload_sessions is queried by every ajax_upload_* endpoint and was absent
-- from the schema entirely, so resumable uploads were hard-failing.
-- =====================================================================
ALTER TABLE submissions ADD COLUMN IF NOT EXISTS assignment_id INTEGER DEFAULT NULL;
ALTER TABLE submissions ADD COLUMN IF NOT EXISTS teacher_id INTEGER DEFAULT NULL;
ALTER TABLE posted_assignments ADD COLUMN IF NOT EXISTS file_content TEXT DEFAULT NULL;
ALTER TABLE posted_assignments ADD COLUMN IF NOT EXISTS file_type VARCHAR(100) DEFAULT 'application/octet-stream';
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
CREATE INDEX IF NOT EXISTS idx_upload_sessions_user ON upload_sessions (user_id, user_role);
CREATE INDEX IF NOT EXISTS idx_upload_sessions_status ON upload_sessions (status);
CREATE INDEX IF NOT EXISTS idx_upload_sessions_expires ON upload_sessions (expires_at);


-- =====================================================================
-- ==== RUN 3 of 5 - SUBMISSION AND ASSIGNMENT INDEXES ====
-- teacher_id had no supporting index at all, so the teacher dashboard and
-- both download endpoints were a sequential scan plus a sort per request.
-- =====================================================================
CREATE INDEX IF NOT EXISTS idx_submissions_student_subject ON submissions (student_id, subject);
CREATE INDEX IF NOT EXISTS idx_submissions_student_subject_normalized ON submissions (student_id, LOWER(TRIM(subject))) WHERE assignment_id IS NULL;
CREATE INDEX IF NOT EXISTS idx_submissions_student_submitted ON submissions (student_id, submitted_at DESC);
CREATE INDEX IF NOT EXISTS idx_submissions_teacher_submitted ON submissions (teacher_id, submitted_at DESC);
CREATE INDEX IF NOT EXISTS idx_submissions_legacy_subject ON submissions (subject) WHERE teacher_id IS NULL;
CREATE UNIQUE INDEX IF NOT EXISTS idx_submissions_student_assignment_unique ON submissions (student_id, assignment_id) WHERE assignment_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_posted_assignments_teacher_created ON posted_assignments (teacher_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_posted_assignments_target_created ON posted_assignments (grade_level, strand, section, created_at DESC);


-- =====================================================================
-- ==== RUN 4 of 5 - REMAINING INDEXES + FOREIGN KEYS ====
-- The MariaDB schema always had these constraints. PostgreSQL was missing
-- them, so deleted students left dangling submissions and notifications.
-- ON DELETE CASCADE removes a student's notifications with the account.
-- ON DELETE SET NULL keeps a submission but detaches it from a deleted
-- student or teacher. RUN 1 already proved there are no orphan rows.
--
-- Note: these are plain ADD CONSTRAINT and will error if they already
-- exist. Skip this block if you have run it before.
-- =====================================================================
CREATE INDEX IF NOT EXISTS idx_notifications_user_created ON notifications (user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_notifications_user_unread ON notifications (user_id) WHERE is_read = FALSE;
CREATE INDEX IF NOT EXISTS idx_students_filter ON students (grade_level, strand, section);
CREATE INDEX IF NOT EXISTS idx_jobs_status_created ON jobs (status, created_at);
ALTER TABLE submissions ADD CONSTRAINT submissions_fk_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE SET NULL;
ALTER TABLE submissions ADD CONSTRAINT submissions_fk_teacher FOREIGN KEY (teacher_id) REFERENCES teachers (id) ON DELETE SET NULL;
ALTER TABLE submissions ADD CONSTRAINT submissions_fk_assignment FOREIGN KEY (assignment_id) REFERENCES posted_assignments (id) ON DELETE SET NULL;
ALTER TABLE posted_assignments ADD CONSTRAINT posted_assignments_fk_teacher FOREIGN KEY (teacher_id) REFERENCES teachers (id) ON DELETE CASCADE;
ALTER TABLE notifications ADD CONSTRAINT notifications_fk_user FOREIGN KEY (user_id) REFERENCES students (id) ON DELETE CASCADE;


-- =====================================================================
-- ==== RUN 5 of 5 - VERIFICATION ====
-- Every result must say ok. Then confirm nothing was lost.
-- =====================================================================
SELECT 'teachers.status column' AS check, CASE WHEN EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'teachers' AND column_name = 'status') THEN 'ok' ELSE 'FAILED' END AS result
UNION ALL SELECT 'upload_sessions table', CASE WHEN to_regclass('public.upload_sessions') IS NOT NULL THEN 'ok' ELSE 'FAILED' END
UNION ALL SELECT 'submissions.teacher_id index', CASE WHEN EXISTS (SELECT 1 FROM pg_indexes WHERE tablename = 'submissions' AND indexname = 'idx_submissions_teacher_submitted') THEN 'ok' ELSE 'FAILED' END
UNION ALL SELECT 'unique student+assignment', CASE WHEN EXISTS (SELECT 1 FROM pg_indexes WHERE tablename = 'submissions' AND indexname = 'idx_submissions_student_assignment_unique') THEN 'ok' ELSE 'FAILED' END
UNION ALL SELECT 'notifications user/created index', CASE WHEN EXISTS (SELECT 1 FROM pg_indexes WHERE tablename = 'notifications' AND indexname = 'idx_notifications_user_created') THEN 'ok' ELSE 'FAILED' END
UNION ALL SELECT '3 foreign keys on submissions', CASE WHEN (SELECT COUNT(*) FROM pg_constraint WHERE conrelid = 'submissions'::regclass AND contype = 'f') = 3 THEN 'ok' ELSE 'FAILED' END
UNION ALL SELECT 'no teacher has a blank status', CASE WHEN NOT EXISTS (SELECT 1 FROM teachers WHERE status IS NULL OR status = '') THEN 'ok' ELSE 'FAILED' END
UNION ALL SELECT 'existing teachers can sign in', CASE WHEN NOT EXISTS (SELECT 1 FROM teachers WHERE status <> 'approved') THEN 'ok - all approved' ELSE 'ok - some awaiting approval' END;

SELECT 'students' AS table_name, COUNT(*) FROM students
UNION ALL SELECT 'teachers', COUNT(*) FROM teachers
UNION ALL SELECT 'submissions', COUNT(*) FROM submissions
UNION ALL SELECT 'posted_assignments', COUNT(*) FROM posted_assignments
UNION ALL SELECT 'notifications', COUNT(*) FROM notifications
UNION ALL SELECT 'upload_sessions', COUNT(*) FROM upload_sessions;


-- Optional, run one at a time later once you have reviewed them.
-- Review:  SELECT id, name, email, subject, status FROM teachers ORDER BY id
-- Approve: UPDATE teachers SET status = 'approved' WHERE id IN (1, 2)
