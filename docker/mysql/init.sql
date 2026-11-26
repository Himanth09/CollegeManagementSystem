-- Runs once, when the MySQL volume is first created.
-- Extra database for the integration/HTTP test suites (tests/config.test.php).
CREATE DATABASE IF NOT EXISTS cms_unittest CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON cms_unittest.* TO 'cms'@'%';
FLUSH PRIVILEGES;
