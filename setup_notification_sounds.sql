-- สร้างตาราง notification_sounds สำหรับเก็บเสียงแจ้งเตือนตาม location_code
CREATE TABLE IF NOT EXISTS `notification_sounds` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `location_code` VARCHAR(50) NOT NULL,
  `sound_file` VARCHAR(255) NOT NULL,
  `original_name` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_location_code` (`location_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
