<?php
/**
 * 计划任务执行器（CLI 定时任务与后台手动执行共用）
 */
class TaskRunner
{
    public static function run(string $code, bool $manual = false): array
    {
        $start  = microtime(true);
        $result = 'ok';
        $message = '';
        $events = [];

        try {
            switch ($code) {
                case 'check':
                    $r = DomainService::checkAndFailover();
                    $events  = $r['events'];
                    $message = sprintf('实时检测完成：检测 %d 个域名，发现 %d 个异常', $r['checked'], $r['failed']);
                    if ($r['failed'] > 0) {
                        $result = 'warn';
                    }
                    break;

                case 'rotate':
                    $events  = DomainService::rotate();
                    $message = '轮换任务完成，产生 ' . count($events) . ' 条状态变更';
                    break;

                case 'cleanup':
                    $r       = self::cleanup();
                    $message = sprintf('汇总 %d 天历史数据，清理日志 %d 条，数据库备份：%s', $r['rolled'], $r['deleted'], $r['backup'] ?: '未生成');
                    break;

                default:
                    throw new RuntimeException('未知任务：' . $code);
            }

            if ($events) {
                Notifier::dispatch($events);
            }
        } catch (Throwable $e) {
            $result  = 'fail';
            $message = $e->getMessage();
            Logger::error('任务执行失败 [' . $code . ']：' . $message);
            try {
                Notifier::send('task_error', '计划任务异常', sprintf(
                    "【导流系统告警】\n任务：%s\n错误：%s\n时间：%s",
                    $code,
                    $message,
                    date('Y-m-d H:i:s')
                ));
            } catch (Throwable $ignore) {
            }
        }

        $ms = (int) round((microtime(true) - $start) * 1000);

        // 写入执行记录（数据库异常时不影响任务本身，避免计划任务入口直接 500）
        try {
            Database::exec(
                'UPDATE `tasks` SET `last_run_at`=?, `last_result`=?, `last_message`=?, `last_ms`=? WHERE `code`=?',
                [Database::now(), $result, mb_substr($message, 0, 480), $ms, $code]
            );
            Database::insert('task_logs', [
                'task_code'   => $code,
                'result'      => $result,
                'message'     => mb_substr($message, 0, 990),
                'duration_ms' => $ms,
                'created_at'  => Database::now(),
            ]);
        } catch (Throwable $e) {
            Logger::error('任务执行记录写入失败：' . $e->getMessage());
        }

        return ['code' => $code, 'result' => $result, 'message' => $message, 'ms' => $ms, 'events' => $events];
    }

    /**
     * 清理过期日志并备份数据库
     */
    public static function cleanup(): array
    {
        $days = max(1, Config::settingInt('log_keep_days', 30));
        $edge = date('Y-m-d H:i:s', time() - $days * 86400);

        // 先把历史数据汇总进 stats_daily，再删除日志，保证累计量不丢
        $rolled = self::rollupStats();

        $deleted = 0;
        foreach (['jump_logs', 'check_logs', 'domain_logs', 'task_logs', 'notifications', 'operation_logs'] as $table) {
            $deleted += Database::exec("DELETE FROM `$table` WHERE `created_at` < ?", [$edge]);
        }

        $backup = self::backupDatabase();
        return ['deleted' => $deleted, 'backup' => $backup, 'rolled' => $rolled];
    }

    /**
     * 把历史日期的跳转次数与访客数汇总到 stats_daily
     * 只处理今天之前的日期，避免把当天的实时数据覆盖掉
     */
    public static function rollupStats(): int
    {
        $rows = Database::all(
            "SELECT DATE(`created_at`) AS d, COUNT(*) AS c, COUNT(DISTINCT `ip`) AS v, COUNT(DISTINCT `flow`) AS f
             FROM `jump_logs` WHERE DATE(`created_at`) < CURDATE()
             GROUP BY DATE(`created_at`)"
        );
        $n = 0;
        foreach ($rows as $r) {
            Database::exec(
                'INSERT INTO `stats_daily` (`d`,`jumps`,`flows`,`visitors`,`updated_at`) VALUES (?,?,?,?,NOW())
                 ON DUPLICATE KEY UPDATE
                    `jumps` = GREATEST(`jumps`, VALUES(`jumps`)),
                    `flows` = GREATEST(`flows`, VALUES(`flows`)),
                    `visitors` = VALUES(`visitors`),
                    `updated_at` = NOW()',
                [(string) $r['d'], (int) $r['c'], (int) $r['f'], (int) $r['v']]
            );
            $n++;
        }

        // 渠道维度汇总（跳转次数一次访问只记一次，访客按 IP 去重）
        $channelRows = Database::all(
            "SELECT DATE(`created_at`) AS d, `channel_id`, COUNT(DISTINCT `flow`) AS f, COUNT(DISTINCT `ip`) AS v
             FROM `jump_logs` WHERE DATE(`created_at`) < CURDATE() AND `channel_id` > 0
             GROUP BY DATE(`created_at`), `channel_id`"
        );
        foreach ($channelRows as $r) {
            Database::exec(
                'INSERT INTO `stats_channel_daily` (`d`,`channel_id`,`flows`,`visitors`,`updated_at`) VALUES (?,?,?,?,NOW())
                 ON DUPLICATE KEY UPDATE
                    `flows` = GREATEST(`flows`, VALUES(`flows`)),
                    `visitors` = VALUES(`visitors`),
                    `updated_at` = NOW()',
                [(string) $r['d'], (int) $r['channel_id'], (int) $r['f'], (int) $r['v']]
            );
        }
        return $n;
    }

    /**
     * 导出数据库结构 + 数据到 runtime/backup
     */
    public static function backupDatabase(): string
    {
        $dir = ROOT_PATH . '/runtime/backup';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $file = $dir . '/backup-' . date('Ymd-His') . '.sql';
        $fh   = @fopen($file, 'w');
        if (!$fh) {
            throw new RuntimeException('备份文件无法写入：' . $file);
        }

        $tables = [];
        foreach (Database::all('SHOW TABLES') as $row) {
            $tables[] = (string) array_values($row)[0];
        }
        fwrite($fh, "-- 导流系统数据库备份 " . date('Y-m-d H:i:s') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

        foreach ($tables as $table) {
            $create = Database::one("SHOW CREATE TABLE `$table`");
            fwrite($fh, "\nDROP TABLE IF EXISTS `$table`;\n" . ($create['Create Table'] ?? '') . ";\n");

            $rows = Database::all("SELECT * FROM `$table`");
            if (!$rows) {
                continue;
            }
            $cols = array_keys($rows[0]);
            $chunks = array_chunk($rows, 200);
            foreach ($chunks as $chunk) {
                $values = [];
                foreach ($chunk as $row) {
                    $vals = array_map(static function ($v) {
                        if ($v === null) {
                            return 'NULL';
                        }
                        return Database::pdo()->quote((string) $v);
                    }, array_values($row));
                    $values[] = '(' . implode(',', $vals) . ')';
                }
                fwrite($fh, "INSERT INTO `$table` (`" . implode('`,`', $cols) . "`) VALUES\n"
                    . implode(",\n", $values) . ";\n");
            }
        }
        fwrite($fh, "\nSET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fh);

        // 仅保留最近 10 份备份
        $files = glob($dir . '/backup-*.sql') ?: [];
        rsort($files);
        foreach (array_slice($files, 10) as $old) {
            @unlink($old);
        }

        return basename($file);
    }
}
