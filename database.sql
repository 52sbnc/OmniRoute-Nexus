-- =============================================================
--  天枢 · 多级智能导流系统  数据库结构（手动导入）
--  MySQL 5.7+ / 8.0   utf8mb4
--
--  导入方式：
--    1) 先创建数据库：CREATE DATABASE `daoliu` DEFAULT CHARACTER SET utf8mb4;
--    2) 导入本文件：mysql -uroot -p daoliu < database.sql
--       或在宝塔 / Navicat / phpMyAdmin 中直接导入
--    3) 默认管理员：admin / admin888（请登录后台后立即修改）
-- =============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------
-- 层级配置
-- ------------------------------
DROP TABLE IF EXISTS `layers`;
CREATE TABLE `layers` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`           VARCHAR(50)  NOT NULL COMMENT '层级名称',
  `sort`           INT          NOT NULL DEFAULT 0 COMMENT '排序',
  `active_count`   INT          NOT NULL DEFAULT 1 COMMENT '同时使用数量',
  `rotate_days`    INT          NOT NULL DEFAULT 3 COMMENT '轮换周期(天)',
  `observe_days`   INT          NOT NULL DEFAULT 3 COMMENT '观察期(天)',
  `disable_days`   INT          NOT NULL DEFAULT 30 COMMENT '禁用周期(天)',
  `strategy`       VARCHAR(20)  NOT NULL DEFAULT 'random' COMMENT 'fixed/random/weight/poll',
  `fallback_url`   VARCHAR(255) NOT NULL DEFAULT '' COMMENT '本层兜底地址',
  `check_enabled`  TINYINT      NOT NULL DEFAULT 1 COMMENT '实时检测开关',
  `check_interval` INT          NOT NULL DEFAULT 60 COMMENT '检测频率(秒)',
  `check_timeout`  INT          NOT NULL DEFAULT 5 COMMENT '超时(秒)',
  `check_path`     VARCHAR(120) NOT NULL DEFAULT '/jump.php?health=1' COMMENT '检测路径',
  `check_keyword`  VARCHAR(255) NOT NULL DEFAULT '' COMMENT '页面特征(可选)',
  `allow_status`   VARCHAR(60)  NOT NULL DEFAULT '200,301,302' COMMENT '允许的HTTP状态码',
  `is_final`       TINYINT      NOT NULL DEFAULT 0 COMMENT '是否最终层',
  `remark`         VARCHAR(255) NOT NULL DEFAULT '',
  `created_at`     DATETIME     NULL,
  `updated_at`     DATETIME     NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sort` (`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='层级配置';

-- ------------------------------
-- 域名池
-- ------------------------------
DROP TABLE IF EXISTS `pools`;
CREATE TABLE `pools` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `layer_id`   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '所属层级',
  `name`       VARCHAR(60)  NOT NULL COMMENT '池名称',
  `strategy`   VARCHAR(20)  NOT NULL DEFAULT 'random' COMMENT '选取策略',
  `remark`     VARCHAR(255) NOT NULL DEFAULT '',
  `created_at` DATETIME     NULL,
  `updated_at` DATETIME     NULL,
  PRIMARY KEY (`id`),
  KEY `idx_layer` (`layer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='域名池';

-- ------------------------------
-- 域名表
-- ------------------------------
DROP TABLE IF EXISTS `domains`;
CREATE TABLE `domains` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `domain`         VARCHAR(191) NOT NULL COMMENT '域名(不含协议)',
  `layer_id`       INT UNSIGNED NOT NULL DEFAULT 0,
  `pool_id`        INT UNSIGNED NOT NULL DEFAULT 0,
  `status`         VARCHAR(20)  NOT NULL DEFAULT 'pending' COMMENT 'pending/active/observing/review/standby/disabled/failed',
  `template_id`    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '海报模板 id（第 1 层入口域名使用）',
  `role`           VARCHAR(10)  NOT NULL DEFAULT '' COMMENT 'main/backup (第2层)',
  `weight`         INT          NOT NULL DEFAULT 100 COMMENT '权重',
  `sort`           INT          NOT NULL DEFAULT 0,
  `first_active_at`      DATETIME NULL COMMENT '首次投入使用的时刻',
  `current_active_at`    DATETIME NULL COMMENT '本次投入使用的时刻',
  `observe_at`           DATETIME NULL COMMENT '进入观察期的时刻',
  `total_active_seconds` BIGINT   NOT NULL DEFAULT 0 COMMENT '累计在网时长(秒)',
  `expire_at`            DATETIME NULL COMMENT '禁用时间点',
  `last_check_at`        DATETIME NULL,
  `last_check_result`    VARCHAR(10) NOT NULL DEFAULT '' COMMENT 'ok/fail',
  `last_check_msg`       VARCHAR(255) NOT NULL DEFAULT '',
  `resolved_ip`          VARCHAR(64) NOT NULL DEFAULT '' COMMENT '最近一次 DNS 解析出的 IP',
  `response_ms`          INT NOT NULL DEFAULT 0,
  `fail_count`           INT NOT NULL DEFAULT 0,
  `ok_count`             INT NOT NULL DEFAULT 0,
  `remark`         VARCHAR(255) NOT NULL DEFAULT '',
  `created_at`     DATETIME     NULL,
  `updated_at`     DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_domain` (`domain`),
  KEY `idx_layer_status` (`layer_id`, `status`),
  KEY `idx_pool` (`pool_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='域名表';

-- ------------------------------
-- 域名状态变更日志
-- ------------------------------
DROP TABLE IF EXISTS `domain_logs`;
CREATE TABLE `domain_logs` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `domain_id`  INT UNSIGNED NOT NULL DEFAULT 0,
  `domain`     VARCHAR(191) NOT NULL DEFAULT '',
  `layer_id`   INT UNSIGNED NOT NULL DEFAULT 0,
  `from_status` VARCHAR(20) NOT NULL DEFAULT '',
  `to_status`  VARCHAR(20)  NOT NULL DEFAULT '',
  `action`     VARCHAR(40)  NOT NULL DEFAULT '' COMMENT '轮换/故障替换/主备切换/手工',
  `message`    VARCHAR(255) NOT NULL DEFAULT '',
  `created_at` DATETIME     NULL,
  PRIMARY KEY (`id`),
  KEY `idx_domain` (`domain_id`),
  KEY `idx_time` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='域名状态变更日志';

-- ------------------------------
-- 跳转日志
-- ------------------------------
DROP TABLE IF EXISTS `jump_logs`;
CREATE TABLE `jump_logs` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `layer`      TINYINT      NOT NULL DEFAULT 0,
  `host`       VARCHAR(191) NOT NULL DEFAULT '',
  `target`     VARCHAR(500) NOT NULL DEFAULT '',
  `ip`         VARCHAR(64)  NOT NULL DEFAULT '',
  `flow`       VARCHAR(32)  NOT NULL DEFAULT '' COMMENT '访问流程标识（一次访问只记一次）',
  `channel_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '渠道 id（入口层域名所属渠道）',
  `ua`         VARCHAR(255) NOT NULL DEFAULT '',
  `result`     VARCHAR(20)  NOT NULL DEFAULT 'ok' COMMENT 'ok/fallback/blocked',
  `cost_ms`    INT          NOT NULL DEFAULT 0,
  `created_at` DATETIME     NULL,
  PRIMARY KEY (`id`),
  KEY `idx_layer_time` (`layer`, `created_at`),
  KEY `idx_flow` (`flow`),
  KEY `idx_channel_time` (`channel_id`, `created_at`),
  KEY `idx_time` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='跳转日志';

-- ------------------------------
-- 检测日志
-- ------------------------------
DROP TABLE IF EXISTS `check_logs`;
CREATE TABLE `check_logs` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `domain_id`   INT UNSIGNED NOT NULL DEFAULT 0,
  `domain`      VARCHAR(191) NOT NULL DEFAULT '',
  `layer_id`    INT UNSIGNED NOT NULL DEFAULT 0,
  `result`      VARCHAR(10)  NOT NULL DEFAULT 'ok' COMMENT 'ok/fail',
  `dns_ok`      TINYINT      NOT NULL DEFAULT 0,
  `resolved_ip` VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '解析出的 IP',
  `http_code`   INT          NOT NULL DEFAULT 0,
  `ssl_ok`      TINYINT      NOT NULL DEFAULT 0,
  `keyword_ok`  TINYINT      NOT NULL DEFAULT 0,
  `response_ms` INT          NOT NULL DEFAULT 0,
  `message`     VARCHAR(255) NOT NULL DEFAULT '',
  `created_at`  DATETIME     NULL,
  PRIMARY KEY (`id`),
  KEY `idx_domain_time` (`domain_id`, `created_at`),
  KEY `idx_time` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='检测日志';

-- ------------------------------
-- 计划任务
-- ------------------------------
DROP TABLE IF EXISTS `tasks`;
CREATE TABLE `tasks` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`       VARCHAR(40)  NOT NULL COMMENT '任务标识',
  `name`       VARCHAR(60)  NOT NULL,
  `interval_sec` INT        NOT NULL DEFAULT 300 COMMENT '执行间隔(秒)',
  `enabled`    TINYINT      NOT NULL DEFAULT 1,
  `last_run_at` DATETIME    NULL,
  `last_result` VARCHAR(10) NOT NULL DEFAULT '',
  `last_message` VARCHAR(500) NOT NULL DEFAULT '',
  `last_ms`    INT          NOT NULL DEFAULT 0,
  `remark`     VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='计划任务';

-- ------------------------------
-- 任务日志
-- ------------------------------
DROP TABLE IF EXISTS `task_logs`;
CREATE TABLE `task_logs` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_code`  VARCHAR(40)  NOT NULL DEFAULT '',
  `result`     VARCHAR(10)  NOT NULL DEFAULT 'ok',
  `message`    VARCHAR(1000) NOT NULL DEFAULT '',
  `duration_ms` INT         NOT NULL DEFAULT 0,
  `created_at` DATETIME     NULL,
  PRIMARY KEY (`id`),
  KEY `idx_code_time` (`task_code`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='任务日志';

-- ------------------------------
-- 通知记录
-- ------------------------------
DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `channel`    VARCHAR(20)  NOT NULL DEFAULT '',
  `event`      VARCHAR(40)  NOT NULL DEFAULT '',
  `title`      VARCHAR(120) NOT NULL DEFAULT '',
  `content`    TEXT         NULL,
  `status`     VARCHAR(10)  NOT NULL DEFAULT 'ok' COMMENT 'ok/fail',
  `error`      VARCHAR(500) NOT NULL DEFAULT '',
  `created_at` DATETIME     NULL,
  PRIMARY KEY (`id`),
  KEY `idx_time` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='通知记录';

-- ------------------------------
-- 每日统计汇总（跳转日志被清理后依然保留）
-- ------------------------------
DROP TABLE IF EXISTS `stats_daily`;
CREATE TABLE `stats_daily` (
  `d`          DATE NOT NULL COMMENT '日期',
  `jumps`      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '当日跳转量（三层累计）',
  `flows`      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '当日跳转次数（一次访问只记一次）',
  `visitors`   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '当日独立访客(IP)数',
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`d`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='每日统计汇总';

-- ------------------------------
-- 渠道每日统计汇总（按渠道维度的跳转次数/访客，日志清理后依然保留）
-- ------------------------------
DROP TABLE IF EXISTS `stats_channel_daily`;
CREATE TABLE `stats_channel_daily` (
  `d`          DATE NOT NULL COMMENT '日期',
  `channel_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '渠道 id',
  `flows`      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '当日跳转次数（一次访问只记一次）',
  `visitors`   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '当日独立访客(IP)数',
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`d`,`channel_id`),
  KEY `idx_channel` (`channel_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='渠道每日统计汇总';

-- ------------------------------
-- 渠道（谷歌 / Facebook 等，模板归属渠道，域名用模板即归属该渠道）
-- ------------------------------
DROP TABLE IF EXISTS `channels`;
CREATE TABLE `channels` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(60)  NOT NULL COMMENT '渠道名称',
  `sort`       INT          NOT NULL DEFAULT 0,
  `remark`     VARCHAR(255) NOT NULL DEFAULT '',
  `created_at` DATETIME     NULL,
  `updated_at` DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='渠道';

-- ------------------------------
-- 海报模板（第 1 层入口域名对外展示的页面）
-- 内容中的 {{link}} 会被替换为中转层地址
-- ------------------------------
DROP TABLE IF EXISTS `templates`;
CREATE TABLE `templates` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(60)  NOT NULL DEFAULT '' COMMENT '模板名称',
  `channel_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '所属渠道 id',
  `content`    LONGTEXT     NULL COMMENT 'HTML 内容，{{link}} 为跳转地址占位符',
  `is_default` TINYINT      NOT NULL DEFAULT 0 COMMENT '1=默认模板（域名未指定时使用）',
  `remark`     VARCHAR(255) NOT NULL DEFAULT '',
  `created_at` DATETIME     NULL,
  `updated_at` DATETIME     NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='海报模板';

-- ------------------------------
-- 系统设置
-- ------------------------------
DROP TABLE IF EXISTS `settings`;
CREATE TABLE `settings` (
  `k` VARCHAR(64) NOT NULL,
  `v` TEXT        NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统设置';

-- ------------------------------
-- 管理员
-- ------------------------------
DROP TABLE IF EXISTS `admins`;
CREATE TABLE `admins` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(60)  NOT NULL,
  `password`      VARCHAR(255) NOT NULL COMMENT '登录密码（明文存储）',
  `last_login_at` DATETIME     NULL,
  `last_login_ip` VARCHAR(64)  NOT NULL DEFAULT '',
  `created_at`    DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管理员';

-- ------------------------------
-- 后台操作日志
-- ------------------------------
DROP TABLE IF EXISTS `operation_logs`;
CREATE TABLE `operation_logs` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id`   INT UNSIGNED NOT NULL DEFAULT 0,
  `username`   VARCHAR(60)  NOT NULL DEFAULT '',
  `action`     VARCHAR(60)  NOT NULL DEFAULT '',
  `detail`     VARCHAR(500) NOT NULL DEFAULT '',
  `ip`         VARCHAR(64)  NOT NULL DEFAULT '',
  `created_at` DATETIME     NULL,
  PRIMARY KEY (`id`),
  KEY `idx_time` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='后台操作日志';

-- =============================================================
--  初始数据
-- =============================================================
INSERT INTO `layers`
(`id`,`name`,`sort`,`active_count`,`rotate_days`,`observe_days`,`disable_days`,`strategy`,`check_enabled`,`check_interval`,`check_timeout`,`check_path`,`is_final`,`remark`,`created_at`,`updated_at`) VALUES
(1,'第 1 层 · 入口层',1,1,0,0,0,'random',1,300,5,'/jump.php?health=1',0,'对外展示海报页；不限在线数量，每个域名可独立启用 / 关闭，人工指定，不自动轮换；域名按所用模板归属渠道',NOW(),NOW()),
(2,'第 2 层 · 中转层',2,1,0,0,0,'fixed',1,300,5,'/jump.php?health=1',0,'主备双活，主失败自动切备',NOW(),NOW()),
(3,'第 3 层 · 落地层',3,3,0,0,0,'random',1,300,5,'/jump.php?health=1',0,'同时在线 3 个，故障即换',NOW(),NOW()),
(4,'第 4 层 · 最终落地站点',4,1,0,0,0,'fixed',0,300,5,'',1,'仅 1 个 URL，后台随时修改',NOW(),NOW());

INSERT INTO `pools` (`layer_id`,`name`,`strategy`,`remark`,`created_at`,`updated_at`) VALUES
(1,'入口域名池 A','random','第 1 层主池',NOW(),NOW()),
(2,'中转域名池','fixed','第 2 层主备池',NOW(),NOW()),
(3,'落地域名池','random','第 3 层落地池',NOW(),NOW());

INSERT INTO `tasks` (`code`,`name`,`interval_sec`,`enabled`,`remark`) VALUES
('check','实时检测 · 故障即换',300,1,'检测第 1/2/3 层正在使用域名的 DNS 解析，失败标记异常并通知'),
('rotate','域名使用期流转',300,1,'中转层/落地层使用期满转已到期、满保留期恢复待使用并自动补位；入口层人工启用/关闭，不自动流转'),
('cleanup','日志清理与备份',86400,1,'每天首次执行时清理过期日志并备份数据库');

INSERT INTO `settings` (`k`,`v`) VALUES
('site_name','天枢 · 多级智能导流系统'),
('final_url','https://example.com/'),
('fallback_url','https://example.com/'),
('jump_type','meta'),
('force_https','0'),
('jump_scheme','auto'),
('ip_limit','0'),
('ua_blacklist',''),
('log_keep_days','30'),
('notify_enabled','0'),
('notify_events','domain_fail,domain_replace,main_switch,expire,review,rotate,task_error'),
('notify_email_enabled','0'),
('notify_email_to',''),
('notify_telegram_enabled','0'),
('notify_telegram_bot',''),
('notify_telegram_chat',''),
('notify_wecom_enabled','0'),
('notify_wecom_webhook',''),
('notify_dingtalk_enabled','0'),
('notify_dingtalk_webhook',''),
('notify_webhook_enabled','0'),
('notify_webhook_url','');

-- 默认管理员：admin / admin888（密码明文存储）
INSERT INTO `admins` (`username`,`password`,`created_at`) VALUES
('admin','admin888',NOW());

-- 默认海报模板（内容取自 海报模板1.html，按钮跳转地址处为 {{link}} 占位符）
INSERT INTO `templates` (`id`,`name`,`content`,`is_default`,`remark`,`created_at`,`updated_at`) VALUES
(1,'海报模板 1','
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Hızlı ve Esnek Finansman Çözümleri</title>
<style>
  * {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
  }

  body {
    font-family: ''Segoe UI'', ''Roboto'', ''Helvetica Neue'', Arial, sans-serif;
    background: #f0f2f5;
    display: flex;
    justify-content: center;
    align-items: flex-start;
    min-height: 100vh;
    padding: 20px 10px;
  }

  .poster {
    width: 100%;
    max-width: 420px;
    background: linear-gradient(180deg, #f9c74f 0%, #f9c74f 55%, #ffffff 55%);
    border-radius: 18px;
    overflow: hidden;
    box-shadow: 0 12px 40px rgba(0,0,0,0.18);
    position: relative;
  }

  /* Üst sarı alan */
  .top-section {
    padding: 28px 24px 20px;
    text-align: center;
    position: relative;
    z-index: 2;
  }

  .logo {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    margin-bottom: 22px;
  }

  .logo img {
    height: 42px;
    width: auto;
  }

  .main-title {
    font-size: 30px;
    font-weight: 800;
    color: #0d2b4a;
    line-height: 1.2;
    margin-bottom: 14px;
    text-transform: uppercase;
    letter-spacing: -0.5px;
  }

  .highlight {
    color: #c0392b;
  }

  .subtitle {
    font-size: 15px;
    color: #2c3e50;
    line-height: 1.6;
    margin-bottom: 22px;
    font-weight: 500;
    padding: 0 8px;
  }

  .cta-button {
    display: inline-block;
    background: #ffffff;
    color: #0d2b4a;
    font-size: 18px;
    font-weight: 700;
    padding: 14px 40px;
    border-radius: 50px;
    text-decoration: none;
    box-shadow: 0 6px 18px rgba(0,0,0,0.15);
    transition: all 0.25s ease;
    border: 2px solid #0d2b4a;
  }

  .cta-button:hover {
    background: #0d2b4a;
    color: #fff;
    transform: translateY(-2px);
  }

  .website {
    margin-top: 14px;
    font-size: 14px;
    color: #1a3a5c;
    font-weight: 600;
    letter-spacing: 0.3px;
  }

  /* Dalgalı geçiş */
  .wave {
    position: absolute;
    bottom: 0;
    left: 0;
    width: 100%;
    height: 70px;
    z-index: 1;
  }

  .wave svg {
    display: block;
    width: 100%;
    height: 100%;
  }

  /* Alt fotoğraf alanı */
  .photo-section {
    background: #fff;
    padding: 10px 0 0;
    position: relative;
  }

  .photo-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 4px;
    padding: 0 4px 4px;
  }

  .photo-grid img {
    width: 100%;
    height: 160px;
    object-fit: cover;
    border-radius: 8px;
  }

  .photo-full {
    width: 100%;
    height: 190px;
    object-fit: cover;
    display: block;
  }

  .badge {
    position: absolute;
    top: -18px;
    right: 18px;
    background: #27ae60;
    color: white;
    font-size: 13px;
    font-weight: 700;
    padding: 6px 14px;
    border-radius: 20px;
    box-shadow: 0 4px 12px rgba(39,174,96,0.4);
    z-index: 5;
  }

  /* Legal disclaimer footer */
  .disclaimer {
    padding: 12px 16px;
    background-color: #f8f9fa;
    font-size: 10px;
    color: #7f8c8d;
    text-align: center;
    line-height: 1.4;
    border-top: 1px solid #edf2f7;
  }

  /* Responsive */
  @media (max-width: 480px) {
    .main-title {
      font-size: 26px;
    }
    .subtitle {
      font-size: 14px;
    }
    .cta-button {
      font-size: 16px;
      padding: 12px 32px;
    }
    .photo-grid img {
      height: 140px;
    }
  }

  @media (min-width: 500px) {
    .poster {
      max-width: 440px;
    }
  }
</style>
</head>
<body>

<div class="poster">
  <!-- Üst kısım -->
  <div class="top-section">

    <h1 class="main-title">
      NAKİT İHTİYACINIZA<br>
      <span class="highlight">HIZLI ÇÖZÜM!</span>
    </h1>

    <p class="subtitle">
      Kefilsiz ve Evraksız Başvuru<br>
      Uygun Faiz Oranları • Esnek Ödeme<br>
      7/24 Dijital Başvuru İmkânı<br>
      Güvenli ve Şeffaf Finansman
    </p>

    <a href="{{link}}" class="cta-button" target="_blank" rel="noopener">
      HEMEN BAŞVUR
    </a>
  </div>    

  <!-- Dalga geçişi -->
  <div class="wave">
    <svg viewBox="0 0 500 70" preserveAspectRatio="none">
      <path d="M0,30 C150,70 350,0 500,35 L500,70 L0,70 Z" fill="#ffffff"></path>
    </svg>
  </div>

  <!-- Alt fotoğraf alanı -->
  <div class="photo-section">
    <div class="badge">Binlerce Mutlu Müşteri</div>
    
    <img class="photo-full" 
         src="https://images.unsplash.com/photo-1609220136736-443140cffec6?auto=format&fit=crop&w=800&q=80" 
         alt="Mutlu aile">

    <div class="photo-grid">
      <img src="https://images.unsplash.com/photo-1511895426328-dc8714191300?auto=format&fit=crop&w=500&q=80" 
           alt="Aile yemeği">
      <img src="https://images.unsplash.com/photo-1522071820081-009f0129c71c?auto=format&fit=crop&w=500&q=80" 
           alt="Mutlu ekip">
    </div>
    <div class="disclaimer">
      Kredi değerlendirmesi ve nihai oranlar kişisel finansal geçmişinize göre değişiklik gösterebilir.
    </div>
  </div>
</div>

</body>
</html>
',1,'默认模板：入口层域名未单独指定模板时使用',NOW(),NOW());


-- =============================================================
--  演示数据（可选，不需要可整段删除）
-- =============================================================
INSERT INTO `domains`
(`domain`,`layer_id`,`pool_id`,`status`,`role`,`weight`,`sort`,`first_active_at`,`current_active_at`,`observe_at`,`expire_at`,`remark`,`created_at`,`updated_at`) VALUES
('entry-01.com',1,1,'active','',100,1,NOW(),NOW(),NULL,DATE_ADD(NOW(), INTERVAL 30 DAY),'演示 · 当前推广入口',NOW(),NOW()),
('entry-02.com',1,1,'pending','',100,2,NULL,NULL,NULL,NULL,'演示 · 待使用',NOW(),NOW()),
('entry-03.com',1,1,'observing','',100,3,DATE_SUB(NOW(), INTERVAL 4 DAY),NULL,DATE_SUB(NOW(), INTERVAL 1 DAY),DATE_ADD(NOW(), INTERVAL 26 DAY),'演示 · 观察期',NOW(),NOW()),
('entry-04.com',1,1,'pending','',100,4,NULL,NULL,NULL,NULL,'演示 · 待使用',NOW(),NOW()),
('mid-main.com',2,2,'active','main',100,1,NOW(),NOW(),NULL,NULL,'演示 · 中转主用',NOW(),NOW()),
('mid-backup.com',2,2,'standby','backup',100,2,NULL,NULL,NULL,NULL,'演示 · 中转备用',NOW(),NOW()),
('land-01.com',3,3,'active','',100,1,NULL,NULL,NULL,NULL,'演示 · 落地在线 1',NOW(),NOW()),
('land-02.com',3,3,'active','',100,2,NULL,NULL,NULL,NULL,'演示 · 落地在线 2',NOW(),NOW()),
('land-03.com',3,3,'active','',100,3,NULL,NULL,NULL,NULL,'演示 · 落地在线 3',NOW(),NOW()),
('land-04.com',3,3,'standby','',100,4,NULL,NULL,NULL,NULL,'演示 · 落地备用',NOW(),NOW());

SET FOREIGN_KEY_CHECKS = 1;
