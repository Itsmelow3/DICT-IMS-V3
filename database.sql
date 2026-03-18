-- ============================================================
--  DICT ISABELA – Intern Management System v2
--  Updated Schema per System Instructions
-- ============================================================

CREATE DATABASE IF NOT EXISTS ims_dict CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE ims_dict;

-- ─────────────────────────────────────────────────────────────
-- 1. USERS
-- ─────────────────────────────────────────────────────────────
CREATE TABLE users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(60)   NOT NULL UNIQUE,
    intern_id     VARCHAR(10)   NULL UNIQUE,           -- Format: YY-XXXX  (interns only)
    password_hash VARCHAR(255)  NOT NULL,
    role          ENUM('admin','supervisor','intern')  NOT NULL DEFAULT 'intern',
    province      ENUM('Tuguegarao','Quirino','Cauayan','Santiago','Batanes','Nueva Vizcaya','') DEFAULT '',
    is_active     TINYINT(1)    NOT NULL DEFAULT 1,
    created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login    DATETIME      NULL
) ENGINE=InnoDB;

-- ─────────────────────────────────────────────────────────────
-- 2. INTERN PROFILES  (interns update their own after enrollment)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE intern_profiles (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    user_id              INT           NOT NULL UNIQUE,
    full_name            VARCHAR(120)  NOT NULL DEFAULT '',
    email                VARCHAR(120)  NULL,
    contact_number       VARCHAR(20)   NULL,
    address              TEXT          NULL,
    age                  TINYINT UNSIGNED NULL,
    gender               ENUM('Male','Female','Other','') DEFAULT '',
    school               VARCHAR(150)  NULL,
    course               VARCHAR(150)  NULL,
    province             ENUM('Tuguegarao','Quirino','Cauayan','Santiago','Batanes','Nueva Vizcaya','') DEFAULT '',
    profile_photo        VARCHAR(500)  NULL,
    ojt_hours_required   INT           NOT NULL DEFAULT 480,
    ojt_start_date       DATE          NULL,
    ojt_end_date         DATE          NULL,
    status               ENUM('active','graduated','inactive') NOT NULL DEFAULT 'active',
    session_access       TINYINT(1)    NOT NULL DEFAULT 0,   -- 1 = Admin-granted session creation right
    created_at           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ip_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─────────────────────────────────────────────────────────────
-- 3. ATTENDANCE  (interns only; admins/supervisors monitor)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE attendance (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    user_id           INT           NOT NULL,
    attendance_date   DATE          NOT NULL,

    am_time_in        TIME          NULL,
    am_time_out       TIME          NULL,
    pm_time_in        TIME          NULL,
    pm_time_out       TIME          NULL,

    -- Auto-calculated by trigger
    hours_rendered    DECIMAL(6,4)  NOT NULL DEFAULT 0.0000,
    minutes_rendered  INT           NOT NULL DEFAULT 0,

    -- Full Day | Half Day | Early Out | Absent | In Progress
    attendance_status ENUM('Full Day','Half Day','Early Out','Absent','In Progress') NOT NULL DEFAULT 'Absent',

    remarks           VARCHAR(255)  NULL,
    created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_user_date (user_id, attendance_date),
    CONSTRAINT fk_att_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─────────────────────────────────────────────────────────────
-- 4. DOCUMENT ASSIGNMENTS  (Admin/Supervisor → Interns)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE document_assignments (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    assigned_by     INT           NOT NULL,
    target_user_id  INT           NULL,           -- NULL = broadcast to all interns
    title           VARCHAR(200)  NOT NULL,
    description     TEXT          NULL,
    file_path       VARCHAR(500)  NULL,           -- Physical file attached by admin
    assigned_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_da_assigner FOREIGN KEY (assigned_by)     REFERENCES users(id),
    CONSTRAINT fk_da_target   FOREIGN KEY (target_user_id)  REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ─────────────────────────────────────────────────────────────
-- 5. DOCUMENTS – Inbound (Interns submit)
--    Title format: [Last_Name]-[Type]  e.g. Smith-Waiver
-- ─────────────────────────────────────────────────────────────
CREATE TABLE documents (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT           NOT NULL,
    doc_type      ENUM('resume','endorsement','application','nda','waiver','medical','other') NOT NULL,
    title         VARCHAR(200)  NOT NULL,          -- Enforced format: LastName-Type
    file_path     VARCHAR(500)  NULL,
    notes         TEXT          NULL,
    status        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    submitted_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at   DATETIME      NULL,
    reviewed_by   INT           NULL,
    CONSTRAINT fk_doc_user     FOREIGN KEY (user_id)     REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_doc_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ─────────────────────────────────────────────────────────────
-- 6. LEARNING SESSION ACCESS REQUESTS
-- ─────────────────────────────────────────────────────────────
CREATE TABLE session_access_requests (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT           NOT NULL,
    status       ENUM('pending','approved','denied') NOT NULL DEFAULT 'pending',
    requested_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at  DATETIME      NULL,
    reviewed_by  INT           NULL,
    CONSTRAINT fk_sar_user     FOREIGN KEY (user_id)     REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_sar_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ─────────────────────────────────────────────────────────────
-- 7. LEARNING SESSIONS
-- ─────────────────────────────────────────────────────────────
CREATE TABLE learning_sessions (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    title             VARCHAR(200)  NOT NULL,
    hosted_by         INT           NOT NULL,
    host_name         VARCHAR(120)  NOT NULL,
    platform          ENUM('Google Meet','Zoom','Other') NOT NULL DEFAULT 'Google Meet',
    meeting_link      VARCHAR(500)  NULL,
    session_date      DATE          NOT NULL,
    start_time        TIME          NOT NULL,
    end_time          TIME          NULL,
    target_provinces  TEXT          NULL,          -- comma-separated e.g. "Cauayan,Quirino"
    created_by_role   ENUM('admin','supervisor','intern') NOT NULL DEFAULT 'admin',
    created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sess_host FOREIGN KEY (hosted_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ─────────────────────────────────────────────────────────────
-- 8. WEEKLY REPORTS
--    Title format: [Last_Name]-[Week-No.]  e.g. Smith-Week-1
-- ─────────────────────────────────────────────────────────────
CREATE TABLE weekly_reports (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT           NOT NULL,
    title         VARCHAR(200)  NOT NULL,          -- Enforced format: LastName-Week-N
    week_number   TINYINT UNSIGNED NOT NULL,
    week_range    VARCHAR(80)   NULL,
    summary       TEXT          NULL,
    file_path     VARCHAR(500)  NULL,
    status        ENUM('submitted','reviewed','approved') NOT NULL DEFAULT 'submitted',
    submitted_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_rpt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─────────────────────────────────────────────────────────────
-- 9. REPORT TEMPLATES  (Admin uploads → Interns download)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE report_templates (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    title        VARCHAR(200)  NOT NULL,
    description  TEXT          NULL,
    file_path    VARCHAR(500)  NOT NULL,
    uploaded_by  INT           NOT NULL,
    uploaded_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_rt_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ─────────────────────────────────────────────────────────────
-- 10. NOTIFICATIONS
-- ─────────────────────────────────────────────────────────────
CREATE TABLE notifications (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT           NOT NULL,
    type        ENUM('document_request','session_created','report_reviewed',
                     'access_approved','access_denied','general') NOT NULL DEFAULT 'general',
    title       VARCHAR(200)  NOT NULL,
    message     TEXT          NOT NULL,
    is_read     TINYINT(1)    NOT NULL DEFAULT 0,
    related_id  INT           NULL,
    created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─────────────────────────────────────────────────────────────
-- TRIGGERS: Auto-calculate hours + attendance_status
-- ─────────────────────────────────────────────────────────────
DELIMITER $$

CREATE TRIGGER trg_att_insert
BEFORE INSERT ON attendance
FOR EACH ROW
BEGIN
    DECLARE am_mins INT DEFAULT 0;
    DECLARE pm_mins INT DEFAULT 0;
    DECLARE total_mins INT DEFAULT 0;
    DECLARE att_status VARCHAR(20) DEFAULT 'Absent';

    IF NEW.am_time_in IS NOT NULL AND NEW.am_time_out IS NOT NULL THEN
        SET am_mins = GREATEST(0, TIMESTAMPDIFF(MINUTE, NEW.am_time_in, NEW.am_time_out));
    END IF;
    IF NEW.pm_time_in IS NOT NULL AND NEW.pm_time_out IS NOT NULL THEN
        SET pm_mins = GREATEST(0, TIMESTAMPDIFF(MINUTE, NEW.pm_time_in, NEW.pm_time_out));
    END IF;

    SET total_mins = am_mins + pm_mins;
    SET NEW.hours_rendered   = total_mins / 60.0;
    SET NEW.minutes_rendered = total_mins;

    IF total_mins = 0 THEN
        IF NEW.am_time_in IS NOT NULL OR NEW.pm_time_in IS NOT NULL THEN
            SET att_status = 'In Progress';
        ELSE
            SET att_status = 'Absent';
        END IF;
    ELSEIF total_mins >= 480 THEN
        SET att_status = 'Full Day';
    ELSEIF total_mins >= 240 THEN
        SET att_status = 'Half Day';
    ELSE
        SET att_status = 'Early Out';
    END IF;
    SET NEW.attendance_status = att_status;
END$$

CREATE TRIGGER trg_att_update
BEFORE UPDATE ON attendance
FOR EACH ROW
BEGIN
    DECLARE am_mins INT DEFAULT 0;
    DECLARE pm_mins INT DEFAULT 0;
    DECLARE total_mins INT DEFAULT 0;
    DECLARE att_status VARCHAR(20) DEFAULT 'Absent';

    IF NEW.am_time_in IS NOT NULL AND NEW.am_time_out IS NOT NULL THEN
        SET am_mins = GREATEST(0, TIMESTAMPDIFF(MINUTE, NEW.am_time_in, NEW.am_time_out));
    END IF;
    IF NEW.pm_time_in IS NOT NULL AND NEW.pm_time_out IS NOT NULL THEN
        SET pm_mins = GREATEST(0, TIMESTAMPDIFF(MINUTE, NEW.pm_time_in, NEW.pm_time_out));
    END IF;

    SET total_mins = am_mins + pm_mins;
    SET NEW.hours_rendered   = total_mins / 60.0;
    SET NEW.minutes_rendered = total_mins;

    IF total_mins = 0 THEN
        IF NEW.am_time_in IS NOT NULL OR NEW.pm_time_in IS NOT NULL THEN
            SET att_status = 'In Progress';
        ELSE
            SET att_status = 'Absent';
        END IF;
    ELSEIF total_mins >= 480 THEN
        SET att_status = 'Full Day';
    ELSEIF total_mins >= 240 THEN
        SET att_status = 'Half Day';
    ELSE
        SET att_status = 'Early Out';
    END IF;
    SET NEW.attendance_status = att_status;
END$$

DELIMITER ;

-- ─────────────────────────────────────────────────────────────
-- SEED DATA
-- ─────────────────────────────────────────────────────────────

-- Admin  (password: Admin@1234)
INSERT INTO users (username, password_hash, role) VALUES
('admin', '$2y$12$Y5v3KLgM0OxT1q2W4RhXaePmzVnGqJkCt7sYb8UdXfHwIlNrO3K6e', 'admin');

-- Supervisor  (password: Super@1234)
INSERT INTO users (username, password_hash, role, province) VALUES
('supervisor1', '$2y$12$B8d5RMiP2QuV3s4Y6TjZcgRoaXpIsLmEv9uAd0WfZiKyNnQtR5M8g', 'supervisor', 'Cauayan');

-- Sample Intern  (password: Intern@1234)
INSERT INTO users (username, intern_id, password_hash, role, province) VALUES
('jdelacruz', '25-0001', '$2y$12$A9c4PLhN1PyU2r3X5SiYbfQnzWoHrKlDu8tZc9VeYgJxMmOsP4L7f', 'intern', 'Cauayan');

INSERT INTO intern_profiles (user_id, full_name, email, school, course, province, ojt_hours_required, status)
VALUES (3, 'Juan Dela Cruz', 'juan@example.com', 'ISU Cauayan', 'BSIT', 'Cauayan', 480, 'active');
