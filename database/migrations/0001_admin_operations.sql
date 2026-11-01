CREATE TABLE IF NOT EXISTS driver_admin_status (
    driver_id BIGINT UNSIGNED NOT NULL,
    account_status ENUM('active', 'suspended', 'deactivated') NOT NULL DEFAULT 'active',
    reason VARCHAR(500) NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (driver_id),
    CONSTRAINT fk_driver_admin_status_driver
        FOREIGN KEY (driver_id) REFERENCES drivers (id) ON DELETE CASCADE,
    CONSTRAINT fk_driver_admin_status_admin
        FOREIGN KEY (updated_by) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS jeepney_admin_status (
    jeepney_profile_id BIGINT UNSIGNED NOT NULL,
    operational_status ENUM('active', 'inactive', 'maintenance') NOT NULL DEFAULT 'inactive',
    updated_by BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (jeepney_profile_id),
    CONSTRAINT fk_jeepney_admin_status_profile
        FOREIGN KEY (jeepney_profile_id) REFERENCES jeepney_profiles (id) ON DELETE CASCADE,
    CONSTRAINT fk_jeepney_admin_status_admin
        FOREIGN KEY (updated_by) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO jeepney_admin_status (jeepney_profile_id, operational_status)
SELECT id, IF(verification_status = 'verified', 'active', 'inactive')
FROM jeepney_profiles;

CREATE TABLE IF NOT EXISTS terminals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    terminal_name VARCHAR(120) NOT NULL,
    municipality VARCHAR(100) NOT NULL,
    latitude DECIMAL(10, 7) NULL,
    longitude DECIMAL(10, 7) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_terminals_name_municipality (terminal_name, municipality),
    KEY idx_terminals_active (is_active, municipality)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS route_terminals (
    route_id BIGINT UNSIGNED NOT NULL,
    terminal_id BIGINT UNSIGNED NOT NULL,
    stop_order SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (route_id, terminal_id),
    UNIQUE KEY uq_route_terminals_order (route_id, stop_order),
    CONSTRAINT fk_route_terminals_route
        FOREIGN KEY (route_id) REFERENCES routes (id) ON DELETE CASCADE,
    CONSTRAINT fk_route_terminals_terminal
        FOREIGN KEY (terminal_id) REFERENCES terminals (id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS route_stop_coordinates (
    route_stop_id BIGINT UNSIGNED NOT NULL,
    latitude DECIMAL(10, 7) NOT NULL,
    longitude DECIMAL(10, 7) NOT NULL,
    PRIMARY KEY (route_stop_id),
    CONSTRAINT fk_route_stop_coordinates_stop
        FOREIGN KEY (route_stop_id) REFERENCES RouteStops (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS driver_documents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    driver_id BIGINT UNSIGNED NOT NULL,
    document_type VARCHAR(32) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    storage_key CHAR(64) NOT NULL,
    mime_type VARCHAR(80) NOT NULL,
    file_contents MEDIUMBLOB NULL,
    review_status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    review_note VARCHAR(500) NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_driver_documents_storage_key (storage_key),
    KEY idx_driver_documents_review (review_status, uploaded_at),
    KEY idx_driver_documents_driver (driver_id, uploaded_at),
    CONSTRAINT fk_driver_documents_driver
        FOREIGN KEY (driver_id) REFERENCES drivers (id) ON DELETE CASCADE,
    CONSTRAINT fk_driver_documents_admin
        FOREIGN KEY (reviewed_by) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS trips (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    driver_id BIGINT UNSIGNED NOT NULL,
    jeepney_profile_id BIGINT UNSIGNED NOT NULL,
    route_id BIGINT UNSIGNED NOT NULL,
    trip_status ENUM('active', 'completed', 'cancelled') NOT NULL DEFAULT 'active',
    started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at TIMESTAMP NULL,
    PRIMARY KEY (id),
    KEY idx_trips_status_started (trip_status, started_at),
    KEY idx_trips_driver_started (driver_id, started_at),
    CONSTRAINT fk_trips_driver
        FOREIGN KEY (driver_id) REFERENCES drivers (id) ON DELETE RESTRICT,
    CONSTRAINT fk_trips_jeepney
        FOREIGN KEY (jeepney_profile_id) REFERENCES jeepney_profiles (id) ON DELETE RESTRICT,
    CONSTRAINT fk_trips_route
        FOREIGN KEY (route_id) REFERENCES routes (id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS trip_locations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    trip_id BIGINT UNSIGNED NOT NULL,
    latitude DECIMAL(10, 7) NOT NULL,
    longitude DECIMAL(10, 7) NOT NULL,
    accuracy_m DECIMAL(8, 2) NULL,
    recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_trip_locations_latest (trip_id, recorded_at),
    CONSTRAINT fk_trip_locations_trip
        FOREIGN KEY (trip_id) REFERENCES trips (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS commuters (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(254) NOT NULL,
    phone VARCHAR(32) NULL,
    account_status ENUM('active', 'suspended', 'deactivated') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_commuters_email (email),
    KEY idx_commuters_status_created (account_status, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS admin_roles (
    admin_id BIGINT UNSIGNED NOT NULL,
    role ENUM('owner', 'admin', 'viewer') NOT NULL DEFAULT 'admin',
    account_status ENUM('active', 'deactivated') NOT NULL DEFAULT 'active',
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (admin_id),
    CONSTRAINT fk_admin_roles_admin
        FOREIGN KEY (admin_id) REFERENCES admins (id) ON DELETE CASCADE,
    CONSTRAINT fk_admin_roles_creator
        FOREIGN KEY (created_by) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO admin_roles (admin_id, role, account_status)
SELECT id, 'admin', 'active'
FROM admins;

UPDATE admin_roles
SET role = 'owner'
WHERE admin_id = (SELECT id FROM admins ORDER BY id LIMIT 1);

CREATE TABLE IF NOT EXISTS activity_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_id BIGINT UNSIGNED NULL,
    action VARCHAR(80) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id BIGINT UNSIGNED NULL,
    details VARCHAR(1000) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_activity_logs_created (created_at),
    KEY idx_activity_logs_entity (entity_type, entity_id),
    CONSTRAINT fk_activity_logs_admin
        FOREIGN KEY (admin_id) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(64) NOT NULL,
    setting_value VARCHAR(255) NOT NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key),
    CONSTRAINT fk_app_settings_admin
        FOREIGN KEY (updated_by) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
    ('map_refresh_seconds', '20');
