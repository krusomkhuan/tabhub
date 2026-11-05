-- Run in phpMyAdmin after uploading the code that uses lowercase users.
-- Only drops an EMPTY uppercase Users table when both tables are distinct.
-- No change is made on Windows / case-insensitive database servers.
SET @can_drop_upper_users = (
  @@lower_case_table_names = 0
  AND EXISTS (SELECT 1 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND BINARY TABLE_NAME = BINARY 'Users' AND TABLE_TYPE = 'BASE TABLE')
  AND EXISTS (SELECT 1 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND BINARY TABLE_NAME = BINARY 'users' AND TABLE_TYPE = 'BASE TABLE')
);
SET @upper_users_count = NULL;
SET @check_upper_users = IF(@can_drop_upper_users,
  'SELECT COUNT(*) INTO @upper_users_count FROM `Users`',
  'SET @upper_users_count = NULL');
PREPARE check_upper_users FROM @check_upper_users;
EXECUTE check_upper_users;
DEALLOCATE PREPARE check_upper_users;

SET @drop_upper_users = IF(@can_drop_upper_users AND @upper_users_count = 0,
  'DROP TABLE `Users`',
  'SELECT ''Skipped: Users is not empty, tables are missing, or names are case-insensitive.'' AS result');
PREPARE drop_upper_users FROM @drop_upper_users;
EXECUTE drop_upper_users;
DEALLOCATE PREPARE drop_upper_users;
