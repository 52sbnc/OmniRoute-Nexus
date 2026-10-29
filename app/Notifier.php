<?php
/**
 * 通知模块：邮件 / Telegram / 企业微信 / 钉钉 / 自定义 Webhook
 * 同时提供站内通知记录。
 */
class Notifier
{
    /** 批量处理业务事件 */
    public static function dispatch(array $events): void
    {
        foreach ($events as $ev) {
            $event   = (string) ($ev['event'] ?? 'system');
            $title   = (string) ($ev['title'] ?? '系统通知');
            $content = (string) ($ev['content'] ?? '');
            if ($content === '') {
                continue;
            }
            // 站内记录始终写入
            if (!self::eventEnabled($event)) {
                continue;
            }
            self::send($event, $title, $content);
        }
    }

    private static function eventEnabled(string $event): bool
    {
        if (!Config::settingBool('notify_enabled')) {
            return false;
        }
        if ($event === 'pool_empty') {
            return true; // 池子告急始终通知
        }
        $list = array_filter(array_map('trim', explode(',', Config::setting('notify_events', ''))));
        return in_array($event, $list, true);
    }

    public static function send(string $event, string $title, string $content): void
    {
        $channels = [
            'email'    => Config::settingBool('notify_email_enabled'),
            'telegram' => Config::settingBool('notify_telegram_enabled'),
            'wecom'    => Config::settingBool('notify_wecom_enabled'),
            'dingtalk' => Config::settingBool('notify_dingtalk_enabled'),
            'webhook'  => Config::settingBool('notify_webhook_enabled'),
        ];

        foreach ($channels as $channel => $enabled) {
            if (!$enabled) {
                continue;
            }
            [$ok, $err] = self::push($channel, $title, $content);
            Database::insert('notifications', [
                'channel'    => $channel,
                'event'      => $event,
                'title'      => mb_substr($title, 0, 100),
                'content'    => $content,
                'status'     => $ok ? 'ok' : 'fail',
                'error'      => mb_substr((string) $err, 0, 480),
                'created_at' => Database::now(),
            ]);
        }
    }

    /** 测试某个渠道 */
    public static function test(string $channel): array
    {
        return self::push($channel, '导流系统测试通知', "【导流系统】通知渠道测试\n时间：" . date('Y-m-d H:i:s'));
    }

    private static function push(string $channel, string $title, string $content): array
    {
        try {
            switch ($channel) {
                case 'email':
                    $to = Config::setting('notify_email_to');
                    if (!$to) {
                        return [false, '未配置收件邮箱'];
                    }
                    $subject = '=?UTF-8?B?' . base64_encode($title) . '?=';
                    $body    = str_replace("\n", "<br>", e($content));
                    $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: " . Config::setting('site_name') . " <no-reply@" . (request_host() ?: 'localhost') . ">\r\n";
                    $ok = @mail($to, $subject, $body, $headers);
                    return [$ok, $ok ? '' : 'mail() 发送失败，请检查服务器邮件服务'];

                case 'telegram':
                    $token = Config::setting('notify_telegram_bot');
                    $chat  = Config::setting('notify_telegram_chat');
                    if (!$token || !$chat) {
                        return [false, '未配置 Bot Token 或 Chat ID'];
                    }
                    $resp = self::httpPost(
                        "https://api.telegram.org/bot{$token}/sendMessage",
                        ['chat_id' => $chat, 'text' => $title . "\n\n" . $content],
                        true
                    );
                    return [$resp['ok'], $resp['ok'] ? '' : $resp['body']];

                case 'wecom':
                    $url = Config::setting('notify_wecom_webhook');
                    if (!$url) {
                        return [false, '未配置企业微信 Webhook'];
                    }
                    $resp = self::httpPost($url, [
                        'msgtype'  => 'text',
                        'text'     => ['content' => $title . "\n" . $content],
                    ], true);
                    return [$resp['ok'], $resp['ok'] ? '' : $resp['body']];

                case 'dingtalk':
                    $url = Config::setting('notify_dingtalk_webhook');
                    if (!$url) {
                        return [false, '未配置钉钉 Webhook'];
                    }
                    $resp = self::httpPost($url, [
                        'msgtype'  => 'text',
                        'text'     => ['content' => $title . "\n" . $content],
                    ], true);
                    return [$resp['ok'], $resp['ok'] ? '' : $resp['body']];

                case 'webhook':
                    $url = Config::setting('notify_webhook_url');
                    if (!$url) {
                        return [false, '未配置 Webhook 地址'];
                    }
                    $resp = self::httpPost($url, [
                        'title' => $title,
                        'content' => $content,
                        'event' => 'notify',
                        'time'  => date('Y-m-d H:i:s'),
                    ], false);
                    return [$resp['ok'], $resp['ok'] ? '' : $resp['body']];
            }
        } catch (Throwable $e) {
            return [false, $e->getMessage()];
        }
        return [false, '未知渠道'];
    }

    /** 简易 HTTP POST */
    public static function httpPost(string $url, array $data, bool $asJson): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'body' => '缺少 cURL 扩展'];
        }
        $ch = curl_init();
        $payload = $asJson ? json_encode($data, JSON_UNESCAPED_UNICODE) : http_build_query($data);
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => $asJson ? ['Content-Type: application/json; charset=utf-8'] : [],
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'body' => $err ?: '请求失败'];
        }
        $ok = $code >= 200 && $code < 300;
        if ($ok && $asJson) {
            $json = json_decode((string) $body, true);
            if (is_array($json)) {
                if (isset($json['ok']) && $json['ok'] === false) {
                    $ok = false;
                }
                if (isset($json['errcode']) && (int) $json['errcode'] !== 0) {
                    $ok = false;
                }
            }
        }
        return ['ok' => $ok, 'body' => 'HTTP ' . $code . ' ' . mb_substr((string) $body, 0, 400)];
    }
}
