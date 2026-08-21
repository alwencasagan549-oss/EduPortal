-- EduPortal LMS - PostgreSQL Schema
-- For SnapDeploy / Render / Production
-- Run this against your PostgreSQL database

-- Table: teachers
CREATE TABLE IF NOT EXISTS teachers (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    subject VARCHAR(100) NOT NULL,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Table: students
CREATE TABLE IF NOT EXISTS students (
    id SERIAL PRIMARY KEY,
    lrn VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    grade_level VARCHAR(50) DEFAULT 'Grade 11',
    strand VARCHAR(50),
    section VARCHAR(50),
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Table: submissions
CREATE TABLE IF NOT EXISTS submissions (
    id SERIAL PRIMARY KEY,
    student_id INTEGER REFERENCES students(id) ON DELETE SET NULL,
    teacher_id INTEGER REFERENCES teachers(id) ON DELETE SET NULL,
    student_name VARCHAR(100),
    subject VARCHAR(100) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_content TEXT,
    file_type VARCHAR(100) DEFAULT 'application/octet-stream',
    marks VARCHAR(10),
    remarks TEXT,
    submission_date DATE NOT NULL,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_submissions_student_id ON submissions(student_id);
CREATE INDEX IF NOT EXISTS idx_submissions_teacher_id ON submissions(teacher_id);
CREATE INDEX IF NOT EXISTS idx_submissions_subject ON submissions(subject);

-- Table: posted_assignments
CREATE TABLE IF NOT EXISTS posted_assignments (
    id SERIAL PRIMARY KEY,
    teacher_id INTEGER NOT NULL REFERENCES teachers(id),
    teacher_name VARCHAR(255) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    file_path VARCHAR(255) NOT NULL,
    file_content TEXT,
    file_type VARCHAR(100) DEFAULT 'application/octet-stream',
    grade_level VARCHAR(50) NOT NULL,
    strand VARCHAR(50),
    section VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_posted_assignments_teacher_id ON posted_assignments(teacher_id);
CREATE INDEX IF NOT EXISTS idx_posted_assignments_grade_section ON posted_assignments(grade_level, strand, section);

-- Table: jobs (email queue)
CREATE TABLE IF NOT EXISTS jobs (
    id SERIAL PRIMARY KEY,
    type VARCHAR(50) NOT NULL,
    payload TEXT NOT NULL,
    status VARCHAR(20) DEFAULT 'pending',
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Seed data (passwords: admin123, teacher123, student123)
INSERT INTO teachers (name, email, subject, password) VALUES
    ('John Smith', 'john@example.com', 'Mathematics', '$2y$10$CMOcgV0.HISHsoDWTeLnQeJ0Ys9BMWoEF1pEcDEnP0M5RpFBPiBKy'),
    ('Sarah Johnson', 'sarah@example.com', 'Physics', '$2y$10$CMOcgV0.HISHsoDWTeLnQeJ0Ys9BMWoEF1pEcDEnP0M5RpFBPiBKy')
ON CONFLICT (email) DO UPDATE SET password = EXCLUDED.password;

INSERT INTO students (lrn, name, email, grade_level, strand, section, password) VALUES
    ('123456789012', 'Alex Johnson', 'alex@example.com', 'Grade 12', 'ICT', 'A', '$2y$10$CMOcgV0.HISHsoDWTeLnQeJ0Ys9BMWoEF1pEcDEnP0M5RpFBPiBKy'),
    ('987654321098', 'Maya Rivera', 'maya@example.com', 'Grade 11', 'STEM', 'A', '$2y$10$CMOcgV0.HISHsoDWTeLnQeJ0Ys9BMWoEF1pEcDEnP0M5RpFBPiBKy')
ON CONFLICT (lrn) DO UPDATE SET password = EXCLUDED.password;
