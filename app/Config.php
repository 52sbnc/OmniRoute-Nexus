<?php
/**
 * 配置读取（文件配置 + 数据库设置）
 */
class Config
{
    private static ?array $file = null;
    private static ?array $settings = null;
    private static ?array $settingsMap = null;

    /** 数据库设置项的默认值 */
    public const DEFAULTS = [
        'site_name'      => '天枢 · 多级智能导流系统',
        'final_url'      => 'https://example.com/',
        'fallback_url'   => 'https://example.com/',
        'jump_type'      => 'meta',
        'force_https'    => '0',
        'jump_scheme'    => 'auto',
        'ip_limit'       => '0',
        'ua_blacklist'   => '',
        'log_keep_days'  => '30',
        'notify_enabled' => '0',
        'notify_events'  => 'domain_fail,domain_replace,main_switch,expire,review,rotate,task_error',
        'notify_email_enabled'      => '0',
        'notify_email_to'           => '',
        'notify_telegram_enabled'   => '0',
        'notify_telegram_bot'       => '',
        'notify_telegram_chat'      => '',
        'notify_wecom_enabled'      => '0',
        'notify_wecom_webhook'      => '',
        'notify_dingtalk_enabled'   => '0',
        'notify_dingtalk_webhook'   => '',
        'notify_webhook_enabled'    => '0',
        'notify_webhook_url'        => '',
    ];

    public static function base(): array
    {
        if (self::$file === null) {
            $path = ROOT_PATH . '/config/config.php';
            self::$file = is_file($path) ? (array) require $path : [];
        }
        return self::$file;
    }

    public static function get(string $key, $default = null)
    {
        $data = self::base();
        foreach (explode('.', $key) as $seg) {
            if (!is_array($data) || !array_key_exists($seg, $data)) {
                return $default;
            }
            $data = $data[$seg];
        }
        return $data;
    }

    public static function debug(): bool
    {
        return (bool) self::get('app.debug', false);
    }

    /** 读取全部数据库设置（含默认值合并） */
    public static function settings(): array
    {
        if (self::$settings !== null) {
            return self::$settings;
        }
        $map = self::settingsMap();
        self::$settings = array_merge(self::DEFAULTS, $map);
        return self::$settings;
    }

    /** 数据库原始键值对 */
    private static function settingsMap(): array
    {
        if (self::$settingsMap !== null) {
            return self::$settingsMap;
        }
        self::$settingsMap = [];
        try {
            foreach (Database::all('SELECT `k`,`v` FROM `settings`') as $row) {
                self::$settingsMap[$row['k']] = (string) $row['v'];
            }
        } catch (Throwable $e) {
            // 数据库未就绪时静默降级
        }
        return self::$settingsMap;
    }

    public static function setting(string $key, $default = null): string
    {
        $all = self::settings();
        return array_key_exists($key, $all) ? (string) $all[$key] : (string) $default;
    }

    public static function settingBool(string $key, bool $default = false): bool
    {
        $all = self::settings();
        if (!array_key_exists($key, $all)) {
            return $default;
        }
        return in_array((string) $all[$key], ['1', 'true', 'on', 'yes'], true);
    }

    public static function settingInt(string $key, int $default = 0): int
    {
        $all = self::settings();
        return array_key_exists($key, $all) ? (int) $all[$key] : $default;
    }

    public static function saveSetting(string $key, string $value): void
    {
        Database::exec(
            'INSERT INTO `settings` (`k`,`v`) VALUES (?,?) ON DUPLICATE KEY UPDATE `v`=VALUES(`v`)',
            [$key, $value]
        );
        self::$settings = null;
        self::$settingsMap = null;
        // 跳转所需的快照同步刷新
        Cache::set('settings_snapshot', self::settings(), 86400);
    }

    /** 设置变更后让缓存失效 */
    public static function resetSettings(): void
    {
        self::$settings = null;
        self::$settingsMap = null;
    }
}
