-- PC28 Bot Admin Database Schema (SQLite)
-- Run: sqlite3 admin.db < admin/src/schema.sql
--
-- NOTE: uses strftime('%s','now') instead of unixepoch()
-- to ensure cross-platform compatibility (Windows + Linux/macOS)

CREATE TABLE IF NOT EXISTS admins (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    username    TEXT    NOT NULL UNIQUE,
    password    TEXT    NOT NULL,
    nickname    TEXT    NOT NULL DEFAULT 'Admin',
    role        TEXT    NOT NULL DEFAULT 'admin',
    created_at  INTEGER NOT NULL DEFAULT (strftime('%s', 'now')),
    last_login  INTEGER DEFAULT 0,
    status      TEXT    NOT NULL DEFAULT 'active'
);

CREATE TABLE IF NOT EXISTS users (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    openid      TEXT    NOT NULL UNIQUE,
    nickname    TEXT    NOT NULL DEFAULT 'Player',
    balance     REAL    NOT NULL DEFAULT 0.00,
    total_bet   REAL    NOT NULL DEFAULT 0.00,
    total_win   REAL    NOT NULL DEFAULT 0.00,
    total_deposit REAL  NOT NULL DEFAULT 0.00,
    total_withdraw REAL NOT NULL DEFAULT 0.00,
    bet_count   INTEGER NOT NULL DEFAULT 0,
    last_bet    INTEGER DEFAULT 0,
    status      TEXT    NOT NULL DEFAULT 'active',
    created_at  INTEGER NOT NULL DEFAULT (strftime('%s', 'now')),
    updated_at  INTEGER NOT NULL DEFAULT (strftime('%s', 'now'))
);

CREATE TABLE IF NOT EXISTS bets (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,
    period      TEXT    NOT NULL,
    bet_type    TEXT    NOT NULL,
    bet_value   TEXT    NOT NULL,
    amount      REAL    NOT NULL,
    odds        REAL    NOT NULL,
    result      TEXT    NOT NULL DEFAULT 'pending',
    win_amount  REAL    NOT NULL DEFAULT 0.00,
    lottery_result TEXT,
    created_at  INTEGER NOT NULL DEFAULT (strftime('%s', 'now')),
    settled_at  INTEGER DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS deposits (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,
    amount      REAL    NOT NULL,
    method      TEXT    NOT NULL DEFAULT 'manual',
    status      TEXT    NOT NULL DEFAULT 'pending',
    note        TEXT,
    admin_id    INTEGER,
    created_at  INTEGER NOT NULL DEFAULT (strftime('%s', 'now')),
    processed_at INTEGER DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS withdrawals (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,
    amount      REAL    NOT NULL,
    method      TEXT    NOT NULL DEFAULT 'manual',
    bank_info   TEXT,
    status      TEXT    NOT NULL DEFAULT 'pending',
    note        TEXT,
    admin_id    INTEGER,
    created_at  INTEGER NOT NULL DEFAULT (strftime('%s', 'now')),
    processed_at INTEGER DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS lottery_history (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    period      TEXT    NOT NULL UNIQUE,
    numbers     TEXT    NOT NULL,
    total       INTEGER NOT NULL,
    size        TEXT,
    odd_even    TEXT,
    created_at  INTEGER NOT NULL DEFAULT (strftime('%s', 'now'))
);

CREATE TABLE IF NOT EXISTS cashback_log (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,
    period      TEXT    NOT NULL,
    bet_amount  REAL    NOT NULL,
    cashback    REAL    NOT NULL,
    created_at  INTEGER NOT NULL DEFAULT (strftime('%s', 'now')),
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS operations_log (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    admin_id    INTEGER NOT NULL,
    action      TEXT    NOT NULL,
    target_type TEXT,
    target_id   INTEGER,
    detail      TEXT,
    created_at  INTEGER NOT NULL DEFAULT (strftime('%s', 'now'))
);

CREATE INDEX IF NOT EXISTS idx_bets_user ON bets(user_id);
CREATE INDEX IF NOT EXISTS idx_bets_period ON bets(period);
CREATE INDEX IF NOT EXISTS idx_bets_created ON bets(created_at);
CREATE INDEX IF NOT EXISTS idx_deposits_user ON deposits(user_id);
CREATE INDEX IF NOT EXISTS idx_withdrawals_user ON withdrawals(user_id);
CREATE INDEX IF NOT EXISTS idx_users_openid ON users(openid);
