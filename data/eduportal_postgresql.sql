-- EduPortal PostgreSQL Schema
-- Run this in the Aiven PostgreSQL database to create/update tables.

-- Students table
CREATE TABLE IF NOT EXISTS students (
    id SERIAL PRIMARY KEY,
    lrn VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    grade_level VARCHAR(50) DEFAULT 'Grade 11',
    section VARCHAR(50),
    strand VARCHAR(50) DEFAULT 'Academic',
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Teachers table
CREATE TABLE IF NOT EXISTS teachers (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    subject VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_teachers_email_unique
    ON teachers (LOWER(email));
CREATE INDEX IF NOT EXISTS idx_teachers_status ON teachers (status);

-- Submissions table
CREATE TABLE IF NOT EXISTS submissions (
    id SERIAL PRIMARY KEY,
    student_id INTEGER REFERENCES students (id) ON DELETE SET NULL,
    assignment_id INTEGER,
    teacher_id INTEGER REFERENCES teachers (id) ON DELETE SET NULL,
    student_name VARCHAR(100),
    subject VARCHAR(255) NOT NULL,
    file_path TEXT NOT NULL,
    file_content TEXT,
    file_type VARCHAR(100) DEFAULT 'application/octet-stream',
    marks VARCHAR(10),
    remarks TEXT,
    submission_date DATE NOT NULL,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Posted assignments table
CREATE TABLE IF NOT EXISTS posted_assignments (
    id SERIAL PRIMARY KEY,
    teacher_id INTEGER NOT NULL REFERENCES teachers (id) ON DELETE CASCADE,
    teacher_name VARCHAR(255) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    file_path TEXT NOT NULL,
    file_content TEXT,
    file_type VARCHAR(100) DEFAULT 'application/octet-stream',
    grade_level VARCHAR(50) NOT NULL,
    section VARCHAR(50) NOT NULL,
    strand VARCHAR(50) DEFAULT 'Academic',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE submissions
    ADD CONSTRAINT submissions_ibfk_assignment
    FOREIGN KEY (assignment_id) REFERENCES posted_assignments (id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_posted_assignments_teacher_created
    ON posted_assignments (teacher_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_posted_assignments_target_created
    ON posted_assignments (grade_level, strand, section, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_submissions_student_submitted
    ON submissions (student_id, submitted_at DESC);
CREATE INDEX IF NOT EXISTS idx_submissions_teacher_submitted
    ON submissions (teacher_id, submitted_at DESC);
CREATE INDEX IF NOT EXISTS idx_submissions_legacy_subject
    ON submissions (subject) WHERE teacher_id IS NULL;
CREATE INDEX IF NOT EXISTS idx_submissions_student_subject
    ON submissions (student_id, subject);
CREATE INDEX IF NOT EXISTS idx_submissions_student_subject_normalized
    ON submissions (student_id, LOWER(TRIM(subject)))
    WHERE assignment_id IS NULL;
CREATE UNIQUE INDEX IF NOT EXISTS idx_submissions_student_assignment_unique
    ON submissions (student_id, assignment_id)
    WHERE assignment_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS submission_deletion_audit (
    id BIGSERIAL PRIMARY KEY,
    submission_id INTEGER,
    existing_submission_id INTEGER,
    student_id INTEGER,
    assignment_id INTEGER,
    subject VARCHAR(255),
    file_path TEXT,
    file_removed SMALLINT NOT NULL DEFAULT 0,
    event VARCHAR(100) NOT NULL DEFAULT 'submission_file_deleted',
    reason VARCHAR(100) NOT NULL,
    actor_id INTEGER,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_submission_deletion_audit_student
    ON submission_deletion_audit (student_id, created_at);

-- Jobs table
CREATE TABLE IF NOT EXISTS jobs (
    id SERIAL PRIMARY KEY,
    type VARCHAR(50) NOT NULL,
    payload TEXT NOT NULL,
    status VARCHAR(20) DEFAULT 'pending',
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_jobs_status_created
    ON jobs (status, created_at);

-- Notifications table
CREATE TABLE IF NOT EXISTS notifications (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES students (id) ON DELETE CASCADE,
    type VARCHAR(50) NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT,
    data JSONB DEFAULT '{}',
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_notifications_user_created
    ON notifications (user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_notifications_user_unread
    ON notifications (user_id) WHERE is_read = FALSE;

-- Chunked upload sessions (Cloudflare R2 / S3 multipart)
CREATE TABLE IF NOT EXISTS upload_sessions (
    id SERIAL PRIMARY KEY,
    upload_id VARCHAR(64) NOT NULL UNIQUE,
    user_id INTEGER NOT NULL,
    user_role VARCHAR(20) NOT NULL,
    original_filename TEXT NOT NULL,
    stored_filename TEXT,
    file_size BIGINT NOT NULL,
    mime_type VARCHAR(100),
    chunk_size INTEGER NOT NULL,
    total_chunks INTEGER NOT NULL,
    completed_chunks INTEGER[] DEFAULT '{}',
    presigned_urls TEXT DEFAULT '[]',
    object_key TEXT,
    s3_upload_id TEXT,
    status VARCHAR(20) DEFAULT 'initiated',
    error_message TEXT,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_upload_sessions_user
    ON upload_sessions (user_id, user_role);
CREATE INDEX IF NOT EXISTS idx_upload_sessions_status
    ON upload_sessions (status);
CREATE INDEX IF NOT EXISTS idx_upload_sessions_expires
    ON upload_sessions (expires_at);

-- Index for student filtering
CREATE INDEX IF NOT EXISTS idx_students_filter ON students (grade_level, strand, section);
