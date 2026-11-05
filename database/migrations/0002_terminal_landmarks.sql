CREATE TABLE IF NOT EXISTS terminal_details (
    terminal_id BIGINT UNSIGNED NOT NULL,
    landmark VARCHAR(255) NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (terminal_id),
    CONSTRAINT fk_terminal_details_terminal
        FOREIGN KEY (terminal_id) REFERENCES terminals (id) ON DELETE CASCADE
) ENGINE=InnoDB;
