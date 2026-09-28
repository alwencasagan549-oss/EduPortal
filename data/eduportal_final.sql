-- EDU-PORTAL SQL EXPORT (PRODUCTION READY)
-- Optimized for InfinityFree / MariaDB
-- Date: 2026-03-29

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- -----------------------------------------------------
-- Table: admin
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `admin` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `username` VARCHAR(50) NOT NULL,
    `password` VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table: teachers
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `teachers` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(100) NOT NULL,
    `subject` VARCHAR(100) NOT NULL,
    `password` VARCHAR(255) NOT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `teachers_subject_unique` (`subject`),
    UNIQUE KEY `teachers_email_unique` (`email`),
    KEY `idx_teachers_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table: students
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `students` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `lrn` VARCHAR(20) NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(100) DEFAULT NULL,
    `grade_level` VARCHAR(50) DEFAULT 'Grade 11',
    `section` VARCHAR(50) DEFAULT NULL,
    `strand` VARCHAR(50) DEFAULT 'Academic',
    `password` VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `lrn` (`lrn`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table: submissions
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `submissions` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `student_id` INT(11) DEFAULT NULL,
    `assignment_id` INT(11) DEFAULT NULL,
    `teacher_id` INT(11) DEFAULT NULL,
    `student_name` VARCHAR(100) DEFAULT NULL,
    `subject` VARCHAR(255) NOT NULL,
    `file_path` VARCHAR(255) NOT NULL,
    `file_content` LONGTEXT,
    `file_type` VARCHAR(100) DEFAULT 'application/octet-stream',
    `marks` VARCHAR(10) DEFAULT NULL,
    `remarks` TEXT DEFAULT NULL,
    `submission_date` DATE NOT NULL,
    `submitted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `student_id` (`student_id`),
    KEY `teacher_id` (`teacher_id`),
    KEY `idx_submissions_student_submitted` (`student_id`, `submitted_at` DESC),
    KEY `idx_submissions_teacher_submitted` (`teacher_id`, `submitted_at` DESC),
    KEY `idx_submissions_legacy_subject` (`subject`),
    UNIQUE KEY `idx_submissions_student_assignment_unique` (`student_id`, `assignment_id`),
    CONSTRAINT `submissions_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE SET NULL,
    CONSTRAINT `submissions_ibfk_2` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `submission_deletion_audit` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `submission_id` INT(11) DEFAULT NULL,
    `existing_submission_id` INT(11) DEFAULT NULL,
    `student_id` INT(11) DEFAULT NULL,
    `assignment_id` INT(11) DEFAULT NULL,
    `subject` VARCHAR(255) DEFAULT NULL,
    `file_path` TEXT,
    `file_removed` SMALLINT NOT NULL DEFAULT 0,
    `event` VARCHAR(100) NOT NULL DEFAULT 'submission_file_deleted',
    `reason` VARCHAR(100) NOT NULL,
    `actor_id` INT(11) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_submission_deletion_audit_student` (`student_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table: posted_assignments
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `posted_assignments` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `teacher_id` INT(11) NOT NULL,
    `teacher_name` VARCHAR(255) NOT NULL,
    `subject` VARCHAR(255) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `file_path` VARCHAR(255) NOT NULL,
    `file_content` LONGTEXT,
    `file_type` VARCHAR(100) DEFAULT 'application/octet-stream',
    `grade_level` VARCHAR(50) NOT NULL,
    `section` VARCHAR(50) NOT NULL,
    `strand` VARCHAR(50) DEFAULT 'Academic',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `teacher_id` (`teacher_id`),
    KEY `idx_posted_assignments_target_created` (`grade_level`, `strand`, `section`, `created_at` DESC),
    CONSTRAINT `posted_assignments_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table: jobs
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `jobs` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `type` VARCHAR(50) NOT NULL,
    `payload` TEXT NOT NULL,
    `status` ENUM('pending','processing','completed','failed') DEFAULT 'pending',
    `error_message` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table: notifications
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `notifications` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `type` VARCHAR(50) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `message` TEXT DEFAULT NULL,
    `data` JSON DEFAULT NULL,
    `is_read` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_notifications_user_created` (`user_id`, `created_at` DESC),
    KEY `idx_notifications_user_unread` (`user_id`, `is_read`),
    CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table: upload_sessions (S3 / R2 chunked uploads)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `upload_sessions` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `upload_id` VARCHAR(64) NOT NULL,
    `user_id` INT(11) NOT NULL,
    `user_role` VARCHAR(20) NOT NULL,
    `original_filename` TEXT NOT NULL,
    `stored_filename` TEXT DEFAULT NULL,
    `file_size` BIGINT NOT NULL,
    `mime_type` VARCHAR(100) DEFAULT NULL,
    `chunk_size` INT(11) NOT NULL,
    `total_chunks` INT(11) NOT NULL,
    `completed_chunks` TEXT DEFAULT '[]',
    `presigned_urls` LONGTEXT DEFAULT NULL,
    `object_key` TEXT DEFAULT NULL,
    `s3_upload_id` TEXT DEFAULT NULL,
    `status` VARCHAR(20) DEFAULT 'initiated',
    `error_message` TEXT DEFAULT NULL,
    `expires_at` DATETIME NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `upload_id` (`upload_id`),
    KEY `idx_upload_sessions_user` (`user_id`, `user_role`),
    KEY `idx_upload_sessions_status` (`status`),
    KEY `idx_upload_sessions_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;

-- Development seed data lives in data/dev_seed.sql and must never be applied
-- to a production database: it creates accounts with published passwords.
