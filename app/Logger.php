<?php
/**
 * 文件日志（按天切割）
 */
class Logger
{
    private static function write(string $level, string $message): void
    {
        $dir = ROOT_PATH . '/runtime/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $line = sprintf(
            "[%s] [%s] %s%s",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            PHP_EOL
        );
        @file_put_contents($dir . '/app-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    public static function info(string $message): void
    {
        self::write('info', $message);
    }

    public static function warn(string $message): void
    {
        self::write('warn', $message);
    }

    public static function error(string $message): void
    {
        self::write('error', $message);
    }
}
