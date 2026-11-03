-- Baloot 450 multiplayer persistence schema.
-- Apply manually after reviewing credentials and backup policy. Never run on deployment automatically.
CREATE TABLE IF NOT EXISTS baloot_rooms (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  room_code CHAR(6) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  owner_guest_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  status ENUM('lobby','active','finished','closed') NOT NULL DEFAULT 'lobby',
  phase VARCHAR(32) NOT NULL DEFAULT 'lobby',
  state_json LONGTEXT NOT NULL,
  version BIGINT UNSIGNED NOT NULL DEFAULT 0,
  qaid_enabled TINYINT(1) NOT NULL DEFAULT 1,
  last_activity_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_baloot_rooms_code (room_code),
  KEY ix_baloot_rooms_status_activity (status, last_activity_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS baloot_room_players (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  room_id BIGINT UNSIGNED NOT NULL,
  seat TINYINT UNSIGNED NOT NULL,
  guest_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  display_name VARCHAR(48) NOT NULL,
  is_bot TINYINT(1) NOT NULL DEFAULT 0,
  connected_at DATETIME(6) NULL,
  last_seen_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_room_seat (room_id, seat),
  UNIQUE KEY uq_room_guest (room_id, guest_id),
  KEY ix_player_guest (guest_id),
  CONSTRAINT fk_baloot_players_room FOREIGN KEY (room_id) REFERENCES baloot_rooms(id) ON DELETE CASCADE,
  CONSTRAINT chk_baloot_seat CHECK (seat BETWEEN 0 AND 3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS baloot_room_actions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  room_id BIGINT UNSIGNED NOT NULL,
  action_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  guest_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  action_type VARCHAR(32) NOT NULL,
  result_json TEXT NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_room_action (room_id, action_id),
  KEY ix_room_actions_created (room_id, created_at),
  CONSTRAINT fk_baloot_actions_room FOREIGN KEY (room_id) REFERENCES baloot_rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
