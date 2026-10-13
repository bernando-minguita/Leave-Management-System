-- ===========================================================================
-- LMS - Driver Leave & Vacation Management System
-- MySQL 8.x schema
--
-- Run with:  php tools/install.php
--   (mysql.exe is often not on PATH in XAMPP, so the installer uses PDO)
--
-- Conventions
--   * InnoDB, utf8mb4, application time zone Asia/Riyadh (UTC+3)
--   * soft deactivation (status columns) instead of deletes
--   * leave status is DERIVED from dates and stored only so it can be
--     indexed/filtered fast (see app/core/Status.php)
-- ===========================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------------
-- users (authentication)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username       VARCHAR(50)  NOT NULL,
    password_hash  VARCHAR(255) NOT NULL,
    full_name      VARCHAR(120) NOT NULL,
    role           VARCHAR(60)  NOT NULL DEFAULT 'viewer',
    status         ENUM('active','inactive') NOT NULL DEFAULT 'active',
    last_login_at  DATETIME     NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- branches (Dammam / Riyadh / Jeddah + extras)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS branches (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_name VARCHAR(100) NOT NULL,
    code        VARCHAR(20)  NOT NULL,
    status      ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_branches_name (branch_name),
    UNIQUE KEY uq_branches_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- agencies (manpower suppliers providing drivers)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS agencies (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    agency_name    VARCHAR(120) NOT NULL,
    code           VARCHAR(20)  NOT NULL,
    contact_person VARCHAR(120) NULL,
    mobile         VARCHAR(30)  NULL,
    email          VARCHAR(120) NULL,
    address        VARCHAR(255) NULL,
    remarks        TEXT         NULL,
    status         ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_agencies_name (agency_name),
    UNIQUE KEY uq_agencies_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- leave_types (classification only - never an entitlement)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS leave_types (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(80)  NOT NULL,
    description VARCHAR(255) NULL,
    status      ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_leave_types_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- drivers (address book / master data)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS drivers (
    id                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    badge_number             VARCHAR(30)  NOT NULL,
    driver_name              VARCHAR(120) NOT NULL,
    iqama_number             VARCHAR(30)  NOT NULL,
    passport_number          VARCHAR(30)  NULL,
    mobile_number            VARCHAR(30)  NULL,
    alternate_mobile         VARCHAR(30)  NULL,
    nationality              VARCHAR(60)  NULL,
    agency_id                INT UNSIGNED NULL,
    branch_id                INT UNSIGNED NULL,
    department               VARCHAR(100) NULL,
    position                 VARCHAR(100) NULL,
    joining_date             DATE         NULL,
    emergency_contact_name   VARCHAR(120) NULL,
    emergency_contact_number VARCHAR(30)  NULL,
    address                  VARCHAR(255) NULL,
    city                     VARCHAR(80)  NULL,
    remarks                  TEXT         NULL,
    -- employment status: slugs from the Settings list (employment_statuses),
    -- "active" drives every KPI.
    status                   VARCHAR(30)  NOT NULL DEFAULT 'active',
    -- operational status: slugs from Settings (operational_statuses), NULL =
    -- not recorded yet. Separate question from employment - a driver can be
    -- employed and still off the road (vacation, sick, suspended...).
    operational_status       VARCHAR(30)  NULL,
    -- Last day the driver actually worked, recorded when a driver is
    -- deactivated (Address Book -> Deactivate) or edited while their status
    -- is a leaving status. NULL = no end date on file, which is how every
    -- driver behaved before this column existed. Capacity Planning, the
    -- dashboard KPIs and the driver pickers count a driver with a date up to
    -- and INCLUDING that day - they are not excluded from the total driver
    -- count for dates on which they were still working. Reactivating clears
    -- it.
    last_working_date         DATE         NULL,
    created_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_drivers_badge (badge_number),
    UNIQUE KEY uq_drivers_iqama (iqama_number),
    KEY idx_drivers_name (driver_name),
    KEY idx_drivers_mobile (mobile_number),
    KEY idx_drivers_passport (passport_number),
    KEY idx_drivers_branch (branch_id, status),
    KEY idx_drivers_agency (agency_id, status),
    KEY idx_drivers_status (status),
    KEY idx_drivers_operational_status (operational_status),
    KEY idx_drivers_lwd (last_working_date),
    CONSTRAINT fk_drivers_agency FOREIGN KEY (agency_id) REFERENCES agencies (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_drivers_branch FOREIGN KEY (branch_id) REFERENCES branches (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- leave_records (the operational heart of the system)
--
-- status is DERIVED (app/core/Status.php) and stored only so it can be
-- indexed and filtered fast:
--   cancelled  set manually
--   not_returned  closed by management with a reason (terminal, no arrival date)
--   returned   actual_arrival_date IS NOT NULL
--   overdue    expected_arrival_date < TODAY AND actual_arrival_date IS NULL
--   planned    leave_start_date > TODAY
--   on_leave   leave_start_date <= TODAY AND actual_arrival_date IS NULL
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS leave_records (
    id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    driver_id             INT UNSIGNED NOT NULL,
    leave_type_id         INT UNSIGNED NOT NULL,
    -- Which application this record was applied for (NULL = recorded directly,
    -- or an imported/legacy row). One application produces at most one record;
    -- the application's leave_status follows this record's status rather than
    -- being typed by hand.
    -- the FK itself is declared AFTER leave_applications is created below.
    leave_application_id  INT UNSIGNED NULL,
    -- Snapshotted from the address book the moment the leave is recorded, so a
    -- later passport renewal or branch transfer cannot rewrite history.
    -- branch_id is a frozen copy of drivers.branch_id, NOT a live lookup.
    passport_number       VARCHAR(30)  NULL,
    branch_id             INT UNSIGNED NULL,
    last_working_date     DATE         NULL,
    leave_start_date      DATE         NOT NULL,
    ticket_date           DATE         NULL,
    expected_arrival_date DATE         NULL,
    actual_arrival_date   DATE         NULL,
    actual_arrival_time   TIME         NULL,
    destination           VARCHAR(120) NULL,
    reason                VARCHAR(255) NULL,
    remarks               VARCHAR(500) NULL,
    return_remarks        VARCHAR(500) NULL,
    cancelled_reason      VARCHAR(255) NULL,
    -- Closing a leave as a non-return. The four are written together, always:
    -- why, when the decision was taken, who took it, and the notes. Without
    -- them the status would be an unexplained gap in the leave history.
    not_returned_reason   VARCHAR(100) NULL,
    not_returned_date     DATE         NULL,
    not_returned_by       INT UNSIGNED NULL,
    not_returned_remarks  VARCHAR(500) NULL,
    status                ENUM('planned','on_leave','returned','overdue','cancelled','not_returned') NOT NULL DEFAULT 'planned',
    created_by            INT UNSIGNED NULL,
    updated_by            INT UNSIGNED NULL,
    created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_leave_application (leave_application_id),
    KEY idx_leave_status_expected (status, expected_arrival_date),
    KEY idx_leave_status_start (status, leave_start_date),
    KEY idx_leave_driver (driver_id, leave_start_date),
    KEY idx_leave_start (leave_start_date),
    KEY idx_leave_expected (expected_arrival_date),
    KEY idx_leave_actual (actual_arrival_date),
    KEY idx_leave_type (leave_type_id),
    KEY idx_leave_branch (branch_id),
    KEY idx_leave_created (created_at),
    KEY idx_leave_not_returned_reason (not_returned_reason),
    CONSTRAINT fk_leave_driver FOREIGN KEY (driver_id) REFERENCES drivers (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_leave_type FOREIGN KEY (leave_type_id) REFERENCES leave_types (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_leave_branch FOREIGN KEY (branch_id) REFERENCES branches (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_leave_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_leave_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_leave_not_returned_by FOREIGN KEY (not_returned_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT chk_leave_dates CHECK (actual_arrival_date IS NULL OR actual_arrival_date >= leave_start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- audit_logs (who changed what, when)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_logs (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED    NULL,
    action     VARCHAR(60)     NOT NULL,
    table_name VARCHAR(60)     NOT NULL,
    record_id  INT UNSIGNED    NULL,
    old_values JSON            NULL,
    new_values JSON            NULL,
    ip_address VARCHAR(45)     NULL,
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_table_record (table_name, record_id),
    KEY idx_audit_user (user_id),
    KEY idx_audit_created (created_at),
    KEY idx_audit_action (action),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- settings (key/value application settings)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    setting_key   VARCHAR(80)  NOT NULL,
    setting_value VARCHAR(500) NULL,
    label         VARCHAR(120) NULL,
    group_name    VARCHAR(60)  NOT NULL DEFAULT 'general',
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_settings_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* ---------------------------------------------------------------------------
 * Leave applications - a register of leave/vacation requests and the decision
 * taken on them. A leave record POINTS AT its application
 * (leave_records.leave_application_id), and this table's leave_status column
 * is kept in step with that record's status - an application with no record
 * keeps whatever was chosen on it, default "not started". Nothing here feeds
 * the dashboard, the calendar or the expected-returns panels on its own: only
 * a leave record does.
 *
 * "branch" is a snapshot taken when the application is recorded, exactly as on
 * leave_records, so transferring a driver later cannot rewrite history.
 * ------------------------------------------------------------------------- */
CREATE TABLE IF NOT EXISTS leave_applications (
    id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    driver_id             INT UNSIGNED NOT NULL,
    leave_type_id         INT UNSIGNED NOT NULL,
    branch_id             INT UNSIGNED NULL,
    application_date      DATE         NOT NULL,
    last_working_date     DATE         NULL,
    expected_arrival_date DATE         NULL,
    destination           VARCHAR(120) NULL,
    reason                VARCHAR(255) NULL,
    remarks               VARCHAR(500) NULL,
    status                ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    -- Whether the leave itself has begun - a separate axis from the approval
    -- decision above, so it gets its own column rather than a status value.
    leave_status          ENUM('not_started','on_leave','returned','not_returned','closed') NOT NULL DEFAULT 'not_started',
    created_by            INT UNSIGNED NULL,
    updated_by            INT UNSIGNED NULL,
    created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_app_status (status),
    KEY idx_app_leave_status (leave_status),
    KEY idx_app_driver (driver_id),
    KEY idx_app_branch (branch_id),
    KEY idx_app_applied (application_date),
    CONSTRAINT fk_app_driver FOREIGN KEY (driver_id) REFERENCES drivers (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_app_leave_type FOREIGN KEY (leave_type_id) REFERENCES leave_types (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_app_branch FOREIGN KEY (branch_id) REFERENCES branches (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_app_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_app_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- The link from a leave record to the application it was applied for. It has
-- to be declared HERE rather than inside leave_records above, because
-- schema.sql creates leave_records first and MySQL cannot reference a table
-- that does not exist yet. ON DELETE SET NULL: deleting an application
-- unlinks the record instead of destroying leave history.
--
-- Re-running schema.sql reports "Duplicate foreign key constraint" on this
-- statement - harmless, it means the constraint is already there.
-- ---------------------------------------------------------------------------
ALTER TABLE leave_records
    ADD CONSTRAINT fk_leave_application
    FOREIGN KEY (leave_application_id) REFERENCES leave_applications (id)
    ON DELETE SET NULL ON UPDATE CASCADE;

-- ---------------------------------------------------------------------------
-- Driver master-data governance.
--
-- driver_change_requests holds one field's proposed change with the reason
-- behind it and whoever decided it. driver_history is the append-only record
-- of how a driver's master data came to look like this: written on approval,
-- on a direct correction, on import and on activate/deactivate, never
-- updated or deleted from application code.
--
-- pending_key is a GENERATED column so "one open request per field" is
-- enforced by the database instead of by a check two requests could race
-- past. While status = 'pending' it holds "driver:field" and the unique index
-- bites; for every decided request it is NULL, so history keeps as many rows
-- for a field as it has decisions.
--
-- tools/migrate-driver-governance.php creates these same two tables and seeds
-- the baseline history for a database that predates the feature. This block
-- is what keeps a FRESH install on the same shape, per the rule that
-- schema.sql carries the final shape.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS driver_change_requests (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    driver_id      INT UNSIGNED NOT NULL,
    field_name     VARCHAR(60)  NOT NULL,
    old_value      TEXT         NULL,
    new_value      TEXT         NULL,
    reason         VARCHAR(500) NOT NULL,
    status         ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    requested_by   INT UNSIGNED NULL,
    requested_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_by    INT UNSIGNED NULL,
    reviewed_at    DATETIME     NULL,
    review_note    VARCHAR(500) NULL,
    effective_date DATE         NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    pending_key    VARCHAR(90)  GENERATED ALWAYS AS (IF(status = 'pending', CONCAT(driver_id, ':', field_name), NULL)) VIRTUAL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_dcr_pending (pending_key),
    KEY idx_dcr_driver (driver_id, status),
    KEY idx_dcr_status (status, created_at),
    KEY idx_dcr_requested_by (requested_by),
    CONSTRAINT fk_dcr_driver FOREIGN KEY (driver_id) REFERENCES drivers (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_dcr_requested_by FOREIGN KEY (requested_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_dcr_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS driver_history (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    driver_id      INT UNSIGNED NOT NULL,
    field_name     VARCHAR(60)  NOT NULL,
    old_value      TEXT         NULL,
    new_value      TEXT         NULL,
    reason         VARCHAR(500) NULL,
    source         ENUM('initial','approved_request','direct_correction','import','deactivation','reactivation') NOT NULL DEFAULT 'direct_correction',
    request_id     INT UNSIGNED NULL,
    effective_date DATE         NULL,
    changed_by     INT UNSIGNED NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_dh_driver (driver_id, created_at),
    KEY idx_dh_changed_by (changed_by),
    KEY idx_dh_request (request_id),
    CONSTRAINT fk_dh_driver FOREIGN KEY (driver_id) REFERENCES drivers (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_dh_changed_by FOREIGN KEY (changed_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_dh_request FOREIGN KEY (request_id) REFERENCES driver_change_requests (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;


