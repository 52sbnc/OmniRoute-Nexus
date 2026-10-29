<?php
/**
 * 核心业务服务：域名池状态机 / 轮换 / 观察期 / 禁用 / 实时检测故障即换
 */
class DomainService
{
    public const CACHE_ROUTE = 'route:v3';

    /** 快照结构版本：字段有增减时 +1，旧快照会自动失效重建 */
    private const ROUTE_VER = 3;

    /** 全部层级，按 sort 升序，键为层级 id */
    public static function layers(): array
    {
        static $cache = null;
        if ($cache === null) {
            $cache = [];
            foreach (Database::all('SELECT * FROM `layers` ORDER BY `sort` ASC, `id` ASC') as $row) {
                $cache[(int) $row['id']] = $row;
            }
        }
        return $cache;
    }

    public static function layer(int $id): ?array
    {
        return self::layers()[$id] ?? null;
    }

    public static function clearLayerCache(): void
    {
        // layers() 使用函数内静态缓存，这里通过反射无必要；层级配置变更后由进程结束自然失效
    }

    /** 域名池 */
    public static function pools(int $layerId = 0): array
    {
        if ($layerId > 0) {
            return Database::all('SELECT * FROM `pools` WHERE `layer_id`=? ORDER BY `id` ASC', [$layerId]);
        }
        return Database::all('SELECT * FROM `pools` ORDER BY `layer_id` ASC, `id` ASC');
    }

    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM `domains` WHERE `id`=?', [$id]);
    }

    /** 某层级指定状态的域名列表 */
    public static function byStatus(int $layerId, array $statuses): array
    {
        if (!$statuses) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($statuses), '?'));
        return Database::all(
            "SELECT * FROM `domains` WHERE `layer_id`=? AND `status` IN ($ph) ORDER BY `sort` ASC, `id` ASC",
            array_merge([$layerId], $statuses)
        );
    }

    /** 当前“正在使用”的域名 */
    public static function active(int $layerId): array
    {
        return self::byStatus($layerId, ['active']);
    }

    // =========================================================
    //  状态变更
    // =========================================================

    /**
     * 变更域名状态并写日志
     */
    public static function setStatus(int $domainId, string $to, string $action, string $message = '', array $extra = []): void
    {
        $domain = self::find($domainId);
        if (!$domain) {
            return;
        }
        $from = (string) $domain['status'];
        if ($from === $to && !$extra) {
            return;
        }
        $data = array_merge(['status' => $to, 'updated_at' => Database::now()], $extra);
        Database::update('domains', $data, '`id`=?', [$domainId]);
        self::logChange((int) $domain['id'], (string) $domain['domain'], (int) $domain['layer_id'], $from, $to, $action, $message);
    }

    public static function logChange(int $domainId, string $domain, int $layerId, string $from, string $to, string $action, string $message = ''): void
    {
        Database::insert('domain_logs', [
            'domain_id'   => $domainId,
            'domain'      => $domain,
            'layer_id'    => $layerId,
            'from_status' => $from,
            'to_status'   => $to,
            'action'      => $action,
            'message'     => mb_substr($message, 0, 250),
            'created_at'  => Database::now(),
        ]);
    }

    /** 累计在网时长 */
    public static function accumulate(array $domain): int
    {
        if (empty($domain['current_active_at'])) {
            return 0;
        }
        $sec = max(0, time() - strtotime((string) $domain['current_active_at']));
        if ($sec > 0) {
            Database::exec(
                'UPDATE `domains` SET `total_active_seconds`=`total_active_seconds`+? WHERE `id`=?',
                [$sec, (int) $domain['id']]
            );
        }
        return $sec;
    }

    /**
     * 将域名投入“正在使用”
     */
    public static function activate(array $domain, string $action, string $message = ''): void
    {
        $layer = self::layer((int) $domain['layer_id']);
        $now   = date('Y-m-d H:i:s');
        $extra = [
            'current_active_at' => $now,
            'observe_at'        => null,
        ];
        if (empty($domain['first_active_at'])) {
            $extra['first_active_at'] = $now;
            $disableDays = (int) ($layer['disable_days'] ?? 0);
            if ($disableDays > 0) {
                $extra['expire_at'] = date('Y-m-d H:i:s', time() + $disableDays * 86400);
            }
        }
        $from = (string) $domain['status'];
        Database::update('domains', array_merge(['status' => 'active', 'updated_at' => $now], $extra), '`id`=?', [(int) $domain['id']]);

        self::logChange((int) $domain['id'], (string) $domain['domain'], (int) $domain['layer_id'], $from, 'active', $action, $message);
    }

    // =========================================================
    //  候选域名选取
    // =========================================================

    /**
     * 从健康池中挑选一个域名
     *
     * @param int   $layerId  层级
     * @param array $statuses 允许的状态
     * @param array $exclude  排除的域名 id
     */
    public static function pickCandidate(int $layerId, array $statuses, array $exclude = []): ?array
    {
        $list = self::byStatus($layerId, $statuses);
        if ($exclude) {
            $list = array_values(array_filter($list, static fn($d) => !in_array((int) $d['id'], $exclude, true)));
        }
        if (!$list) {
            return null;
        }
        // 优先级：待使用 > 备用 > 观察期
        $priority = ['pending' => 1, 'standby' => 2, 'observing' => 3, 'active' => 4, 'failed' => 9, 'disabled' => 9];
        $strategy = (string) (self::layer($layerId)['strategy'] ?? 'random');

        // 先按优先级分组，组内再按策略排序
        usort($list, static function ($a, $b) use ($priority, $strategy) {
            $pa = $priority[$a['status']] ?? 5;
            $pb = $priority[$b['status']] ?? 5;
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }
            if ($strategy === 'weight') {
                return (int) $b['weight'] <=> (int) $a['weight'];
            }
            if ($strategy === 'random') {
                return 0;
            }
            return [(int) $a['sort'], (int) $a['id']] <=> [(int) $b['sort'], (int) $b['id']];
        });

        if ($strategy === 'random') {
            // 在同优先级分组内随机
            $firstPriority = $priority[$list[0]['status']] ?? 5;
            $group = array_values(array_filter($list, static fn($d) => ($priority[$d['status']] ?? 5) === $firstPriority));
            return $group[random_int(0, count($group) - 1)];
        }
        if ($strategy === 'weight') {
            return self::weightedPick($list);
        }
        return $list[0];
    }

    /** 加权随机 */
    private static function weightedPick(array $list): array
    {
        $total = 0;
        foreach ($list as $d) {
            $total += max(1, (int) $d['weight']);
        }
        $rand = random_int(1, max(1, $total));
        $acc  = 0;
        foreach ($list as $d) {
            $acc += max(1, (int) $d['weight']);
            if ($rand <= $acc) {
                return $d;
            }
        }
        return $list[0];
    }

    // =========================================================
    //  补位：保证“同时使用数量”为配置值
    // =========================================================

    /**
     * 保证某层正在使用的域名数量达到配置值
     *
     * @return array 事件消息列表
     */
    public static function ensureActive(int $layerId, bool $notify = true): array
    {
        $events = [];
        $layer  = self::layer($layerId);
        // 最终层不参与；第 1 层入口层不限制在线数量，全靠人工启用/关闭，不自动补位
        if (!$layer || (int) $layer['is_final'] === 1 || $layerId === 1) {
            return $events;
        }
        $need    = max(1, (int) $layer['active_count']);
        $current = self::active($layerId);
        $missing = $need - count($current);
        if ($missing <= 0) {
            return $events;
        }

        for ($i = 0; $i < $missing; $i++) {
            $cand = self::pickCandidate($layerId, ['pending', 'standby', 'observing']);
            if (!$cand) {
                $events[] = [
                    'event'   => 'pool_empty',
                    'title'   => '健康域名池告急',
                    'content' => sprintf(
                        "【导流系统告警】\n层级：%s\n状态：健康域名不足，当前 %d 个，需要 %d 个\n处理：已启用兜底地址，请尽快补充域名\n时间：%s",
                        $layer['name'],
                        count($current),
                        $need,
                        date('Y-m-d H:i:s')
                    ),
                ];
                break;
            }
            self::activate($cand, '自动补位', '从健康池补充为正在使用');
            $current[] = array_merge($cand, ['status' => 'active']);
            $events[]  = [
                'event'   => 'domain_replace',
                'title'   => '域名自动补位',
                'content' => sprintf(
                    "【导流系统通知】\n层级：%s\n新域名：%s → 正在使用\n时间：%s",
                    $layer['name'],
                    $cand['domain'],
                    date('Y-m-d H:i:s')
                ),
            ];
        }
        return $events;
    }

    // =========================================================
    //  计划任务：轮换 / 观察期 / 禁用
    // =========================================================

    /**
     * 生命周期流转
     *   入口层：不限制在线数量与使用期，生命周期完全由人工启用/关闭控制，不自动流转
     *   其它层：使用期满 → 已到期；已到期保留期满 → 待使用；并自动补齐在线数量
     */
    public static function rotate(): array
    {
        $events = [];
        $now    = time();

        foreach (self::layers() as $layer) {
            $layerId = (int) $layer['id'];
            if ((int) $layer['is_final'] === 1) {
                continue;
            }
            // 入口层：不限量、不设使用期，全部人工启用/关闭
            if ($layerId === 1) {
                continue;
            }
            $rotateDays  = (int) $layer['rotate_days'];
            $observeDays = (int) $layer['observe_days'];

            // 1) 使用中满使用期 → 已到期
            if ($rotateDays > 0) {
                foreach (self::active($layerId) as $domain) {
                    $start = $domain['current_active_at'] ? strtotime((string) $domain['current_active_at']) : 0;
                    if ($start > 0 && ($now - $start) >= $rotateDays * 86400) {
                        self::accumulate($domain);
                        self::setStatus(
                            (int) $domain['id'],
                            'observing',
                            '使用期结束',
                            '投入使用满 ' . $rotateDays . ' 天，已到期',
                            ['observe_at' => date('Y-m-d H:i:s')]
                        );
                        $events[] = [
                            'event'   => 'expire',
                            'title'   => '域名已到期',
                            'content' => sprintf(
                                "【导流系统通知】\n层级：%s\n域名：%s → 已到期（不再对外服务）\n时间：%s",
                                $layer['name'],
                                $domain['domain'],
                                date('Y-m-d H:i:s')
                            ),
                        ];
                    }
                }
            }

            // 2) 已到期满保留期 → 恢复待使用
            if ($observeDays > 0) {
                foreach (self::byStatus($layerId, ['observing']) as $domain) {
                    $at = $domain['observe_at'] ? strtotime((string) $domain['observe_at']) : 0;
                    if ($at > 0 && ($now - $at) >= $observeDays * 86400) {
                        self::setStatus((int) $domain['id'], 'pending', '到期结束', '保留期满，恢复为待使用');
                        $events[] = [
                            'event'   => 'rotate',
                            'title'   => '域名恢复待使用',
                            'content' => sprintf(
                                "【导流系统通知】\n层级：%s\n域名：%s → 待使用\n时间：%s",
                                $layer['name'],
                                $domain['domain'],
                                date('Y-m-d H:i:s')
                            ),
                        ];
                    }
                }
            }

            // 3) 补足在线数量
            $events = array_merge($events, self::ensureActive($layerId, false));
        }

        self::rebuild();
        return $events;
    }

    // =========================================================
    //  实时检测 + 故障即换
    // =========================================================

    /**
     * 检测指定层级正在使用的域名，失败立即替换
     *
     * @param int|null $onlyLayer 仅检测某层
     * @return array{checked:int,failed:int,events:array}
     */
    public static function checkAndFailover(?int $onlyLayer = null, bool $withStandby = true): array
    {
        $checked = 0;
        $failed  = 0;
        $events  = [];

        foreach (self::layers() as $layer) {
            $layerId = (int) $layer['id'];
            if ((int) $layer['is_final'] === 1 || (int) $layer['check_enabled'] !== 1) {
                continue;
            }
            if ($onlyLayer !== null && $onlyLayer !== $layerId) {
                continue;
            }

            // 检测范围：正在使用 (+ 备用)
            $statuses = $withStandby ? ['active', 'standby'] : ['active'];
            $targets  = self::byStatus($layerId, $statuses);
            $fails    = [];

            foreach ($targets as $domain) {
                $res = Detector::check($domain);
                $checked++;

                Database::update('domains', [
                    'last_check_at'     => Database::now(),
                    'last_check_result' => $res['ok'] ? 'ok' : 'fail',
                    'last_check_msg'    => mb_substr($res['message'], 0, 250),
                    'resolved_ip'       => $res['resolved_ip'],
                    'response_ms'       => $res['response_ms'],
                    'fail_count'        => $res['ok'] ? (int) $domain['fail_count'] : (int) $domain['fail_count'] + 1,
                    'ok_count'          => $res['ok'] ? (int) $domain['ok_count'] + 1 : (int) $domain['ok_count'],
                    'updated_at'        => Database::now(),
                ], '`id`=?', [(int) $domain['id']]);

                self::logCheck($domain, $layerId, $res);

                if (!$res['ok']) {
                    $failed++;
                    $fails[] = ['domain' => $domain, 'res' => $res];
                }
            }

            if (!$fails) {
                continue;
            }

            // 故障即换
            foreach ($fails as $item) {
                $domain = $item['domain'];
                $res    = $item['res'];
                $isActive = $domain['status'] === 'active';
                $oldStatus = (string) $domain['status'];

                if ($oldStatus === 'active') {
                    self::accumulate($domain);
                }
                self::setStatus((int) $domain['id'], 'failed', '故障替换', $res['message']);

                if ($layerId === 2) {
                    $events[] = [
                        'event'   => 'main_switch',
                        'title'   => '中转层主备切换',
                        'content' => sprintf(
                            "【导流系统通知】\n层级：%s\n%s：%s → 失败（%s）\n时间：%s",
                            $layer['name'],
                            $domain['status'] === 'active' ? '主用' : '备用',
                            $domain['domain'],
                            $res['message'],
                            date('Y-m-d H:i:s')
                        ),
                    ];
                }

                $events[] = [
                    'event'   => 'domain_fail',
                    'title'   => '域名检测失败',
                    'content' => sprintf(
                        "【导流系统告警】\n层级：%s\n域名：%s\n状态：检测失败（%s）\n时间：%s",
                        $layer['name'],
                        $domain['domain'],
                        $res['message'],
                        date('Y-m-d H:i:s')
                    ),
                ];
            }

            // 入口层可多域名同时使用，故障域名直接标记，不自动补位；其它层补足在线数量
            if ($layerId === 1) {
                continue;
            }

            $before = self::active($layerId);
            $events = array_merge($events, self::ensureActive($layerId, false));

            // 主备切换补充说明
            if ($layerId === 2) {
                $after = self::active($layerId);
                if ($after && !$before) {
                    $events[] = [
                        'event'   => 'main_switch',
                        'title'   => '中转层主备切换',
                        'content' => sprintf(
                            "【导流系统通知】\n层级：%s\n备用：%s → 已启用为当前使用\n时间：%s",
                            $layer['name'],
                            $after[0]['domain'],
                            date('Y-m-d H:i:s')
                        ),
                    ];
                }
            }
        }

        self::rebuild();
        return ['checked' => $checked, 'failed' => $failed, 'events' => $events];
    }

    private static function logCheck(array $domain, int $layerId, array $res): void
    {
        Database::insert('check_logs', [
            'domain_id'   => (int) $domain['id'],
            'domain'      => (string) $domain['domain'],
            'layer_id'    => $layerId,
            'result'      => $res['ok'] ? 'ok' : 'fail',
            'dns_ok'      => $res['dns_ok'] ? 1 : 0,
            'http_code'   => 0,
            'ssl_ok'      => 1,
            'keyword_ok'  => 1,
            'resolved_ip' => (string) $res['resolved_ip'],
            'response_ms' => (int) $res['response_ms'],
            'message'     => mb_substr((string) $res['message'], 0, 250),
            'created_at'  => Database::now(),
        ]);
    }

    // =========================================================
    //  跳转缓存
    // =========================================================

    /**
     * 重建跳转路由快照（写入 Redis / 文件缓存）
     */
    public static function rebuild(): array
    {
        // 下级域名协议：https / http / auto（跟随当前访问协议）
        $scheme = strtolower(trim(Config::setting('jump_scheme', 'https')));
        if (!in_array($scheme, ['http', 'https', 'auto'], true)) {
            $scheme = 'https';
        }

        $l1map = [];
        foreach (Database::all("SELECT `domain`,`status` FROM `domains` WHERE `layer_id`=1") as $row) {
            $l1map[strtolower((string) $row['domain'])] = (string) $row['status'];
        }
        $l2 = self::byStatus(2, ['active', 'standby']);
        $l3 = self::byStatus(3, ['active']);

        // 全量域名状态表：域名 => [所属层级, 状态, 模板, 渠道]，用于识别未入池 / 待使用 / 已到期 / 待处理
        $dmap = [];
        foreach (Database::all(
            "SELECT `domain`,`layer_id`,`status`,`template_id`,
                    COALESCE((SELECT `channel_id` FROM `templates` t WHERE t.`id`=`domains`.`template_id`),0) AS `channel_id`
             FROM `domains`
             WHERE `layer_id` IN (SELECT `id` FROM `layers` WHERE `is_final`=0)"
        ) as $row) {
            $dmap[strtolower((string) $row['domain'])] = [
                'layer'       => (int) $row['layer_id'],
                'status'      => (string) $row['status'],
                'template_id' => (int) $row['template_id'],
                'channel'     => (int) $row['channel_id'],
            ];
        }

        // 跳转方式：meta（HTML 网页跳转，默认）/ 301 / 302
        $jumpType = strtolower(trim(Config::setting('jump_type', 'meta')));
        if (!in_array($jumpType, ['301', '302', 'meta'], true)) {
            $jumpType = 'meta';
        }

        $route = [
            'ver'          => self::ROUTE_VER,
            'ts'           => time(),
            'scheme'       => $scheme,
            'force_https'  => Config::settingBool('force_https'),
            'jump_type'    => $jumpType,
            'ip_limit'     => Config::settingInt('ip_limit', 0),
            'ua_blacklist' => Config::setting('ua_blacklist', ''),
            'final'        => Config::setting('final_url', ''),
            'fallback'     => Config::setting('fallback_url', ''),
            'l1'           => ['map' => $l1map],
            'domains'      => $dmap,
            'l2'           => [
                'active'  => $l2 ? (string) $l2[0]['domain'] : '',
                'standby' => array_map(static fn($d) => (string) $d['domain'], array_slice($l2, 1)),
            ],
            'l3'           => array_map(static fn($d) => (string) $d['domain'], $l3),
        ];

        Cache::set(self::CACHE_ROUTE, $route, 0);
        return $route;
    }

    /**
     * 判断访问域名在当前快照中的身份
     *
     * @return array{layer:int,status:string,template_id:int,text:string}
     *         text 为空表示可正常服务（入口层出海报、其它层跳转）；非空表示应显示该提示文字
     */
    public static function hostState(array $route, string $host): array
    {
        $info = $route['domains'][$host] ?? null;
        if (!is_array($info)) {
            return ['layer' => 0, 'status' => '', 'template_id' => 0, 'channel' => 0, 'text' => '未入池'];
        }

        $layer  = (int) ($info['layer'] ?? 0);
        $status = (string) ($info['status'] ?? '');
        $cnName = ['1' => '第一层', '2' => '第二层', '3' => '第三层'][(string) $layer] ?? ('第' . $layer . '层');
        $suffix = [
            'pending'   => '待使用',
            'standby'   => '备用',
            'observing' => '已到期',
            'review'    => '待处理',
            'disabled'  => '禁用',
            'failed'    => '检测失败',
        ][$status] ?? '';

        return [
            'layer'       => $layer,
            'status'      => $status,
            'template_id' => (int) ($info['template_id'] ?? 0),
            'channel'     => (int) ($info['channel'] ?? 0),
            'text'        => $suffix !== '' ? $cnName . $suffix : '',
        ];
    }

    /**
     * 域名生命周期文案（后台卡片显示用）
     * 正在使用：显示「已在线 X 天」（从首次投入使用算起）
     */
    public static function lifeText(array $domain): string
    {
        $status = (string) $domain['status'];
        $layer  = self::layer((int) $domain['layer_id']);

        if ($status === 'active' && !empty($domain['first_active_at'])) {
            $sec  = max(0, time() - strtotime((string) $domain['first_active_at']));
            $days = (int) floor($sec / 86400);
            return '已在线 ' . $days . ' 天';
        }
        if ($status === 'observing' && !empty($domain['observe_at'])) {
            $days = (int) ($layer['observe_days'] ?? 0);
            if ($days > 0) {
                $left = strtotime((string) $domain['observe_at']) + $days * 86400 - time();
                return $left > 0 ? '转待处理 ' . human_duration($left) : '待处理';
            }
        }
        return '';
    }

    /**
     * 当前应指向的中转层地址（第 2 层当前使用，缺失时取备用，再缺失取兜底）
     */
    public static function midUrl(array $route, string $scheme): string
    {
        $mid = (string) ($route['l2']['active'] ?? '');
        if ($mid === '') {
            $mid = (string) ($route['l2']['standby'][0] ?? '');
        }
        if ($mid !== '') {
            return build_url($scheme, $mid, '/');
        }
        return trim((string) ($route['fallback'] ?? ''));
    }

    /**
     * 渲染入口层海报页
     *
     * @return bool false 表示没有可用模板，调用方应回退为直接跳转
     */
    public static function renderPoster(array $route, string $host, int $templateId, string $scheme, float $start): bool
    {
        $tpl = $templateId > 0 ? self::template($templateId) : null;
        if (!$tpl) {
            $tpl = self::defaultTemplate();
        }
        $content = trim((string) ($tpl['content'] ?? ''));
        if ($content === '') {
            return false;
        }

        $link = self::midUrl($route, $scheme);
        // 带上访问流程标识，让后续第 2/3 层共享同一次访问（跳转次数只记一次）
        if ($link !== '') {
            $link = append_query($link, 'flow', self::flowToken());
        }

        // 跳转地址占位符
        $content = str_replace(
            ['{{link}}', '{{url}}', '{{中转层地址}}'],
            e($link),
            $content
        );

        $channelId = (int) ($route['domains'][$host]['channel'] ?? 0);
        self::logJump(1, $host, $link, $link !== '' ? 'ok' : 'fallback', (int) round((microtime(true) - $start) * 1000), $channelId);

        header('Content-Type: text/html; charset=utf-8');
        echo $content;
        return true;
    }

    /** 全部渠道，按 sort 升序，键为渠道 id */
    public static function channels(): array
    {
        static $cache = null;
        if ($cache === null) {
            $cache = [];
            try {
                foreach (Database::all('SELECT * FROM `channels` ORDER BY `sort` ASC, `id` ASC') as $row) {
                    $cache[(int) $row['id']] = $row;
                }
            } catch (Throwable $e) {
                Logger::warn('渠道读取失败：' . $e->getMessage());
            }
        }
        return $cache;
    }

    public static function channel(int $id): ?array
    {
        return self::channels()[$id] ?? null;
    }

    /** 全部海报模板 */
    public static function templates(): array
    {
        return Database::all('SELECT * FROM `templates` ORDER BY `is_default` DESC, `id` ASC');
    }

    public static function template(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        return Database::one('SELECT * FROM `templates` WHERE `id`=?', [$id]) ?: null;
    }

    /** 默认模板（未标记默认时取第一个） */
    public static function defaultTemplate(): ?array
    {
        return Database::one('SELECT * FROM `templates` ORDER BY `is_default` DESC, `id` ASC LIMIT 1') ?: null;
    }

    /**
     * 访问流程标识：一次访问（含经过第 2/3 层）共用同一个标识
     * 来源优先级：URL 上的 flow 参数（跨域名传递） > 当前域名 cookie > 新生成
     */
    public static function flowToken(): string
    {
        static $token = null;
        if ($token !== null) {
            return $token;
        }

        $in = (string) ($_GET['flow'] ?? '');
        if (preg_match('/^[a-f0-9]{16}$/', $in)) {
            return $token = $in;
        }

        $ck = (string) ($_COOKIE['dl_flow'] ?? '');
        if (preg_match('/^[a-f0-9]{16}$/', $ck)) {
            return $token = $ck;
        }

        $token = bin2hex(random_bytes(8));
        if (!headers_sent()) {
            setcookie('dl_flow', $token, [
                'expires'  => 0,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure'   => request_scheme() === 'https',
            ]);
        }
        return $token;
    }

    /**
     * 记录一次跳转（日志 + 当日汇总）
     *
     * @param int $channelId 渠道 id（入口层域名所属渠道，仅入口层记录）
     */
    public static function logJump(int $layer, string $host, string $target, string $result, int $costMs, int $channelId = 0): void
    {
        try {
            Database::insert('jump_logs', [
                'layer'      => $layer,
                'host'       => mb_substr($host, 0, 190),
                'target'     => mb_substr($target, 0, 490),
                'ip'         => client_ip(),
                'flow'       => self::flowToken(),
                'channel_id' => $channelId,
                'ua'         => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250),
                'result'     => $result,
                'cost_ms'    => $costMs,
                'created_at' => Database::now(),
            ]);
            self::recordJump();
        } catch (Throwable $e) {
            Logger::warn('跳转日志写入失败：' . $e->getMessage());
        }
    }

    /**
     * 读取跳转路由快照（缓存优先，缺失时回源）
     */
    public static function route(): array
    {
        $route = Cache::get(self::CACHE_ROUTE);
        // 结构校验：版本不匹配的是旧快照，必须重建
        if (is_array($route) && !empty($route['ts']) && (int) ($route['ver'] ?? 0) === self::ROUTE_VER) {
            return $route;
        }
        try {
            return self::rebuild();
        } catch (Throwable $e) {
            // 数据库或缓存异常时不让整站 500，交给调用方走兜底
            Logger::error('跳转快照重建失败：' . $e->getMessage());
            return [];
        }
    }

    // =========================================================
    //  统计
    // =========================================================

    /**
     * 记录一次跳转（写入当日汇总）
     * 汇总表独立于 jump_logs，日志被清理后累计量依然保留
     */
    public static function recordJump(): void
    {
        Database::exec(
            'INSERT INTO `stats_daily` (`d`,`jumps`,`updated_at`) VALUES (?,1,NOW())
             ON DUPLICATE KEY UPDATE `jumps`=`jumps`+1, `updated_at`=NOW()',
            [date('Y-m-d')]
        );
    }

    /**
     * 统计总览
     */
    public static function stats(): array
    {
        $byStatus = [];
        foreach (Database::all('SELECT `layer_id`,`status`,COUNT(*) AS c FROM `domains` GROUP BY `layer_id`,`status`') as $row) {
            $byStatus[(int) $row['layer_id']][(string) $row['status']] = (int) $row['c'];
        }

        $todayStart = date('Y-m-d 00:00:00');
        $ydayStart  = date('Y-m-d 00:00:00', strtotime('-1 day'));

        $jumpsToday = (int) Database::value('SELECT COUNT(*) FROM `jump_logs` WHERE `created_at`>=?', [$todayStart]);
        $visToday   = (int) Database::value('SELECT COUNT(DISTINCT `ip`) FROM `jump_logs` WHERE `created_at`>=?', [$todayStart]);
        $okToday    = (int) Database::value("SELECT COUNT(*) FROM `jump_logs` WHERE `created_at`>=? AND `result`='ok'", [$todayStart]);
        $jumpsYday  = (int) Database::value('SELECT COUNT(*) FROM `jump_logs` WHERE `created_at`>=? AND `created_at`<?', [$ydayStart, $todayStart]);
        $visYday    = (int) Database::value('SELECT COUNT(DISTINCT `ip`) FROM `jump_logs` WHERE `created_at`>=? AND `created_at`<?', [$ydayStart, $todayStart]);
        // 跳转次数：一次访问流程只记一次（按 flow 去重）
        $flowsToday = (int) Database::value("SELECT COUNT(DISTINCT `flow`) FROM `jump_logs` WHERE `created_at`>=? AND `flow`<>''", [$todayStart]);
        $flowsYday  = (int) Database::value("SELECT COUNT(DISTINCT `flow`) FROM `jump_logs` WHERE `created_at`>=? AND `created_at`<? AND `flow`<>''", [$ydayStart, $todayStart]);

        $alertsToday = (int) Database::value(
            "SELECT COUNT(*) FROM `domain_logs` WHERE `created_at`>=? AND `to_status`='failed'",
            [$todayStart]
        );

        // 累计量取自 stats_daily，日志清理后依然保留
        $totalJumps = $jumpsToday;
        $totalVis   = $visToday;
        $totalFlows = $flowsToday;
        try {
            $row = Database::one('SELECT COALESCE(SUM(`jumps`),0) AS j FROM `stats_daily`');
            if ($row && (int) $row['j'] > 0) {
                $totalJumps = (int) $row['j'];
            }
            $past = Database::one('SELECT COALESCE(SUM(`visitors`),0) AS v, COALESCE(SUM(`flows`),0) AS f FROM `stats_daily` WHERE `d` < ?', [date('Y-m-d')]);
            $totalVis   += (int) ($past['v'] ?? 0);
            $totalFlows += (int) ($past['f'] ?? 0);

            // 昨日数据若日志已被清理，用汇总表兜底
            $yRow = Database::one('SELECT `jumps`,`flows`,`visitors` FROM `stats_daily` WHERE `d`=?', [date('Y-m-d', strtotime('-1 day'))]);
            if ($yRow) {
                if ($jumpsYday === 0) {
                    $jumpsYday = (int) $yRow['jumps'];
                }
                if ($flowsYday === 0) {
                    $flowsYday = (int) $yRow['flows'];
                }
                if ($visYday === 0) {
                    $visYday = (int) $yRow['visitors'];
                }
            }
        } catch (Throwable $e) {
            Logger::warn('stats_daily 读取失败（请确认已建表）：' . $e->getMessage());
        }

        $onlineTotal = (int) Database::value(
            "SELECT COUNT(*) FROM `domains` WHERE `status`='active'
             AND `layer_id` IN (SELECT `id` FROM `layers` WHERE `is_final`=0)"
        );

        return [
            'by_status'          => $byStatus,
            'total'              => (int) Database::value('SELECT COUNT(*) FROM `domains`'),
            'fail_domains'       => (int) Database::value("SELECT COUNT(*) FROM `domains` WHERE `status`='failed'"),
            'online_total'       => $onlineTotal,
            'jumps_today'        => $jumpsToday,
            'flows_today'        => $flowsToday,
            'visitors_today'     => $visToday,
            'jumps_yesterday'    => $jumpsYday,
            'flows_yesterday'    => $flowsYday,
            'visitors_yesterday' => $visYday,
            'total_jumps'        => $totalJumps,
            'total_flows'        => $totalFlows,
            'total_visitors'     => $totalVis,
            'alerts_today'       => $alertsToday,
            'ok_rate'            => $jumpsToday > 0 ? round($okToday / $jumpsToday * 100, 1) : 100.0,
        ];
    }

    /**
     * 按渠道统计：今日 / 昨日 / 总数的跳转次数（一次访问只记一次）与访客数
     *
     * @return array 渠道行列表
     */
    public static function channelStats(): array
    {
        $todayStart = date('Y-m-d 00:00:00');
        $ydayStart  = date('Y-m-d 00:00:00', strtotime('-1 day'));

        $rows = [];
        foreach (self::channels() as $id => $c) {
            $rows[$id] = [
                'id'                 => $id,
                'name'               => (string) $c['name'],
                'today_flows'        => 0,
                'today_visitors'     => 0,
                'yesterday_flows'    => 0,
                'yesterday_visitors' => 0,
                'total_flows'        => 0,
                'total_visitors'     => 0,
            ];
        }

        try {
            // 今日实时
            foreach (Database::all(
                "SELECT `channel_id`, COUNT(DISTINCT `flow`) f, COUNT(DISTINCT `ip`) v
                 FROM `jump_logs` WHERE `channel_id`>0 AND `created_at`>=? GROUP BY `channel_id`",
                [$todayStart]
            ) as $r) {
                $cid = (int) $r['channel_id'];
                if (!isset($rows[$cid])) {
                    continue;
                }
                $rows[$cid]['today_flows']    = (int) $r['f'];
                $rows[$cid]['today_visitors'] = (int) $r['v'];
            }

            // 昨日
            foreach (Database::all(
                "SELECT `channel_id`, COUNT(DISTINCT `flow`) f, COUNT(DISTINCT `ip`) v
                 FROM `jump_logs` WHERE `channel_id`>0 AND `created_at`>=? AND `created_at`<? GROUP BY `channel_id`",
                [$ydayStart, $todayStart]
            ) as $r) {
                $cid = (int) $r['channel_id'];
                if (!isset($rows[$cid])) {
                    continue;
                }
                $rows[$cid]['yesterday_flows']    = (int) $r['f'];
                $rows[$cid]['yesterday_visitors'] = (int) $r['v'];
            }

            // 历史累计（日志清理前已汇总进 stats_channel_daily，均不含今天）
            foreach (Database::all(
                "SELECT `channel_id`, COALESCE(SUM(`flows`),0) f, COALESCE(SUM(`visitors`),0) v
                 FROM `stats_channel_daily` GROUP BY `channel_id`"
            ) as $r) {
                $cid = (int) $r['channel_id'];
                if (!isset($rows[$cid])) {
                    continue;
                }
                $rows[$cid]['total_flows']    += (int) $r['f'];
                $rows[$cid]['total_visitors'] += (int) $r['v'];
            }

            // 昨日若日志已被清理，用汇总表兜底
            $yday = date('Y-m-d', strtotime('-1 day'));
            foreach (Database::all(
                "SELECT `channel_id`,`flows`,`visitors` FROM `stats_channel_daily` WHERE `d`=?",
                [$yday]
            ) as $r) {
                $cid = (int) $r['channel_id'];
                if (!isset($rows[$cid])) {
                    continue;
                }
                if ($rows[$cid]['yesterday_flows'] === 0) {
                    $rows[$cid]['yesterday_flows'] = (int) $r['flows'];
                }
                if ($rows[$cid]['yesterday_visitors'] === 0) {
                    $rows[$cid]['yesterday_visitors'] = (int) $r['visitors'];
                }
            }
        } catch (Throwable $e) {
            Logger::warn('渠道统计读取失败：' . $e->getMessage());
        }

        // 今日实时量并入总数
        foreach ($rows as $cid => $row) {
            $rows[$cid]['total_flows']    = $row['total_flows'] + $row['today_flows'];
            $rows[$cid]['total_visitors'] = $row['total_visitors'] + $row['today_visitors'];
        }

        return array_values($rows);
    }
}
