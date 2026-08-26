-- PC28 后台管理 - 数据库结构
-- 字符集：utf8mb4

SET NAMES utf8mb4;

-- ----------------------------
-- 管理员表（单管理员）
-- ----------------------------
DROP TABLE IF EXISTS `admin_user`;
CREATE TABLE `admin_user` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(64) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 默认管理员 admin / admin123（首次登录后请立即修改密码）
INSERT INTO `admin_user` (`username`, `password_hash`) VALUES
('admin', '$2y$10$N4aQvOYZ7Gd3J1jqfN3LPOmjCvIquZ7gYxqxqxqxqxqxqxqxqxqxu');

-- ----------------------------
-- 用户表（来自 Bot）
-- ----------------------------
DROP TABLE IF EXISTS `bot_users`;
CREATE TABLE `bot_users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uid` VARCHAR(64) NOT NULL COMMENT '群内用户ID',
  `nickname` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '昵称',
  `balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '余额',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1正常 0封禁',
  `total_recharge` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '累计充值',
  `total_withdraw` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '累计提现',
  `total_bet` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '累计下注',
  `total_rebate` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '累计反水',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- 充值表
-- ----------------------------
DROP TABLE IF EXISTS `bot_recharges`;
CREATE TABLE `bot_recharges` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `remark` VARCHAR(255) NOT NULL DEFAULT '',
  `operator` VARCHAR(64) NOT NULL DEFAULT 'system' COMMENT '操作人',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- 提现表
-- ----------------------------
DROP TABLE IF EXISTS `bot_withdraws`;
CREATE TABLE `bot_withdraws` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `remark` VARCHAR(255) NOT NULL DEFAULT '',
  `operator` VARCHAR(64) NOT NULL DEFAULT 'system',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- 下注记录
-- ----------------------------
DROP TABLE IF EXISTS `bot_bets`;
CREATE TABLE `bot_bets` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `uid` VARCHAR(64) NOT NULL,
  `issue` VARCHAR(32) NOT NULL COMMENT '期号',
  `bet_type` VARCHAR(16) NOT NULL COMMENT '玩法大类 dx/dd/dxdd/jd/jx/bz/sh/dz/lh/num',
  `content` VARCHAR(16) NOT NULL COMMENT '下注内容 大/小/13...',
  `amount` DECIMAL(12,2) NOT NULL COMMENT '下注金额',
  `odds` DECIMAL(6,2) NOT NULL COMMENT '赔率（含本金）',
  `payout` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '中奖赔付（含本金），0=未中',
  `status` TINYINT NOT NULL DEFAULT 0 COMMENT '0待开奖 1中 2未中',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `settled_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_issue` (`issue`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- 开奖历史
-- ----------------------------
DROP TABLE IF EXISTS `bot_lottery`;
CREATE TABLE `bot_lottery` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `issue` VARCHAR(32) NOT NULL COMMENT '期号',
  `number` VARCHAR(32) NOT NULL COMMENT '开奖号码 8+9+2=19',
  `sum` TINYINT NOT NULL COMMENT '和值',
  `settled` TINYINT NOT NULL DEFAULT 0 COMMENT '0未结算 1已结算',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_issue` (`issue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- 反水日志
-- ----------------------------
DROP TABLE IF EXISTS `bot_rebate_log`;
CREATE TABLE `bot_rebate_log` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `uid` VARCHAR(64) NOT NULL,
  `period` VARCHAR(16) NOT NULL COMMENT '日/周/月 yyyy-mm-dd / yyyy-Www / yyyy-mm',
  `turnover` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '流水',
  `bet_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '投注期数',
  `rebate_rate` DECIMAL(5,3) NOT NULL DEFAULT 0.6 COMMENT '返点率 %',
  `deduct_rate` DECIMAL(5,3) NOT NULL DEFAULT 0.000 COMMENT '扣除率 %',
  `rebate_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '反水金额',
  `remark` VARCHAR(255) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_period` (`period`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- 系统配置（赔率/限额）
-- ----------------------------
DROP TABLE IF EXISTS `bot_config`;
CREATE TABLE `bot_config` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `config_key` VARCHAR(64) NOT NULL,
  `config_value` TEXT NOT NULL,
  `description` VARCHAR(255) NOT NULL DEFAULT '',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_key` (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 默认配置
INSERT INTO `bot_config` (`config_key`, `config_value`, `description`) VALUES
('rebate_min_turnover', '1000', '反水最低流水'),
('rebate_min_count', '10', '反水最低期数'),
('rebate_rate', '0.6', '基础返点率 %'),
('rebate_deduct_opposite', '0.4', '对压/杀组合扣除率 %'),
('max_total_per_issue', '300000', '单期总下注封顶'),
('max_payout_per_issue', '1000000', '单期最大赔付封顶'),
('enable_rebate', '1', '是否启用反水 1/0');