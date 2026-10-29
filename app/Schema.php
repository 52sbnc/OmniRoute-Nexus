<?php
/**
 * 数据库结构自动升级（幂等）
 *
 * 已部署的站点无需手动导入 SQL，首次请求会自动补齐新增表与字段；
 * 升级完成后写入 runtime/.schema-{版本} 标记文件，后续请求直接跳过。
 */
class Schema
{
    /** 结构版本：有改动时 +1（同时会在 database.sql 中同步结构） */
    public const VERSION = 4;

    public static function ensure(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $lock = ROOT_PATH . '/runtime/.schema-' . self::VERSION;
        if (is_file($lock)) {
            return;
        }

        try {
            self::migrate();
            @file_put_contents($lock, date('Y-m-d H:i:s'));
        } catch (Throwable $e) {
            Logger::warn('数据库结构升级失败：' . $e->getMessage());
        }
    }

    private static function hasTable(string $table): bool
    {
        return (int) Database::value(
            'SELECT COUNT(*) FROM `information_schema`.`TABLES`
             WHERE `TABLE_SCHEMA`=DATABASE() AND `TABLE_NAME`=?',
            [$table]
        ) > 0;
    }

    private static function hasColumn(string $table, string $column): bool
    {
        return (int) Database::value(
            'SELECT COUNT(*) FROM `information_schema`.`COLUMNS`
             WHERE `TABLE_SCHEMA`=DATABASE() AND `TABLE_NAME`=? AND `COLUMN_NAME`=?',
            [$table, $column]
        ) > 0;
    }

    private static function migrate(): void
    {
        if (!self::hasTable('channels')) {
            Database::exec(
                "CREATE TABLE `channels` (
                    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `name`       VARCHAR(60)  NOT NULL COMMENT '渠道名称',
                    `sort`       INT          NOT NULL DEFAULT 0,
                    `remark`     VARCHAR(255) NOT NULL DEFAULT '',
                    `created_at` DATETIME     NULL,
                    `updated_at` DATETIME     NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uk_name` (`name`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='渠道'"
            );
        }

        if (!self::hasTable('stats_channel_daily')) {
            Database::exec(
                "CREATE TABLE `stats_channel_daily` (
                    `d`          DATE NOT NULL COMMENT '日期',
                    `channel_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '渠道 id',
                    `flows`      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '当日跳转次数（一次访问只记一次）',
                    `visitors`   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '当日独立访客(IP)数',
                    `updated_at` DATETIME NULL,
                    PRIMARY KEY (`d`,`channel_id`),
                    KEY `idx_channel` (`channel_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='渠道每日统计汇总'"
            );
        }

        if (!self::hasColumn('templates', 'channel_id')) {
            Database::exec(
                "ALTER TABLE `templates`
                 ADD COLUMN `channel_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '所属渠道 id' AFTER `name`"
            );
        }

        if (!self::hasColumn('jump_logs', 'flow')) {
            Database::exec(
                "ALTER TABLE `jump_logs`
                 ADD COLUMN `flow` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '访问流程标识' AFTER `ip`,
                 ADD KEY `idx_flow` (`flow`)"
            );
        }

        if (!self::hasColumn('jump_logs', 'channel_id')) {
            Database::exec(
                "ALTER TABLE `jump_logs`
                 ADD COLUMN `channel_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '渠道 id' AFTER `flow`,
                 ADD KEY `idx_channel_time` (`channel_id`, `created_at`)"
            );
        }

        if (!self::hasColumn('stats_daily', 'flows')) {
            Database::exec(
                "ALTER TABLE `stats_daily`
                 ADD COLUMN `flows` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '当日跳转次数（去重）' AFTER `jumps`"
            );
        }
    }
}
