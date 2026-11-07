CREATE DATABASE IF NOT EXISTS feed_import_test;
GRANT ALL PRIVILEGES ON feed_import_test.* TO 'feed'@'%';

-- the consumer's own databases and a user that reaches nothing else
CREATE DATABASE IF NOT EXISTS price_history;
CREATE DATABASE IF NOT EXISTS price_history_test;
CREATE USER IF NOT EXISTS 'price_history'@'%' IDENTIFIED BY 'price_history';
GRANT ALL PRIVILEGES ON price_history.* TO 'price_history'@'%';
GRANT ALL PRIVILEGES ON price_history_test.* TO 'price_history'@'%';
