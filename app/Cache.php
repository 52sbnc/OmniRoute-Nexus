<?php
/**
 * 缓存：Redis 专用
 * 跳转链路只读 Redis 快照，保证单次跳转耗时 < 50ms。
 */
class Cache
{
    private static bool $inited = false;
    private static bool $ok = false;
    private static ?Redis $redis = null;
    private static string $prefix = 'dl:';
    private static string $error = '';

    private static function init(): void
    {
        if (self::$inited) {
            return;
        }
        self::$inited = true;

        $cfg          = Config::get('redis', []);
        self::$prefix = $cfg['prefix'] ?? 'dl:';

        if (!class_exists('Redis')) {
            self::$error = '未安装 phpredis 扩展（php_redis / ext-redis）';
            Logger::error('Redis 初始化失败：' . self::$error);
            return;
        }

        try {
            $r = new Redis();
            $r->connect($cfg['host'] ?? '127.0.0.1', (int) ($cfg['port'] ?? 6379), 1.5);
            if (!empty($cfg['password'])) {
                $r->auth($cfg['password']);
            }
            if (!empty($cfg['database'])) {
                $r->select((int) $cfg['database']);
            }
            $r->ping();
            self::$redis = $r;
            self::$ok    = true;
        } catch (Throwable $e) {
            self::$error = $e->getMessage();
            Logger::error('Redis 连接失败：' . self::$error);
        }
    }

    /** Redis 是否可用 */
    public static function usingRedis(): bool
    {
        self::init();
        return self::$ok;
    }

    /** 最近一次错误信息 */
    public static function lastError(): string
    {
        self::init();
        return self::$error;
    }

    private static function key(string $key): string
    {
        return self::$prefix . $key;
    }

    public static function get(string $key, $default = null)
    {
        self::init();
        if (!self::$ok) {
            return $default;
        }
        try {
            $v = self::$redis->get(self::key($key));
            if ($v === false || $v === null) {
                return $default;
            }
            $decoded = json_decode((string) $v, true);
            return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
        } catch (Throwable $e) {
            Logger::error('Redis get 异常：' . $e->getMessage());
            return $default;
        }
    }

    public static function set(string $key, $value, int $ttl = 0): bool
    {
        self::init();
        if (!self::$ok) {
            return false;
        }
        try {
            $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                return false;
            }
            return $ttl > 0
                ? (bool) self::$redis->setex(self::key($key), $ttl, $json)
                : (bool) self::$redis->set(self::key($key), $json);
        } catch (Throwable $e) {
            Logger::error('Redis set 异常：' . $e->getMessage());
            return false;
        }
    }

    public static function delete(string ...$keys): void
    {
        self::init();
        if (!self::$ok || !$keys) {
            return;
        }
        try {
            self::$redis->del(array_map([self::class, 'key'], $keys));
        } catch (Throwable $e) {
            Logger::error('Redis del 异常：' . $e->getMessage());
        }
    }

    /** 计数器自增（用于限频），返回自增后的值 */
    public static function incr(string $key, int $ttl = 60): int
    {
        self::init();
        if (!self::$ok) {
            return 1;
        }
        try {
            $n = (int) self::$redis->incr(self::key($key));
            if ($n === 1 && $ttl > 0) {
                self::$redis->expire(self::key($key), $ttl);
            }
            return $n;
        } catch (Throwable $e) {
            Logger::error('Redis incr 异常：' . $e->getMessage());
            return 1;
        }
    }

    /** 清空本系统前缀下的全部缓存 */
    public static function flushPrefix(): void
    {
        self::init();
        if (!self::$ok) {
            return;
        }
        try {
            $it = null;
            self::$redis->setOption(Redis::OPT_SCAN, Redis::SCAN_RETRY);
            while (($keys = self::$redis->scan($it, self::$prefix . '*', 200)) !== false) {
                if ($keys) {
                    self::$redis->del($keys);
                }
            }
        } catch (Throwable $e) {
            Logger::error('Redis 清空缓存异常：' . $e->getMessage());
        }
    }
}
