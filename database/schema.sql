-- ============================================================================
-- Ministry of Youth and Sports, Republic of Liberia
-- Interview Panel Management and Scoring System
-- Database Schema (MySQL 8.0+ / MariaDB 10.6+)
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 1. Users (Administrators, HR Officers, Panelists)
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `full_name` VARCHAR(150) NOT NULL,
    `email` VARCHAR(180) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `role` ENUM('admin', 'hr_officer', 'panelist') NOT NULL DEFAULT 'panelist',
    `job_title` VARCHAR(150) NULL,
    `department_id` INT UNSIGNED NULL,
    `phone` VARCHAR(40) NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `last_login_at` DATETIME NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_email` (`email`),
    KEY `idx_users_role` (`role`),
    KEY `idx_users_department` (`department_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Ministry Departments
CREATE TABLE IF NOT EXISTS `departments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(30) NOT NULL,
    `name` VARCHAR(160) NOT NULL,
    `description` TEXT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_departments_code` (`code`),
    UNIQUE KEY `uq_departments_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `users`
    ADD CONSTRAINT `fk_users_department`
    FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE;

-- 3. Physical Interview Venues
CREATE TABLE IF NOT EXISTS `interview_venues` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `venue_name` VARCHAR(180) NOT NULL,
    `building` VARCHAR(150) NULL,
    `room_number` VARCHAR(80) NOT NULL,
    `address` VARCHAR(255) NOT NULL,
    `capacity` SMALLINT UNSIGNED NOT NULL DEFAULT 15,
    `notes` TEXT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_venues_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Interview Sessions (Supports BOTH Physical and Virtual Modes)
CREATE TABLE IF NOT EXISTS `interview_sessions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_code` VARCHAR(40) NOT NULL,
    `title` VARCHAR(200) NOT NULL,
    `department_id` INT UNSIGNED NOT NULL,
    `job_title` VARCHAR(180) NOT NULL,
    `job_description` TEXT NULL,
    `interview_mode` ENUM('physical', 'virtual') NOT NULL DEFAULT 'physical',
    `interview_date` DATE NOT NULL,
    `start_time` TIME NOT NULL,
    `end_time` TIME NOT NULL,
    `venue_id` INT UNSIGNED NULL,
    `venue_room_override` VARCHAR(120) NULL,
    `instructions` TEXT NULL,
    `status` ENUM('draft', 'scheduled', 'in_progress', 'completed', 'cancelled', 'archived') NOT NULL DEFAULT 'scheduled',
    `created_by` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_sessions_code` (`session_code`),
    KEY `idx_sessions_mode` (`interview_mode`),
    KEY `idx_sessions_date` (`interview_date`),
    KEY `idx_sessions_status` (`status`),
    KEY `idx_sessions_department` (`department_id`),
    CONSTRAINT `fk_sessions_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_sessions_venue` FOREIGN KEY (`venue_id`) REFERENCES `interview_venues` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_sessions_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Video Interview Rooms (Strictly for Virtual Interview Sessions)
CREATE TABLE IF NOT EXISTS `video_interview_rooms` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_id` INT UNSIGNED NOT NULL,
    `provider` ENUM('jitsi', 'livekit') NOT NULL DEFAULT 'jitsi',
    `server_domain` VARCHAR(255) NULL,
    `room_name` VARCHAR(160) NOT NULL,
    `room_passcode` VARCHAR(80) NULL,
    `panelist_access_token` VARCHAR(128) NOT NULL,
    `waiting_room_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `recording_enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `recording_consent_required` TINYINT(1) NOT NULL DEFAULT 1,
    `recording_authorized_by` INT UNSIGNED NULL,
    `recording_authorized_at` DATETIME NULL,
    `meeting_status` ENUM('scheduled', 'waiting', 'live', 'ended') NOT NULL DEFAULT 'scheduled',
    `started_at` DATETIME NULL,
    `ended_at` DATETIME NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_video_room_session` (`session_id`),
    UNIQUE KEY `uq_video_room_name` (`room_name`),
    CONSTRAINT `fk_video_room_session` FOREIGN KEY (`session_id`) REFERENCES `interview_sessions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_video_room_rec_auth` FOREIGN KEY (`recording_authorized_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Candidates Master Registry
CREATE TABLE IF NOT EXISTS `candidates` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `application_number` VARCHAR(60) NOT NULL,
    `full_name` VARCHAR(160) NOT NULL,
    `email` VARCHAR(180) NULL,
    `phone` VARCHAR(40) NULL,
    `gender` ENUM('Male', 'Female', 'Other', 'Prefer not to say') NULL,
    `highest_qualification` VARCHAR(180) NULL,
    `years_experience` DECIMAL(4,1) NULL DEFAULT 0.0,
    `county` VARCHAR(80) NULL,
    `notes` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_candidates_app_no` (`application_number`),
    KEY `idx_candidates_name` (`full_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Session Candidates (Scheduling + Virtual Invite Token + Status)
CREATE TABLE IF NOT EXISTS `session_candidates` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_id` INT UNSIGNED NOT NULL,
    `candidate_id` INT UNSIGNED NOT NULL,
    `scheduled_time` TIME NULL,
    `interview_order` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `interview_status` ENUM('scheduled', 'checked_in', 'waiting_room', 'in_progress', 'completed', 'absent', 'rescheduled') NOT NULL DEFAULT 'scheduled',
    `interview_started_at` DATETIME NULL,
    `interview_ended_at` DATETIME NULL,
    `virtual_invite_token` VARCHAR(96) NULL,
    `waiting_room_admitted` TINYINT(1) NOT NULL DEFAULT 0,
    `recording_consent_given` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_session_candidate` (`session_id`, `candidate_id`),
    UNIQUE KEY `uq_virtual_invite_token` (`virtual_invite_token`),
    KEY `idx_sc_status` (`interview_status`),
    CONSTRAINT `fk_sc_session` FOREIGN KEY (`session_id`) REFERENCES `interview_sessions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_sc_candidate` FOREIGN KEY (`candidate_id`) REFERENCES `candidates` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Session Panelists
CREATE TABLE IF NOT EXISTS `session_panelists` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_id` INT UNSIGNED NOT NULL,
    `panelist_id` INT UNSIGNED NOT NULL,
    `panel_role` ENUM('chairperson', 'member', 'technical_specialist', 'hr_representative') NOT NULL DEFAULT 'member',
    `assigned_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_session_panelist` (`session_id`, `panelist_id`),
    KEY `idx_sp_panelist` (`panelist_id`),
    CONSTRAINT `fk_sp_session` FOREIGN KEY (`session_id`) REFERENCES `interview_sessions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_sp_panelist` FOREIGN KEY (`panelist_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Optional Candidate-Specific Panelist Assignments
-- If rows exist for a session_candidate, only those panelists are assigned to that candidate;
-- otherwise all session_panelists assess the candidate.
CREATE TABLE IF NOT EXISTS `candidate_panelist_assignments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_id` INT UNSIGNED NOT NULL,
    `candidate_id` INT UNSIGNED NOT NULL,
    `panelist_id` INT UNSIGNED NOT NULL,
    `assigned_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_cpa` (`session_id`, `candidate_id`, `panelist_id`),
    CONSTRAINT `fk_cpa_session` FOREIGN KEY (`session_id`) REFERENCES `interview_sessions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_cpa_candidate` FOREIGN KEY (`candidate_id`) REFERENCES `candidates` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_cpa_panelist` FOREIGN KEY (`panelist_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Candidate Attendance Register (Physical & Virtual)
CREATE TABLE IF NOT EXISTS `candidate_attendance` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_id` INT UNSIGNED NOT NULL,
    `candidate_id` INT UNSIGNED NOT NULL,
    `scheduled_time` TIME NULL,
    `actual_arrival_time` DATETIME NULL,
    `attendance_status` ENUM('Present', 'Absent', 'Late', 'Rescheduled') NOT NULL DEFAULT 'Absent',
    `remarks` VARCHAR(500) NULL,
    `recorded_by` INT UNSIGNED NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_cand_att_session` (`session_id`, `candidate_id`),
    KEY `idx_cand_att_status` (`attendance_status`),
    CONSTRAINT `fk_ca_session` FOREIGN KEY (`session_id`) REFERENCES `interview_sessions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_ca_candidate` FOREIGN KEY (`candidate_id`) REFERENCES `candidates` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_ca_recorder` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Panelist Attendance Register
CREATE TABLE IF NOT EXISTS `panelist_attendance` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_id` INT UNSIGNED NOT NULL,
    `panelist_id` INT UNSIGNED NOT NULL,
    `attendance_status` ENUM('Present', 'Absent', 'Late', 'Rescheduled') NOT NULL DEFAULT 'Absent',
    `check_in_time` DATETIME NULL,
    `remarks` VARCHAR(500) NULL,
    `recorded_by` INT UNSIGNED NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_pan_att_session` (`session_id`, `panelist_id`),
    KEY `idx_pan_att_status` (`attendance_status`),
    CONSTRAINT `fk_pa_session` FOREIGN KEY (`session_id`) REFERENCES `interview_sessions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_pa_panelist` FOREIGN KEY (`panelist_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_pa_recorder` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Configurable Scoring Criteria (Per Interview Session)
CREATE TABLE IF NOT EXISTS `scoring_criteria` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_id` INT UNSIGNED NOT NULL,
    `criterion_name` VARCHAR(160) NOT NULL,
    `description` TEXT NULL,
    `max_marks` DECIMAL(6,2) NOT NULL,
    `display_order` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_criteria_session` (`session_id`, `display_order`),
    CONSTRAINT `fk_criteria_session` FOREIGN KEY (`session_id`) REFERENCES `interview_sessions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Panel Submissions (Independent Panelist Evaluation Header)
CREATE TABLE IF NOT EXISTS `panel_submissions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_id` INT UNSIGNED NOT NULL,
    `candidate_id` INT UNSIGNED NOT NULL,
    `panelist_id` INT UNSIGNED NOT NULL,
    `total_score` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    `max_possible_score` DECIMAL(6,2) NOT NULL DEFAULT 100.00,
    `percentage_score` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `recommendation` ENUM('strongly_recommended', 'recommended', 'recommended_with_reservations', 'not_recommended') NULL,
    `overall_comments` TEXT NULL,
    `strengths` TEXT NULL,
    `areas_for_improvement` TEXT NULL,
    `status` ENUM('draft', 'submitted') NOT NULL DEFAULT 'draft',
    `submitted_at` DATETIME NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_submission_unique` (`session_id`, `candidate_id`, `panelist_id`),
    KEY `idx_sub_status` (`status`),
    KEY `idx_sub_panelist` (`panelist_id`),
    CONSTRAINT `fk_sub_session` FOREIGN KEY (`session_id`) REFERENCES `interview_sessions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_sub_candidate` FOREIGN KEY (`candidate_id`) REFERENCES `candidates` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_sub_panelist` FOREIGN KEY (`panelist_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. Panel Scores (Per-Criterion Marks Linked to a Submission)
CREATE TABLE IF NOT EXISTS `panel_scores` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `submission_id` INT UNSIGNED NOT NULL,
    `criterion_id` INT UNSIGNED NOT NULL,
    `score` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    `remarks` VARCHAR(500) NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_score_sub_crit` (`submission_id`, `criterion_id`),
    CONSTRAINT `fk_ps_submission` FOREIGN KEY (`submission_id`) REFERENCES `panel_submissions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_ps_criterion` FOREIGN KEY (`criterion_id`) REFERENCES `scoring_criteria` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. Interview Notes (Shared/Personal Session & Candidate Notes for Physical & Virtual)
CREATE TABLE IF NOT EXISTS `interview_notes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_id` INT UNSIGNED NOT NULL,
    `candidate_id` INT UNSIGNED NULL,
    `author_id` INT UNSIGNED NOT NULL,
    `note_type` ENUM('general', 'candidate', 'technical_issue', 'chairperson_summary') NOT NULL DEFAULT 'general',
    `content` TEXT NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_notes_session` (`session_id`, `candidate_id`),
    CONSTRAINT `fk_notes_session` FOREIGN KEY (`session_id`) REFERENCES `interview_sessions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_notes_candidate` FOREIGN KEY (`candidate_id`) REFERENCES `candidates` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_notes_author` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. Official Result Releases (Controls Blind Scoring Confidentiality)
CREATE TABLE IF NOT EXISTS `result_releases` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_id` INT UNSIGNED NOT NULL,
    `is_released` TINYINT(1) NOT NULL DEFAULT 0,
    `released_by` INT UNSIGNED NULL,
    `released_at` DATETIME NULL,
    `release_notes` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_release_session` (`session_id`),
    CONSTRAINT `fk_release_session` FOREIGN KEY (`session_id`) REFERENCES `interview_sessions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_release_user` FOREIGN KEY (`released_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 17. Audit Logs (Immutable Compliance & Security Trail)
CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NULL,
    `action` VARCHAR(100) NOT NULL,
    `entity_type` VARCHAR(80) NOT NULL,
    `entity_id` INT UNSIGNED NULL,
    `details` TEXT NULL,
    `ip_address` VARCHAR(45) NULL,
    `user_agent` VARCHAR(255) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_audit_action` (`action`),
    KEY `idx_audit_entity` (`entity_type`, `entity_id`),
    KEY `idx_audit_created` (`created_at`),
    CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 18. Public Live Scoreboards (No-Login Token-Protected Live Score View)
CREATE TABLE IF NOT EXISTS `live_scoreboards` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_id` INT UNSIGNED NOT NULL,
    `access_token` VARCHAR(96) NOT NULL,
    `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `show_live_scores` TINYINT(1) NOT NULL DEFAULT 1,
    `auto_refresh_seconds` SMALLINT UNSIGNED NOT NULL DEFAULT 10,
    `created_by` INT UNSIGNED NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_live_board_session` (`session_id`),
    UNIQUE KEY `uq_live_board_token` (`access_token`),
    CONSTRAINT `fk_lb_session` FOREIGN KEY (`session_id`) REFERENCES `interview_sessions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_lb_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
