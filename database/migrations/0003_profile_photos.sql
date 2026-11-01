CREATE TABLE IF NOT EXISTS profile_photos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    driver_id BIGINT UNSIGNED NULL,
    admin_id BIGINT UNSIGNED NULL,
    mime_type ENUM('image/jpeg', 'image/png', 'image/webp') NOT NULL,
    image_data MEDIUMBLOB NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_profile_photos_driver (driver_id),
    UNIQUE KEY uq_profile_photos_admin (admin_id),
    CONSTRAINT chk_profile_photos_owner CHECK (
        (driver_id IS NULL AND admin_id IS NOT NULL) OR
        (driver_id IS NOT NULL AND admin_id IS NULL)
    ),
    CONSTRAINT fk_profile_photos_driver
        FOREIGN KEY (driver_id) REFERENCES drivers (id) ON DELETE CASCADE,
    CONSTRAINT fk_profile_photos_admin
        FOREIGN KEY (admin_id) REFERENCES admins (id) ON DELETE CASCADE
) ENGINE=InnoDB;
