CREATE TABLE IF NOT EXISTS drivers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(254) NOT NULL,
    phone VARCHAR(32) NOT NULL,
    license_number VARCHAR(64) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    verification_status ENUM(
        'pending_verification',
        'verified',
        'rejected'
    ) NOT NULL DEFAULT 'pending_verification',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_drivers_email (email),
    UNIQUE KEY uq_drivers_license_number (license_number)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS jeepney_profiles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    driver_id BIGINT UNSIGNED NOT NULL,
    plate_number VARCHAR(16) NULL,
    capacity INT UNSIGNED NULL,
    route_assignment VARCHAR(120) NULL,
    verification_status ENUM(
        'not_submitted',
        'pending_verification',
        'verified',
        'rejected'
    ) NOT NULL DEFAULT 'not_submitted',
    pending_plate_number VARCHAR(16) NULL,
    pending_capacity INT UNSIGNED NULL,
    pending_route_assignment VARCHAR(120) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_jeepney_profiles_driver (driver_id),
    UNIQUE KEY uq_jeepney_profiles_plate (plate_number),
    CONSTRAINT fk_jeepney_profiles_driver
        FOREIGN KEY (driver_id) REFERENCES drivers (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS routes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    route_name VARCHAR(120) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_routes_route_name (route_name),
    KEY idx_routes_active_name (is_active, route_name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS RouteStops (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    route_id BIGINT UNSIGNED NOT NULL,
    stop_sequence SMALLINT UNSIGNED NOT NULL,
    location_name VARCHAR(120) NOT NULL,
    area_label VARCHAR(120) NOT NULL,
    landmark VARCHAR(255) NOT NULL,
    stop_type ENUM('terminal', 'boarding_point', 'stop', 'destination') NOT NULL DEFAULT 'stop',
    implementation_note VARCHAR(500) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_route_stops_sequence (route_id, stop_sequence),
    CONSTRAINT fk_route_stops_route
        FOREIGN KEY (route_id) REFERENCES routes (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS admins (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(254) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admins_email (email)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS admin_registration_lock (
    id TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT chk_admin_registration_lock_singleton CHECK (id = 1)
) ENGINE=InnoDB;

INSERT IGNORE INTO admin_registration_lock (id) VALUES (1);

CREATE TABLE IF NOT EXISTS login_rate_limits (
    bucket_key CHAR(64) NOT NULL,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    window_started_at DATETIME NOT NULL,
    blocked_until DATETIME NULL,
    PRIMARY KEY (bucket_key)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(191) NOT NULL,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (version)
) ENGINE=InnoDB;
