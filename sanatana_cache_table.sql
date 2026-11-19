-- Sanatana Dharma — answer cache table.
-- Optional: ask.php creates this table itself on first use (CREATE TABLE IF
-- NOT EXISTS), so this file is only needed if you prefer to create it
-- manually first. Run from the WordPress root with wp-cli:
--   $WP db import /path/to/sanatana_cache_table.sql
-- The book never changes, so cached answers never go stale. The cache key
-- is the normalized question + the answer model, so switching models
-- starts a fresh cache.
CREATE TABLE IF NOT EXISTS l9huvwhjjmwprag_cache_sanatana (
  qhash CHAR(64) NOT NULL PRIMARY KEY,
  question VARCHAR(500) NOT NULL,
  model VARCHAR(100) NOT NULL,
  answer MEDIUMTEXT NOT NULL,
  passages MEDIUMTEXT NOT NULL,
  hits INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_hit_at TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
