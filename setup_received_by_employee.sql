SET @column_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'transfer_data_from_mssql'
      AND COLUMN_NAME = 'received_by_employee'
);

SET @alter_sql := IF(
    @column_exists = 0,
    'ALTER TABLE transfer_data_from_mssql ADD COLUMN received_by_employee VARCHAR(255) NULL AFTER delivery_remark',
    'SELECT ''received_by_employee already exists'' AS message'
);

PREPARE alter_stmt FROM @alter_sql;
EXECUTE alter_stmt;
DEALLOCATE PREPARE alter_stmt;
