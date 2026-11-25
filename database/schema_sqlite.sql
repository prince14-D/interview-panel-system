-- SQLite schema mirror strictly for automated CLI integration testing
PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS departments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL UNIQUE,
    description TEXT,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    full_name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'panelist',
    job_title TEXT,
    department_id INTEGER REFERENCES departments(id) ON DELETE SET NULL,
    phone TEXT,
    is_active INTEGER NOT NULL DEFAULT 1,
    last_login_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS interview_venues (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    venue_name TEXT NOT NULL,
    building TEXT,
    room_number TEXT NOT NULL,
    address TEXT NOT NULL,
    capacity INTEGER NOT NULL DEFAULT 15,
    notes TEXT,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS interview_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_code TEXT NOT NULL UNIQUE,
    title TEXT NOT NULL,
    department_id INTEGER NOT NULL REFERENCES departments(id),
    job_title TEXT NOT NULL,
    job_description TEXT,
    interview_mode TEXT NOT NULL DEFAULT 'physical',
    interview_date TEXT NOT NULL,
    start_time TEXT NOT NULL,
    end_time TEXT NOT NULL,
    venue_id INTEGER REFERENCES interview_venues(id) ON DELETE SET NULL,
    venue_room_override TEXT,
    instructions TEXT,
    status TEXT NOT NULL DEFAULT 'scheduled',
    created_by INTEGER NOT NULL REFERENCES users(id),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS video_interview_rooms (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id INTEGER NOT NULL UNIQUE REFERENCES interview_sessions(id) ON DELETE CASCADE,
    provider TEXT NOT NULL DEFAULT 'jitsi',
    server_domain TEXT,
    room_name TEXT NOT NULL UNIQUE,
    room_passcode TEXT,
    panelist_access_token TEXT NOT NULL,
    waiting_room_enabled INTEGER NOT NULL DEFAULT 1,
    recording_enabled INTEGER NOT NULL DEFAULT 0,
    recording_consent_required INTEGER NOT NULL DEFAULT 1,
    recording_authorized_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    recording_authorized_at TEXT,
    meeting_status TEXT NOT NULL DEFAULT 'scheduled',
    started_at TEXT,
    ended_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS candidates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    application_number TEXT NOT NULL UNIQUE,
    full_name TEXT NOT NULL,
    email TEXT,
    phone TEXT,
    gender TEXT,
    highest_qualification TEXT,
    years_experience REAL DEFAULT 0.0,
    county TEXT,
    notes TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS session_candidates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id INTEGER NOT NULL REFERENCES interview_sessions(id) ON DELETE CASCADE,
    candidate_id INTEGER NOT NULL REFERENCES candidates(id) ON DELETE CASCADE,
    scheduled_time TEXT,
    interview_order INTEGER NOT NULL DEFAULT 1,
    interview_status TEXT NOT NULL DEFAULT 'scheduled',
    interview_started_at TEXT,
    interview_ended_at TEXT,
    virtual_invite_token TEXT UNIQUE,
    waiting_room_admitted INTEGER NOT NULL DEFAULT 0,
    recording_consent_given INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(session_id, candidate_id)
);

CREATE TABLE IF NOT EXISTS session_panelists (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id INTEGER NOT NULL REFERENCES interview_sessions(id) ON DELETE CASCADE,
    panelist_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    panel_role TEXT NOT NULL DEFAULT 'member',
    assigned_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(session_id, panelist_id)
);

CREATE TABLE IF NOT EXISTS candidate_panelist_assignments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id INTEGER NOT NULL REFERENCES interview_sessions(id) ON DELETE CASCADE,
    candidate_id INTEGER NOT NULL REFERENCES candidates(id) ON DELETE CASCADE,
    panelist_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    assigned_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(session_id, candidate_id, panelist_id)
);

CREATE TABLE IF NOT EXISTS candidate_attendance (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id INTEGER NOT NULL REFERENCES interview_sessions(id) ON DELETE CASCADE,
    candidate_id INTEGER NOT NULL REFERENCES candidates(id) ON DELETE CASCADE,
    scheduled_time TEXT,
    actual_arrival_time TEXT,
    attendance_status TEXT NOT NULL DEFAULT 'Absent',
    remarks TEXT,
    recorded_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(session_id, candidate_id)
);

CREATE TABLE IF NOT EXISTS panelist_attendance (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id INTEGER NOT NULL REFERENCES interview_sessions(id) ON DELETE CASCADE,
    panelist_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    attendance_status TEXT NOT NULL DEFAULT 'Absent',
    check_in_time TEXT,
    remarks TEXT,
    recorded_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(session_id, panelist_id)
);

CREATE TABLE IF NOT EXISTS scoring_criteria (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id INTEGER NOT NULL REFERENCES interview_sessions(id) ON DELETE CASCADE,
    criterion_name TEXT NOT NULL,
    description TEXT,
    max_marks REAL NOT NULL,
    display_order INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS panel_submissions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id INTEGER NOT NULL REFERENCES interview_sessions(id) ON DELETE CASCADE,
    candidate_id INTEGER NOT NULL REFERENCES candidates(id) ON DELETE CASCADE,
    panelist_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    total_score REAL NOT NULL DEFAULT 0.0,
    max_possible_score REAL NOT NULL DEFAULT 100.0,
    percentage_score REAL NOT NULL DEFAULT 0.0,
    recommendation TEXT,
    overall_comments TEXT,
    strengths TEXT,
    areas_for_improvement TEXT,
    status TEXT NOT NULL DEFAULT 'draft',
    submitted_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(session_id, candidate_id, panelist_id)
);

CREATE TABLE IF NOT EXISTS panel_scores (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    submission_id INTEGER NOT NULL REFERENCES panel_submissions(id) ON DELETE CASCADE,
    criterion_id INTEGER NOT NULL REFERENCES scoring_criteria(id) ON DELETE CASCADE,
    score REAL NOT NULL DEFAULT 0.0,
    remarks TEXT,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(submission_id, criterion_id)
);

CREATE TABLE IF NOT EXISTS interview_notes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id INTEGER NOT NULL REFERENCES interview_sessions(id) ON DELETE CASCADE,
    candidate_id INTEGER REFERENCES candidates(id) ON DELETE CASCADE,
    author_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    note_type TEXT NOT NULL DEFAULT 'general',
    content TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS result_releases (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id INTEGER NOT NULL UNIQUE REFERENCES interview_sessions(id) ON DELETE CASCADE,
    is_released INTEGER NOT NULL DEFAULT 0,
    released_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    released_at TEXT,
    release_notes TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    action TEXT NOT NULL,
    entity_type TEXT NOT NULL,
    entity_id INTEGER,
    details TEXT,
    ip_address TEXT,
    user_agent TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS live_scoreboards (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id INTEGER NOT NULL UNIQUE REFERENCES interview_sessions(id) ON DELETE CASCADE,
    access_token TEXT NOT NULL UNIQUE,
    is_enabled INTEGER NOT NULL DEFAULT 1,
    show_live_scores INTEGER NOT NULL DEFAULT 1,
    auto_refresh_seconds INTEGER NOT NULL DEFAULT 10,
    created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
