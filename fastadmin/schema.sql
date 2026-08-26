-- ============================================================
-- PC28 机器人后台管理系统 - MySQL 数据库表
-- 导入方式：小皮面板 phpMyAdmin → 选择数据库 → 导入本文件
-- ============================================================

-- ----------------------------
-- 1. 用户表
-- ----------------------------
DROP TABLE IF EXISTS `fa_user`;
CREATE TABLE `fa_user` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `uid` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '群内用户ID',
  `nickname` VARCHAR(128) NOT NULL DEFAULT '' COMMENT '群内昵称',
  `balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '账户余额',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态: 1正常 0冻结',
  `remark` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '备注',
  `created_at` DATETIME DEFAULT NULL COMMENT '创建时间',
  `updated_at` DATETIME DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uid` (`uid`),
  KEY `idx_status` (`status`),
  KEY `idx_nickname` (`nickname`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户表';

-- ----------------------------
-- 2. 余额变动流水表
-- ----------------------------
DROP TABLE IF EXISTS `fa_balance_log`;
CREATE TABLE `fa_balance_log` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `uid` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '用户UID',
  `action` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '操作类型: recharge人工充值/withdraw人工提现/bet下注/settle结算/rebate返水',
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '变动金额(正数加/负数减)',
  `balance_before` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '变动前余额',
  `balance_after` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '变动后余额',
  `operator_id` INT NOT NULL DEFAULT 0 COMMENT '操作人ID: 0=系统/Bot',
  `operator_name` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '操作人昵称',
  `note` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '备注说明',
  `issue` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '关联期号(下注/结算/返水时填)',
  `created_at` DATETIME DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_uid` (`uid`),
  KEY `idx_action` (`action`),
  KEY `idx_issue` (`issue`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='余额变动流水表';

-- ----------------------------
-- 3. 下注记录表
-- ----------------------------
DROP TABLE IF EXISTS `fa_bet`;
CREATE TABLE `fa_bet` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `uid` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '用户UID',
  `issue` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '期号',
  `bet_type` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '下注类型: dx大小/dd单双/lh龙虎/jd极大/jx极小/bz豹子/sh顺子/dz对子/num特码',
  `bet_content` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '下注内容: 如 大/小/单/双/13/27等',
  `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '下注金额',
  `odds` DECIMAL(8,4) NOT NULL DEFAULT 1.0000 COMMENT '赔率(包含本金)',
  `status` TINYINT NOT NULL DEFAULT 0 COMMENT '状态: 0待结算 1赢 2输',
  `settle_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '结算金额(赢时=amount*odds)',
  `settled_at` DATETIME DEFAULT NULL COMMENT '结算时间',
  `created_at` DATETIME DEFAULT NULL COMMENT '下注时间',
  PRIMARY KEY (`id`),
  KEY `idx_uid` (`uid`),
  KEY `idx_issue` (`issue`),
  KEY `idx_status` (`status`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='下注记录表';

-- ----------------------------
-- 4. Bot API 配置表
-- ----------------------------
DROP TABLE IF EXISTS `fa_bot_config`;
CREATE TABLE `fa_bot_config` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `app_id` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '应用ID',
  `secret_key` VARCHAR(128) NOT NULL DEFAULT '' COMMENT 'API密钥',
  `name` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '应用名称',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态: 1启用 0禁用',
  `created_at` DATETIME DEFAULT NULL COMMENT '创建时间',
  `updated_at` DATETIME DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `app_id` (`app_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Bot API配置表';

-- ----------------------------
-- 5. 初始化 Bot 配置
--    secret_key 与 config/robot.config.json 中的 admin_api.secret_key 一致
-- ----------------------------
INSERT INTO `fa_bot_config` (`app_id`, `secret_key`, `name`, `status`, `created_at`, `updated_at`) VALUES
('pc28bot', 'test_secret_key_2026', 'PC28机器人', 1, NOW(), NOW());
