<?php
/**
 * 数据库 / 缓存配置文件
 * 手动导入 database.sql 后，按实际环境修改以下参数即可。
 */
return [
    // ---------------- 数据库 ----------------
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'daoliu',
        'user'    => 'daoliu',
        'pass'    => 'your-db-password',
        'charset' => 'utf8mb4',
    ],

    // ---------------- Redis 缓存 ----------------
    // 跳转链路依赖 Redis 缓存路由快照，请确保已安装 phpredis 扩展并启动 Redis。
    'redis' => [
        'enabled'  => true,
        'host'     => '127.0.0.1',
        'port'     => 6379,
        'password' => '',
        'database' => 0,
        'prefix'   => 'dl:',
    ],

    // ---------------- 应用 ----------------
    'app' => [
        'name'       => '天枢 · 多级智能导流系统',
        'debug'      => false,
        'timezone'   => 'Asia/Shanghai',
        // 请替换为随机字符串
        'key'        => 'change-this-to-a-random-string',
        // task.php 通过 URL（宝塔 URL 计划任务）调用时的令牌，留空则仅允许 CLI 调用；请替换为随机字符串
        'cron_token' => 'change-this-cron-token',
    ],
];
