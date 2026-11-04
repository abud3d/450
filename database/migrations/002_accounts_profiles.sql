-- Add player accounts, password recovery, profile statistics and completed match ledger.
-- Apply once to the existing Baloot 450 database. Existing room tables/data are untouched.
CREATE TABLE IF NOT EXISTS baloot_users (
  uid CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  display_name VARCHAR(40) NOT NULL,
  guest_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  verified TINYINT(1) NOT NULL DEFAULT 0,
  verify_token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  verify_expires DATETIME NULL,
  avatar VARCHAR(16) NOT NULL DEFAULT '🃏',
  bio VARCHAR(120) NOT NULL DEFAULT '',
  points BIGINT UNSIGNED NOT NULL DEFAULT 0,
  matches INT UNSIGNED NOT NULL DEFAULT 0,
  wins INT UNSIGNED NOT NULL DEFAULT 0,
  losses INT UNSIGNED NOT NULL DEFAULT 0,
  draws INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (uid),
  UNIQUE KEY uq_baloot_users_email (email),
  UNIQUE KEY uq_baloot_users_guest (guest_id),
  KEY ix_baloot_users_verify (verify_token_hash, verify_expires)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS baloot_user_sessions (
  token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  uid CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  csrf CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (token_hash),
  KEY ix_baloot_sessions_user (uid),
  KEY ix_baloot_sessions_expiry (expires_at),
  CONSTRAINT fk_baloot_sessions_user FOREIGN KEY (uid) REFERENCES baloot_users(uid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS baloot_password_resets (
  token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  uid CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (token_hash),
  KEY ix_baloot_resets_user (uid),
  KEY ix_baloot_resets_expiry (expires_at),
  CONSTRAINT fk_baloot_resets_user FOREIGN KEY (uid) REFERENCES baloot_users(uid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS baloot_match_results (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  match_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  room_id BIGINT UNSIGNED NOT NULL,
  uid CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  guest_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  team TINYINT UNSIGNED NOT NULL,
  final_score INT UNSIGNED NOT NULL DEFAULT 0,
  outcome ENUM('win','loss','draw') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_baloot_match_user (match_id,uid),
  KEY ix_baloot_match_user_date (uid,created_at),
  CONSTRAINT fk_baloot_match_room FOREIGN KEY (room_id) REFERENCES baloot_rooms(id) ON DELETE CASCADE,
  CONSTRAINT fk_baloot_match_user FOREIGN KEY (uid) REFERENCES baloot_users(uid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
