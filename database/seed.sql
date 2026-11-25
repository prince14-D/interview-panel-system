-- ============================================================================
-- Ministry of Youth and Sports, Republic of Liberia
-- Initial Master Data Seed (Departments, Physical Venues, Initial Admin Account)
-- Default Admin Login: admin@mys.gov.lr / Admin@2026!
-- (Change password immediately after first login in production)
-- ============================================================================

INSERT IGNORE INTO `departments` (`code`, `name`, `description`) VALUES
('ADMIN-HR', 'Department of Administration & Human Resources', 'Oversees ministry administration, personnel recruitment, and civil service compliance.'),
('YOUTH-DEV', 'Department of Youth Development', 'Coordinates national youth empowerment, vocational programs, and county youth councils.'),
('SPORTS-DEV', 'Department of Sports Development', 'Manages national sports federations, athletic programs, and sports infrastructure.'),
('TVET-SKILLS', 'Department of Technical & Vocational Education (TVET)', 'Directs national youth vocational training centers and skills acquisition programs.'),
('PLAN-RES', 'Department of Planning, Research & Statistics', 'Leads policy formulation, monitoring & evaluation, and ICT/statistical systems.'),
('FIN-BUDG', 'Department of Finance & Budget', 'Manages public financial administration, procurement, and internal controls.');

INSERT IGNORE INTO `interview_venues` (`venue_name`, `building`, `room_number`, `address`, `capacity`, `notes`) VALUES
('MYS Main Conference Hall', 'Ministry Headquarters', 'Conference Room A (2nd Floor)', 'Samuel Kanyon Doe Sports Complex, Paynesville, Monrovia', 25, 'Primary in-person recruitment panel venue with waiting area.'),
('HR Recruitment Boardroom', 'Administration Wing', 'Room 104', 'Samuel Kanyon Doe Sports Complex, Paynesville, Monrovia', 12, 'Dedicated panel boardroom for technical and management interviews.'),
('Youth Development Briefing Room', 'Youth Affairs Annex', 'Room 202', 'Samuel Kanyon Doe Sports Complex, Paynesville, Monrovia', 15, 'Suitable for multi-panel physical interviews.');

-- Initial System Administrator Account (Password: Admin@2026!)
-- Hash generated via PHP password_hash('Admin@2026!', PASSWORD_BCRYPT)
INSERT IGNORE INTO `users` (`full_name`, `email`, `password_hash`, `role`, `job_title`, `department_id`, `phone`, `is_active`)
VALUES (
    'System Administrator',
    'admin@mys.gov.lr',
    '$2y$12$4fPUVIer7aYSaYRRSp..DeHvtLGXDApE/umCIhyJNEooklJXhgdxC',
    'admin',
    'Director of Human Resources & Recruitment',
    1,
    '+231-77-000-0001',
    1
);
