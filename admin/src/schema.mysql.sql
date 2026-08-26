-- PC28 Bot Admin Database Schema (MySQL)

CREATE TABLE IF NOT EXISTS admins (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    username    VARCHAR(64) NOT NULL UNIQUE,
    password    VARCHAR(255) NOT NULL,
    nickname    VARCHAR(64) NOT NULL DEFAULT 'Admin',
    role        VARCHAR(32) NOT NULL DEFAULT 'admin',
    created_at  INT UNSIGNED NOT NULL DEFAULT UNIX_TIMESTAMP(),
    last_login  INT UNSIGNED DEFAULT 0,
    status      VARCHAR(16) NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    openid      VARCHAR(128) NOT NULL UNIQUE,
    nickname    VARCHAR(64) NOT NULL DEFAULT 'Player',
    balance     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_bet   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_win   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_deposit DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_withdraw DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    bet_count   INT UNSIGNED NOT NULL DEFAULT 0,
    last_bet    INT UNSIGNED DEFAULT 0,
    status      VARCHAR(16) NOT NULL DEFAULT 'active',
    created_at  INT UNSIGNED NOT NULL DEFAULT UNIX_TIMESTAMP(),
    updated_at  INT UNSIGNED NOT NULL DEFAULT UNIX_TIMESTAMP()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bets (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    period      VARCHAR(32) NOT NULL,
    bet_type    VARCHAR(32) NOT NULL,
    bet_value   VARCHAR(64) NOT NULL,
    amount      DECIMAL(10,2) NOT NULL,
    odds        DECIMAL(6,3) NOT NULL,
    result      VARCHAR(16) NOT NULL DEFAULT 'pending',
    win_amount  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    lottery_result VARCHAR(32),
    created_at  INT UNSIGNED NOT NULL DEFAULT UNIX_TIMESTAMP(),
    settled_at  INT UNSIGNED DEFAULT 0,
    INDEX idx_bets_user (user_id),
    INDEX idx_bets_period (period),
    INDEX idx_bets_created (created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS deposits (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    amount      DECIMAL(10,2) NOT NULL,
    method      VARCHAR(32) NOT NULL DEFAULT 'manual',
    status      VARCHAR(16) NOT NULL DEFAULT 'pending',
    note        TEXT,
    admin_id    INT,
    created_at  INT UNSIGNED NOT NULL DEFAULT UNIX_TIMESTAMP(),
    processed_at INT UNSIGNED DEFAULT 0,
    INDEX idx_deposits_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS withdrawals (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    amount      DECIMAL(10,2) NOT NULL,
    method      VARCHAR(32) NOT NULL DEFAULT 'manual',
    bank_info   TEXT,
    status      VARCHAR(16) NOT NULL DEFAULT 'pending',
    note        TEXT,
    admin_id    INT,
    created_at  INT UNSIGNED NOT NULL DEFAULT UNIX_TIMESTAMP(),
    processed_at INT UNSIGNED DEFAULT 0,
    INDEX idx_withdrawals_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lottery_history (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    period      VARCHAR(32) NOT NULL UNIQUE,
    numbers     VARCHAR(32) NOT NULL,
    total       TINYINT UNSIGNED NOT NULL,
    size        VARCHAR(8),
    odd_even    VARCHAR(8),
    created_at  INT UNSIGNED NOT NULL DEFAULT UNIX_TIMESTAMP()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cashback_log (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    period      VARCHAR(32) NOT NULL,
    bet_amount  DECIMAL(10,2) NOT NULL,
    cashback    DECIMAL(10,2) NOT NULL,
    created_at  INT UNSIGNED NOT NULL DEFAULT UNIX_TIMESTAMP(),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS operations_log (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    admin_id    INT NOT NULL,
    action      VARCHAR(64) NOT NULL,
    target_type VARCHAR(32),
    target_id   INT,
    detail      TEXT,
    created_at  INT UNSIGNED NOT NULL DEFAULT UNIX_TIMESTAMP()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
