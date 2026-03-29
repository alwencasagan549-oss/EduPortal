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
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `email` (`email`)
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
    `teacher_id` INT(11) DEFAULT NULL,
    `student_name` VARCHAR(100) DEFAULT NULL,
    `subject` VARCHAR(100) NOT NULL,
    `file_path` VARCHAR(255) NOT NULL,
    `marks` VARCHAR(10) DEFAULT NULL,
    `remarks` TEXT DEFAULT NULL,
    `submission_date` DATE NOT NULL,
    `submitted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `student_id` (`student_id`),
    KEY `teacher_id` (`teacher_id`),
    CONSTRAINT `submissions_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE SET NULL,
    CONSTRAINT `submissions_ibfk_2` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE SET NULL
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
    `grade_level` VARCHAR(50) NOT NULL,
    `section` VARCHAR(50) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `teacher_id` (`teacher_id`)
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
-- SEED DATA (Password for all: admin123, teacher123, student123)
-- -----------------------------------------------------

INSERT INTO `admin` (`username`, `password`) VALUES
('admin', '$2y$10$CMOcgV0.HISHsoDWTeLnQeJ0Ys9BMWoEF1pEcDEnP0M5RpFBPiBKy')
ON DUPLICATE KEY UPDATE `password` = VALUES(`password`);

INSERT INTO `teachers` (`name`, `email`, `subject`, `password`) VALUES
('John Smith', 'john@example.com', 'Mathematics', '$2y$10$CMOcgV0.HISHsoDWTeLnQeJ0Ys9BMWoEF1pEcDEnP0M5RpFBPiBKy'),
('Sarah Johnson', 'sarah@example.com', 'Physics', '$2y$10$CMOcgV0.HISHsoDWTeLnQeJ0Ys9BMWoEF1pEcDEnP0M5RpFBPiBKy')
ON DUPLICATE KEY UPDATE `password` = VALUES(`password`);

INSERT INTO `students` (`lrn`, `name`, `email`, `grade_level`, `section`, `password`) VALUES
('123456789012', 'Alex Johnson', 'alex@example.com', 'Grade 12', 'ICT', '$2y$10$CMOcgV0.HISHsoDWTeLnQeJ0Ys9BMWoEF1pEcDEnP0M5RpFBPiBKy'),
('987654321098', 'Maya Rivera', 'maya@example.com', 'Grade 11', 'STEM', '$2y$10$CMOcgV0.HISHsoDWTeLnQeJ0Ys9BMWoEF1pEcDEnP0M5RpFBPiBKy')
ON DUPLICATE KEY UPDATE `password` = VALUES(`password`);

COMMIT;
