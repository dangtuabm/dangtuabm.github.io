-- ABM Second Brain — lược đồ MySQL (chạy một lần). Bản SQLite cho môi trường thử được tự tạo khi brain.auto_schema = true.
CREATE TABLE IF NOT EXISTS brain_users (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  email VARCHAR(120) NOT NULL UNIQUE,
  name VARCHAR(80) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS brain_tokens (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  user_id INTEGER NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  created_at BIGINT NOT NULL,
  expires_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS brain_spaces (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(80) NOT NULL,
  owner_id INTEGER NOT NULL,
  created_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS brain_members (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  space_id INTEGER NOT NULL,
  user_id INTEGER NOT NULL,
  role VARCHAR(20) NOT NULL,
  created_at BIGINT NOT NULL,
  UNIQUE (space_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS brain_notes (
  id VARCHAR(40) NOT NULL,
  space_id INTEGER NOT NULL,
  title VARCHAR(300) NOT NULL,
  body MEDIUMTEXT NOT NULL,
  path VARCHAR(300) NOT NULL DEFAULT '',
  level VARCHAR(10) NOT NULL DEFAULT 'xanh',
  author_id INTEGER NOT NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL,
  server_at BIGINT NOT NULL,
  deleted TINYINT NOT NULL DEFAULT 0,
  PRIMARY KEY (space_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX idx_brain_notes_sync ON brain_notes (space_id, server_at);
CREATE TABLE IF NOT EXISTS brain_audit (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  user_id INTEGER NULL,
  space_id INTEGER NULL,
  action VARCHAR(40) NOT NULL,
  target VARCHAR(190) NOT NULL DEFAULT '',
  detail VARCHAR(500) NOT NULL DEFAULT '',
  created_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX idx_brain_audit_space ON brain_audit (space_id, id);
CREATE TABLE IF NOT EXISTS brain_login_attempts (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  ip_hash CHAR(64) NOT NULL,
  created_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
