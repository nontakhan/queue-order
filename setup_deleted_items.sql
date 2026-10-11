CREATE TABLE IF NOT EXISTS deleted_transfer_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    docno VARCHAR(255) NOT NULL,
    sub_id VARCHAR(255) NULL,
    cd_code VARCHAR(255) NOT NULL,
    customer_name TEXT NULL,
    cd_name TEXT NULL,
    location_code VARCHAR(255) NULL,
    location TEXT NULL,
    user_lname VARCHAR(255) NULL,
    snapshot_json LONGTEXT NOT NULL,
    deleted_by_user_id BIGINT UNSIGNED NULL,
    deleted_by_username VARCHAR(255) NULL,
    deleted_by_name VARCHAR(255) NULL,
    deleted_at DATETIME NOT NULL,
    INDEX idx_deleted_at (deleted_at, id),
    INDEX idx_deleted_source (docno, sub_id, cd_code),
    INDEX idx_deleted_location (location_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
