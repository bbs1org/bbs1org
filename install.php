<?php

declare(strict_types=1);

const INSTALL_DATA_DIR = __DIR__ . '/data';
const INSTALL_DB_CONFIG_FILE = INSTALL_DATA_DIR . '/db.php';
const INSTALL_DEFAULT_DB_FILE = INSTALL_DATA_DIR . '/forum.sqlite';
const INSTALL_LOCK_FILE = INSTALL_DATA_DIR . '/install.lock';
const INSTALL_CACHE_DIR = __DIR__ . '/cache';
const INSTALL_FORUM_CACHE_FILE = INSTALL_CACHE_DIR . '/forums.php';
const INSTALL_GROUP_CACHE_FILE = INSTALL_CACHE_DIR . '/groups.php';
const INSTALL_STATS_CACHE_FILE = INSTALL_CACHE_DIR . '/stats.php';
const INSTALL_SETTING_CACHE_FILE = INSTALL_CACHE_DIR . '/settings.php';

function i_h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function i_now(): int { return time(); }
function i_db_name(): string
{
    if (is_file(INSTALL_DB_CONFIG_FILE)) {
        $config = include INSTALL_DB_CONFIG_FILE;
        $name = is_array($config) ? basename((string)($config['db_file'] ?? '')) : '';
        if ($name !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.sqlite$/', $name)) return $name;
    }
    if (is_file(INSTALL_DEFAULT_DB_FILE)) return basename(INSTALL_DEFAULT_DB_FILE);
    return 'forum-' . bin2hex(random_bytes(8)) . '.sqlite';
}
function i_db_file(): string
{
    return INSTALL_DATA_DIR . '/' . i_db_name();
}
function i_save_db_config(string $db_name): void
{
    if (!is_dir(INSTALL_DATA_DIR)) mkdir(INSTALL_DATA_DIR, 0755, true);
    file_put_contents(INSTALL_DB_CONFIG_FILE, "<?php\nreturn ['db_file' => " . var_export($db_name, true) . "];\n", LOCK_EX);
}
function i_env_text($value): string
{
    $text = trim((string)$value);
    return $text !== '' ? $text : '未检测到';
}
function i_install_error(string $title, string $message, array $steps): void
{
    if (PHP_SAPI === 'cli') {
        $plain_steps = [];
        foreach ($steps as $step) $plain_steps[] = trim(html_entity_decode(strip_tags($step), ENT_QUOTES, 'UTF-8'));
        fwrite(STDERR, $title . PHP_EOL . $message . PHP_EOL . PHP_EOL . implode(PHP_EOL, array_map(fn($step) => '- ' . $step, $plain_steps)) . PHP_EOL);
        exit(1);
    }
    $items = '';
    foreach ($steps as $step) $items .= '<li>' . $step . '</li>';
    $drivers = class_exists('PDO') ? implode(', ', PDO::getAvailableDrivers()) : 'PDO 未启用';
    i_html($title, '<div class="hero"><h1>' . i_h($title) . '</h1><p>安装环境检查未通过，请按提示处理后再继续。</p></div><div class="grid"><section class="card"><div class="hd"><h2>需要处理的问题</h2></div><div class="bd"><div class="note warn">' . i_h($message) . '</div><div style="height:14px"></div><ul class="list">' . $items . '</ul></div></section><aside class="card"><div class="hd"><h2>当前 PHP 环境</h2></div><div class="bd"><div class="kv"><div>PHP 版本</div><div class="mono">' . i_h(PHP_VERSION) . '</div><div>php.ini</div><div class="mono">' . i_h(i_env_text(php_ini_loaded_file())) . '</div><div>附加配置</div><div class="mono">' . i_h(i_env_text(php_ini_scanned_files())) . '</div><div>PDO 驱动</div><div class="mono">' . i_h(i_env_text($drivers)) . '</div></div></div></aside></div>');
}
function i_check_sqlite_support(): void
{
    if (!class_exists('PDO')) {
        i_install_error('PDO 扩展未启用', '当前 PHP 环境缺少 PDO，安装程序无法连接 SQLite 数据库。', [
            '在 <span class="mono">php.ini</span> 中启用或安装 <span class="mono">pdo</span> 扩展。',
            '如果使用 Linux 包管理器，请安装当前 PHP 版本对应的 PDO 组件。',
            '修改完成后重启 Web 服务或 PHP-FPM，再重新访问安装页面。',
        ]);
    }
    if (!extension_loaded('pdo_sqlite') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        i_install_error('SQLite 扩展未启用', '当前 PHP 环境缺少 SQLite PDO 驱动，无法创建论坛数据库。', [
            '确认当前 PHP 已启用 <span class="mono">pdo_sqlite</span> 扩展。',
            'Windows 环境：打开右侧显示的 <span class="mono">php.ini</span>，启用 <span class="mono">extension=pdo_sqlite</span>。',
            '如果启动 PHP 时提示 <span class="mono">Unable to load dynamic library \'pdo_sqlite\'</span>，请检查 <span class="mono">extension_dir</span> 是否指向当前 PHP 的 <span class="mono">ext</span> 目录，并确认其中存在 <span class="mono">php_pdo_sqlite.dll</span>。',
            'Linux 环境：安装当前 PHP 版本对应的 PDO SQLite 支持包，安装后确认 PDO 驱动列表中出现 <span class="mono">sqlite</span>。',
            '修改完成后重启 Web 服务或 PHP-FPM，再重新访问安装页面。',
        ]);
    }
}
function i_db(): PDO
{
    static $db;
    if ($db) return $db;
    i_check_sqlite_support();
    $file = i_db_file();
    $dir = dirname($file);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    i_save_db_config(basename($file));
    try {
        $db = new PDO('sqlite:' . $file, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $e) {
        i_install_error('数据库初始化失败', 'SQLite 数据库连接失败：' . $e->getMessage(), [
            '确认 PHP 的 <span class="mono">pdo_sqlite</span> 扩展已经启用。',
            '确认 <span class="mono">data</span> 目录可写，Web 服务用户可以创建和写入 SQLite 文件。',
            '处理完成后刷新安装页面重试。',
        ]);
    }
    foreach (['PRAGMA journal_mode=WAL', 'PRAGMA synchronous=NORMAL', 'PRAGMA temp_store=MEMORY', 'PRAGMA busy_timeout=5000'] as $sql) $db->exec($sql);
    return $db;
}
function i_readme_text(): string
{
    $text = is_file(__DIR__ . '/README.md') ? (string)file_get_contents(__DIR__ . '/README.md') : '';
    $text = preg_replace('/\A#.*?\n+/s', '', $text, 1) ?? $text;
    $text = trim($text);
    return $text !== '' ? $text : '欢迎使用 bbs1org。';
}
function i_save_cache(PDO $db): void
{
    if (!is_dir(INSTALL_CACHE_DIR)) mkdir(INSTALL_CACHE_DIR, 0755, true);
    $forums = $db->query("SELECT id,name,description,sort,last_topic_id,last_topic_title FROM forums ORDER BY sort,id")->fetchAll();
    file_put_contents(INSTALL_FORUM_CACHE_FILE, "<?php\nreturn " . var_export($forums, true) . ";\n", LOCK_EX);
    $groups = $db->query("SELECT id,name,allow_manage,allow_admin FROM groups ORDER BY id")->fetchAll();
    file_put_contents(INSTALL_GROUP_CACHE_FILE, "<?php\nreturn " . var_export($groups, true) . ";\n", LOCK_EX);
    $stats = [
        'topics' => (int)$db->query("SELECT COUNT(*) FROM topics")->fetchColumn(),
        'replies' => (int)$db->query("SELECT COUNT(*) FROM replies")->fetchColumn(),
        'users' => (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn(),
        'latest_users' => $db->query("SELECT id,username,avatar_style,avatar_seed FROM users ORDER BY id DESC LIMIT 8")->fetchAll(),
    ];
    file_put_contents(INSTALL_STATS_CACHE_FILE, "<?php\nreturn " . var_export($stats, true) . ";\n", LOCK_EX);
    $settings = array_column($db->query("SELECT name,value FROM settings")->fetchAll(), 'value', 'name');
    file_put_contents(INSTALL_SETTING_CACHE_FILE, "<?php\nreturn " . var_export($settings, true) . ";\n", LOCK_EX);
}
function i_html(string $title, string $body): void
{
    $meta = '<meta name="viewport" content="width=device-width,initial-scale=1"><meta charset="utf-8">';
    echo '<!doctype html><html lang="zh-CN"><head>' . $meta . '<title>' . i_h($title) . '</title><link rel="icon" type="image/svg+xml" href="logo.svg"><style>
    :root{--bg:#eef2f7;--panel:#fff;--line:#dfe6ee;--line2:#edf1f5;--text:#1f2937;--muted:#6b7280;--brand:#2563eb;--brand2:#1d4ed8;--ok:#059669;--warn:#b45309;--danger:#dc2626;--radius:10px}
    *{box-sizing:border-box}body{margin:0;background:linear-gradient(180deg,#f8fbff 0,#eef2f7 100%);color:var(--text);font:14px/1.6 -apple-system,BlinkMacSystemFont,"PingFang SC","Microsoft YaHei",sans-serif}
    a{color:var(--brand);text-decoration:none}a:hover{color:var(--brand2)}.wrap{max-width:1060px;margin:0 auto;padding:24px 16px 40px}
    .hero{display:grid;gap:8px;margin-bottom:18px}.hero h1{margin:0;font-size:28px;line-height:1.2}.hero p{margin:0;color:var(--muted)}
    .grid{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(0,.85fr);gap:16px;align-items:start}.card{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);box-shadow:0 10px 24px rgba(15,23,42,.05)}
    .card .hd{padding:16px 18px;border-bottom:1px solid var(--line2)}.card .hd h2{margin:0;font-size:16px}.card .bd{padding:16px 18px}
    .note{padding:12px 14px;border:1px solid #dbeafe;background:#eff6ff;color:#1e3a8a;border-radius:8px}.warn{border-color:#fde68a;background:#fffbeb;color:#92400e}.ok{border-color:#bbf7d0;background:#f0fdf4;color:#166534}
    .form{display:grid;gap:12px}.row{display:grid;gap:6px}.row label{font-size:12px;color:var(--muted)}.row small{color:var(--muted);font-size:11px;line-height:1.4}.row.compact{grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:10px}.row.compact .field{display:grid;gap:6px}input[type=text],input[type=password],input[type=email],textarea{width:100%;border:1px solid #d6dbe3;border-radius:8px;padding:10px 12px;font:inherit;background:#fff;color:var(--text)}textarea{min-height:128px;resize:vertical}
    input:focus,textarea:focus{outline:0;border-color:#93c5fd;box-shadow:0 0 0 3px rgba(59,130,246,.12)}.checks{display:grid;gap:10px}.check{display:flex;gap:10px;align-items:flex-start;padding:10px 12px;border:1px solid var(--line2);border-radius:8px;background:#fafcff}.check input{margin-top:3px}
    .actions{display:flex;gap:10px;align-items:center;justify-content:flex-end}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;padding:0 16px;border:0;border-radius:8px;background:var(--brand);color:#fff;cursor:pointer;font:inherit;font-weight:600}.btn:hover{background:var(--brand2);color:#fff}.btn.alt{background:#fff;color:#374151;border:1px solid #d1d5db}.btn.alt:hover{background:#f8fafc;color:#111;border-color:#cbd5e1}
    .list{margin:0;padding-left:18px;color:#374151}.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;overflow-wrap:anywhere;word-break:break-word}.kv{display:grid;grid-template-columns:120px minmax(0,1fr);gap:8px 12px;font-size:13px}.kv div{min-width:0}.kv div:nth-child(odd){color:var(--muted)}.kv div:nth-child(even){overflow-wrap:anywhere;word-break:break-word}.admin-pass{padding:14px;border:1px solid #fecaca;background:#fff1f2;color:#991b1b;border-radius:8px;word-break:break-all}.footer{margin-top:16px;color:var(--muted);font-size:12px;text-align:center}
    @media (max-width:860px){.grid{grid-template-columns:1fr}.hero h1{font-size:24px}.wrap{padding:18px 12px 30px}}
    </style></head><body><main class="wrap">' . $body . '</main></body></html>';
    exit;
}
function i_result(string $title, string $admin_user, string $admin_pass, string $admin_email, string $site_name, string $db_name): void
{
    i_html($title, '<div class="hero"><h1>安装完成</h1><p>站点已初始化，管理员账号已创建。</p></div><div class="grid"><section class="card"><div class="hd"><h2>安装结果</h2></div><div class="bd"><div class="note ok">可以直接进入论坛使用，建议立即登录后台修改密码。</div><div style="height:12px"></div><div class="kv"><div>站点名</div><div>' . i_h($site_name) . '</div><div>数据库文件</div><div class="mono">data/' . i_h($db_name) . '</div><div>管理员用户名</div><div class="mono">' . i_h($admin_user) . '</div><div>管理员邮箱</div><div class="mono">' . i_h($admin_email) . '</div><div>管理员密码</div><div class="admin-pass mono">' . i_h($admin_pass) . '</div></div><div style="height:14px"></div><div class="actions"><a class="btn alt" href="index.php">进入首页</a><a class="btn" href="index.php?a=admin">进入后台</a></div></div></section><aside class="card"><div class="hd"><h2>已完成内容</h2></div><div class="bd"><ul class="list"><li>创建随机文件名 SQLite 数据库</li><li>创建默认版块</li><li>创建第一个管理员</li><li>导入 README 作为首个主题</li><li>生成缓存文件</li><li>管理员密码不会保存到文件</li></ul></div></aside></div><div class="footer">请立即保存本页显示的管理员密码，离开后无法再次查看。</div>');
}
function i_locked(): void
{
    i_html('安装已锁定', '<div class="hero"><h1>安装已锁定</h1><p>站点已安装，且所有迁移均已执行，无需安装或升级。</p></div><div class="card"><div class="bd"><div class="note ok">当前已是最新状态。如需重新安装，请先删除安装锁文件 <span class="mono">data/install.lock</span> 后再访问。</div><div style="height:14px"></div><div class="actions"><a class="btn" href="index.php">进入首页</a></div></div></div>');
}

/** 升级场景引导页：install.lock 已存在，但检测到有待执行的迁移（锁不一致）。
 *  此时全量安装入口仍保持锁定，仅引导用户前往迁移管理执行增量迁移。 */
function i_upgrade_available(array $pending): void
{
    $items = '';
    foreach ($pending as $name) $items .= '<li class="mono">' . i_h($name) . '</li>';
    $count = count($pending);
    i_html('发现待执行的迁移', '<div class="hero"><h1>发现待执行的数据库迁移</h1><p>站点已安装（install.lock 存在），检测到有未执行的迁移脚本，属于升级场景。</p></div><div class="grid"><section class="card"><div class="hd"><h2>待执行迁移（' . (string)$count . '）</h2></div><div class="bd"><div class="note warn">当前为<strong>增量升级</strong>：全量安装入口已锁定，不会重复安装或清理数据，仅可执行下方迁移。</div><div style="height:14px"></div><ul class="list">' . $items . '</ul><div style="height:14px"></div><div class="actions"><a class="btn" href="install.php?do=migrations">前往迁移管理执行</a><a class="btn alt" href="index.php">进入首页</a></div></div></section><aside class="card"><div class="hd"><h2>说明</h2></div><div class="bd"><ul class="list"><li><span class="mono">install.lock</span> 已存在：全量安装已被禁止</li><li>存在缺少 <span class="mono">.lock</span> 的迁移：判定为增量升级</li><li>所有迁移执行完成、锁文件齐全后，本页将恢复为「已锁定」</li><li>迁移会直接修改数据库结构，执行前请备份 <span class="mono">data/</span> 目录</li></ul></div></aside></div>');
}
function i_form(string $site_name, string $admin_user, string $admin_email, string $admin_pass, string $default_forum, string $topic_title): void
{
    i_html('安装 bbs1org', '<div class="hero"><h1>安装 bbs1org</h1><p>一页完成初始化，创建管理员、默认版块和首个主题。</p></div><div class="grid"><section class="card"><div class="hd"><h2>安装配置</h2></div><div class="bd"><form class="form" method="post"><input type="hidden" name="step" value="install"><div class="row"><label>站点名称</label><input type="text" name="site_name" value="' . i_h($site_name) . '" placeholder="我的论坛" required></div><div class="row"><label>管理员用户名</label><input type="text" name="admin_username" value="' . i_h($admin_user) . '" placeholder="admin" required></div><div class="row"><label>管理员邮箱</label><input type="email" name="admin_email" value="' . i_h($admin_email) . '" placeholder="name@example.com" required><small>用于找回密码与通知。</small></div><div class="row"><label>管理员密码</label><input type="password" name="admin_password" value="' . i_h($admin_pass) . '" placeholder="请输入密码" required></div><div class="row"><label>确认管理员密码</label><input type="password" name="admin_password2" value="' . i_h($admin_pass) . '" placeholder="再次输入密码" required></div><div class="row"><label>默认版块名称</label><input type="text" name="forum_name" value="' . i_h($default_forum) . '" required></div><div class="row"><label>首个主题标题</label><input type="text" name="topic_title" value="' . i_h($topic_title) . '" required></div><div class="row"><label>首个主题内容</label><textarea name="topic_body" required>' . i_h(i_readme_text()) . '</textarea></div><div class="checks"><label class="check"><input type="checkbox" name="confirm_clean" value="1" required><span>我确认这是全新安装，数据将被清理。</span></label><label class="check"><input type="checkbox" name="confirm_admin" value="1" required><span>我确认需要手工设置第一个管理员密码。</span></label><label class="check"><input type="checkbox" name="confirm_readme" value="1" required><span>我确认将 README 内容作为第一个主题发布。</span></label></div><div class="actions"><button class="btn" type="submit">开始安装</button></div></form></div></section><aside class="card"><div class="hd"><h2>安装说明</h2></div><div class="bd"><ul class="list"><li>会创建默认用户组和默认版块</li><li>第一个管理员将拥有全部权限</li><li>管理员邮箱可用于找回密码</li><li>README 将作为论坛首帖发布</li></ul></div></aside></div>');
}

/**
 * 迁移管理：扫描 migrate/ 目录，列出迁移文件并按需执行。
 * 迁移文件名格式：migrate_YYYY_MM_DD_NN.php
 * 执行成功后在 data/ 下生成同名 .lock 文件，已存在锁文件的迁移不再重复执行。
 */

/** 扫描 migrate 目录，返回所有 migrate_*.php 文件（按文件名升序）的 basename 数组。目录不存在返回空数组。 */
function i_migration_files(): array
{
    $dir = __DIR__ . '/migrate';
    if (!is_dir($dir)) return [];
    $list = [];
    foreach ((array)glob($dir . '/migrate_*.php') as $path) {
        if (is_file($path)) $list[] = basename($path);
    }
    sort($list, SORT_STRING);
    return $list;
}

/** 对每个迁移文件判断锁状态，返回结构化数组：[['name'=>basename,'locked'=>bool], ...]（按 name 升序）。 */
function i_migration_pending(): array
{
    $out = [];
    foreach (i_migration_files() as $name) {
        $out[] = ['name' => $name, 'locked' => is_file(INSTALL_DATA_DIR . '/' . $name . '.lock')];
    }
    return $out;
}

/** 返回待执行迁移的 basename 列表（按名升序）：迁移文件存在但缺少对应 .lock。
 *  用于区分「全量安装已锁定」与「已安装但有增量迁移待执行（升级场景）」。 */
function i_pending_migrations(): array
{
    $pending = [];
    foreach (i_migration_files() as $name) {
        if (!is_file(INSTALL_DATA_DIR . '/' . $name . '.lock')) $pending[] = $name;
    }
    return $pending;
}

/** 校验迁移文件名白名单并返回其在 migrate 目录内的绝对路径；非法或不在目录内返回 null。 */
function i_migration_resolve(?string $name): ?string
{
    if ($name === null || $name === '') return null;
    // 仅允许 ^[A-Za-z0-9_\-]+\.php$ 形式的 basename
    if (!preg_match('/^[A-Za-z0-9_\-]+\.php$/', $name)) return null;
    $dir = __DIR__ . '/migrate';
    $path = $dir . '/' . $name;
    // realpath 双重校验，防止 .. 穿越并确认文件确实位于 migrate 目录内
    $realDir = realpath($dir);
    $realPath = realpath($path);
    if ($realDir === false || $realPath === false) return null;
    if (dirname($realPath) !== $realDir) return null;
    return $realPath;
}

/** 渲染迁移管理页（只读列出）。 */
function i_migrations_page(): void
{
    $rows = i_migration_pending();
    $total = count($rows);
    $executed = 0;
    foreach ($rows as $r) if ($r['locked']) $executed++;
    $pendingCount = $total - $executed;

    $body = '<div class="hero"><h1>数据库迁移</h1><p>管理 <span class="mono">migrate/</span> 目录下的数据库结构迁移脚本。</p></div>';
    $body .= '<div class="grid"><section class="card"><div class="hd"><h2>迁移列表</h2></div><div class="bd">';
    $body .= '<div class="note">迁移文件位于 <span class="mono">migrate/</span> 目录，执行成功后会在 <span class="mono">data/</span> 目录生成同名 <span class="mono">.lock</span> 锁文件；已执行（有锁）的迁移不会重复执行。</div>';
    $body .= '<div style="height:14px"></div>';
    $body .= '<div class="kv"><div>迁移总数</div><div class="mono">' . (string)$total . '</div><div>已执行</div><div class="mono">' . (string)$executed . '</div><div>待执行</div><div class="mono">' . (string)$pendingCount . '</div></div>';
    $body .= '<div style="height:16px"></div>';

    if ($total === 0) {
        $body .= '<div class="note warn">暂无迁移文件。</div>';
    } elseif ($pendingCount === 0) {
        $body .= '<div class="note ok">所有迁移均已执行。</div>';
    }

    if ($total > 0) {
        $body .= '<div style="height:14px"></div>';
        $body .= '<div class="kv" style="grid-template-columns:minmax(0,1fr) auto auto;gap:10px 12px">';
        foreach ($rows as $r) {
            $name = $r['name'];
            if ($r['locked']) {
                $status = '<span style="color:var(--ok);font-weight:600">已执行</span>';
                $action = '<span class="mono" style="color:var(--muted)">—</span>';
            } else {
                $status = '<span style="color:var(--warn);font-weight:600">待执行</span>';
                $action = '<a class="btn" style="min-height:30px;padding:0 12px;background:var(--warn)" href="install.php?do=migration_run&amp;file=' . i_h(rawurlencode($name)) . '&amp;confirm=1" onclick="return confirm(\'确定执行该迁移？将直接修改数据库结构。\')">执行</a>';
            }
            $body .= '<div class="mono">' . i_h($name) . '</div><div>' . $status . '</div><div>' . $action . '</div>';
        }
        $body .= '</div>';
    }

    $body .= '<div style="height:16px"></div>';
    $body .= '<div class="actions"><a class="btn alt" href="install.php?do=migrations">刷新</a><a class="btn" href="index.php">返回首页</a></div>';
    $body .= '</div></section><aside class="card"><div class="hd"><h2>使用说明</h2></div><div class="bd"><ul class="list"><li>迁移文件名格式：<span class="mono">migrate_YYYY_MM_DD_NN.php</span></li><li>迁移会直接修改数据库结构，执行前请先备份 <span class="mono">data/</span> 目录</li><li>每个迁移只能执行一次，执行后生成同名 <span class="mono">.lock</span> 锁文件</li><li>如需重新执行，删除对应的 <span class="mono">.lock</span> 文件</li><li>迁移脚本内直接使用 <span class="mono">$db</span> 变量执行 SQL</li></ul></div></aside></div>';

    i_html('数据库迁移', $body);
}

/** 执行一个迁移：要求 confirm=1，校验文件白名单，include 后写锁文件；失败回显错误且不写锁。 */
function i_migration_run(): void
{
    $name = isset($_GET['file']) ? (string)$_GET['file'] : '';
    $path = i_migration_resolve($name);
    if ($path === null) {
        i_html('迁移 - 文件无效', '<div class="hero"><h1>迁移文件无效</h1><p>请求的迁移文件不存在或名称非法。</p></div><div class="card"><div class="bd"><div class="note warn">文件名只允许字母、数字、下划线、连字符及 <span class="mono">.php</span> 后缀，且必须位于 <span class="mono">migrate/</span> 目录内。</div><div style="height:14px"></div><div class="actions"><a class="btn" href="install.php?do=migrations">返回迁移列表</a><a class="btn alt" href="index.php">返回首页</a></div></div></div>');
    }

    // 必须带 confirm=1，否则重定向回列表
    if (!isset($_GET['confirm']) || (string)$_GET['confirm'] !== '1') {
        header('Location: install.php?do=migrations');
        exit;
    }

    $lockFile = INSTALL_DATA_DIR . '/' . basename($name) . '.lock';
    if (is_file($lockFile)) {
        i_html('迁移 - 已执行', '<div class="hero"><h1>迁移已执行</h1><p>该迁移此前已成功执行，对应的锁文件已存在。</p></div><div class="card"><div class="bd"><div class="note ok">文件：<span class="mono">' . i_h(basename($name)) . '</span></div><div style="height:14px"></div><div class="actions"><a class="btn" href="install.php?do=migrations">返回迁移列表</a><a class="btn alt" href="index.php">返回首页</a></div></div></div>');
    }

    $base = basename($name);
    try {
        // 写锁文件前确保 data 目录存在
        if (!is_dir(INSTALL_DATA_DIR)) mkdir(INSTALL_DATA_DIR, 0755, true);
        $db = i_db();
        // 迁移文件内直接使用 $db 变量执行 $db->exec(...)
        $result = include $path;
        // include 返回 false 通常意味着加载/语法错误
        if ($result === false) {
            throw new RuntimeException('迁移文件 include 返回 false，可能存在语法或加载错误。');
        }
        // 成功：写锁文件
        if (file_put_contents($lockFile, (string)time(), LOCK_EX) === false) {
            throw new RuntimeException('迁移已执行，但写入锁文件失败：' . $lockFile);
        }
        i_html('迁移执行成功', '<div class="hero"><h1>迁移执行成功</h1><p>该迁移已成功应用并写入锁文件。</p></div><div class="card"><div class="bd"><div class="note ok">已执行：<span class="mono">' . i_h($base) . '</span></div><div style="height:14px"></div><div class="actions"><a class="btn" href="install.php?do=migrations">返回迁移列表</a><a class="btn alt" href="index.php">返回首页</a></div></div></div>');
    } catch (Throwable $e) {
        // 失败时不写锁文件
        i_html('迁移执行失败', '<div class="hero"><h1>迁移执行失败</h1><p>迁移未完成，未生成锁文件，可修正后重试。</p></div><div class="card"><div class="bd"><div class="note warn">迁移会直接修改数据库结构，请确认源文件无误后再执行。</div><div style="height:12px"></div><div class="kv"><div>迁移文件</div><div class="mono">' . i_h($base) . '</div><div>错误信息</div><div class="mono" style="color:var(--danger)">' . i_h($e->getMessage()) . '</div></div><div style="height:14px"></div><div class="actions"><a class="btn" href="install.php?do=migrations">返回迁移列表</a><a class="btn alt" href="index.php">返回首页</a></div></div></div>');
    }
}

i_check_sqlite_support();

// 迁移管理分发：必须在安装锁检查之前处理并自行 exit，以便站点安装后仍可访问迁移页
$do = (string)($_GET['do'] ?? '');
if ($do === 'migration_run') i_migration_run();   // 内部 exit
if ($do === 'migrations') i_migrations_page();    // 内部 exit

if (is_file(INSTALL_LOCK_FILE)) {
    // 区分全量安装与升级：install.lock 存在时，需对比所有迁移锁是否一致。
    // 存在缺少 .lock 的迁移 → 升级场景（引导执行迁移）；锁完全一致 → 全量锁定。
    $pending = i_pending_migrations();
    if ($pending) i_upgrade_available($pending);   // 内部 exit
    i_locked();                                    // 内部 exit：所有锁一致，已是最新
}
$step = (string)($_POST['step'] ?? '');
if ($step !== 'install') {
    i_form('我的论坛', 'admin', '', '', '默认版块', '欢迎使用 bbs1org');
}
if (!isset($_POST['confirm_clean'], $_POST['confirm_admin'], $_POST['confirm_readme'])) i_form('我的论坛', 'admin', '', '', '默认版块', '欢迎使用 bbs1org');
$site_name = trim((string)($_POST['site_name'] ?? '我的论坛'));
$admin_username = trim((string)($_POST['admin_username'] ?? 'admin'));
$admin_email = trim((string)($_POST['admin_email'] ?? ''));
$admin_password = (string)($_POST['admin_password'] ?? '');
$admin_password2 = (string)($_POST['admin_password2'] ?? '');
$forum_name = trim((string)($_POST['forum_name'] ?? '默认版块'));
$topic_title = trim((string)($_POST['topic_title'] ?? '欢迎使用 bbs1org'));
$topic_body = trim((string)($_POST['topic_body'] ?? ''));
if ($site_name === '' || $admin_username === '' || $admin_email === '' || $admin_password === '' || $forum_name === '' || $topic_title === '' || $topic_body === '') i_form($site_name ?: '我的论坛', $admin_username ?: 'admin', $admin_email, $admin_password, $forum_name ?: '默认版块', $topic_title ?: '欢迎使用 bbs1org');
if ($admin_password !== $admin_password2) i_form($site_name, $admin_username, $admin_email, $admin_password, $forum_name, $topic_title);
if (is_file(INSTALL_LOCK_FILE)) i_locked();
$db = i_db();
$db->exec("
CREATE TABLE IF NOT EXISTS groups(id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL UNIQUE,allow_manage INTEGER NOT NULL DEFAULT 0,allow_admin INTEGER NOT NULL DEFAULT 0);
CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY AUTOINCREMENT,username TEXT NOT NULL UNIQUE,password TEXT NOT NULL,email TEXT NOT NULL DEFAULT '',bio TEXT NOT NULL DEFAULT '',avatar_style TEXT NOT NULL DEFAULT '',avatar_seed TEXT NOT NULL DEFAULT '',group_id INTEGER NOT NULL DEFAULT 2,is_banned INTEGER NOT NULL DEFAULT 0,is_muted INTEGER NOT NULL DEFAULT 0,unread_notifications INTEGER NOT NULL DEFAULT 0,last_post_at INTEGER NOT NULL DEFAULT 0,created_at INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS trash(id INTEGER PRIMARY KEY AUTOINCREMENT,table_name TEXT NOT NULL,row_id INTEGER NOT NULL,row_data TEXT NOT NULL,deleted_by INTEGER NOT NULL DEFAULT 0,created_at INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS notifications(id INTEGER PRIMARY KEY AUTOINCREMENT,recipient_id INTEGER NOT NULL,sender_id INTEGER DEFAULT NULL,kind TEXT NOT NULL DEFAULT 'direct',content TEXT NOT NULL,topic_id INTEGER DEFAULT NULL,reply_id INTEGER DEFAULT NULL,read_at INTEGER NOT NULL DEFAULT 0,created_at INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS forums(id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL,description TEXT NOT NULL DEFAULT '',sort INTEGER NOT NULL DEFAULT 0,allow_view_groups TEXT NOT NULL DEFAULT '',allow_post_groups TEXT NOT NULL DEFAULT '',allow_reply_groups TEXT NOT NULL DEFAULT '',last_topic_id INTEGER NOT NULL DEFAULT 0,last_topic_title TEXT NOT NULL DEFAULT '');
CREATE TABLE IF NOT EXISTS topics(id INTEGER PRIMARY KEY AUTOINCREMENT,forum_id INTEGER NOT NULL,user_id INTEGER NOT NULL,title TEXT NOT NULL,body TEXT NOT NULL,highlight_style TEXT NOT NULL DEFAULT '',reply_count INTEGER NOT NULL DEFAULT 0,view_count INTEGER NOT NULL DEFAULT 0,last_reply_at INTEGER NOT NULL DEFAULT 0,created_at INTEGER NOT NULL,updated_at INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS replies(id INTEGER PRIMARY KEY AUTOINCREMENT,topic_id INTEGER NOT NULL,user_id INTEGER NOT NULL,body TEXT NOT NULL,created_at INTEGER NOT NULL,updated_at INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS favorites(user_id INTEGER NOT NULL,topic_id INTEGER NOT NULL,created_at INTEGER NOT NULL,PRIMARY KEY(user_id,topic_id));
CREATE TABLE IF NOT EXISTS password_resets(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,token_hash TEXT NOT NULL UNIQUE,expires_at INTEGER NOT NULL,used_at INTEGER NOT NULL DEFAULT 0,created_at INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS ip_logs(ip TEXT PRIMARY KEY,register_count INTEGER NOT NULL DEFAULT 0,register_at INTEGER NOT NULL DEFAULT 0,login_fail_count INTEGER NOT NULL DEFAULT 0,login_fail_at INTEGER NOT NULL DEFAULT 0,reset_fail_count INTEGER NOT NULL DEFAULT 0,reset_fail_at INTEGER NOT NULL DEFAULT 0,created_at INTEGER NOT NULL,updated_at INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS settings(name TEXT PRIMARY KEY,value TEXT NOT NULL DEFAULT '');
");
$db->exec("CREATE INDEX IF NOT EXISTS idx_users_group ON users(group_id)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_forums_sort ON forums(sort,id)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_topics_user ON topics(user_id,id DESC)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_replies_topic_time ON replies(topic_id,created_at,id)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_replies_user ON replies(user_id,id DESC)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_replies_user_time ON replies(user_id,created_at DESC,id DESC)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_replies_user_topic_time ON replies(user_id,topic_id,created_at DESC,id DESC)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_notifications_recipient_read ON notifications(recipient_id,read_at,created_at DESC,id DESC)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_notifications_recipient_time ON notifications(recipient_id,created_at DESC,id DESC)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_notifications_sender ON notifications(sender_id,id DESC)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_password_resets_user ON password_resets(user_id,created_at DESC)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_ip_logs_updated ON ip_logs(updated_at DESC)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_topics_created ON topics(created_at DESC,id DESC)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_topics_last_reply ON topics(last_reply_at DESC,id DESC)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_topics_user_updated ON topics(user_id,updated_at DESC,id DESC)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_topics_forum_created ON topics(forum_id,created_at DESC,id DESC)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_topics_forum_last_reply ON topics(forum_id,last_reply_at DESC,id DESC)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_users_created ON users(id DESC)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_favorites_user_created ON favorites(user_id,created_at DESC)");
$db->exec("INSERT OR IGNORE INTO groups(id,name,allow_manage,allow_admin) VALUES(1,'管理员',1,1),(2,'会员',0,0)");
$db->exec("INSERT OR IGNORE INTO forums(id,name,description,sort,last_topic_id,last_topic_title) VALUES(1," . $db->quote($forum_name) . ",'欢迎发帖',0,0,'')");
$settings = [
    'site_name' => $site_name,
    'site_keywords' => '',
    'site_description' => '',
    'header_html' => '',
    'footer_html' => '',
    'site_closed' => '0',
    'allow_register' => '1',
    'pretty_url' => '0',
    'reserved_usernames' => 'admin,administrator,root,system',
    'default_group_id' => '2',
    'topics_per_page' => '30',
    'replies_per_page' => '50',
    'mail_from' => '',
    'mail_virtual' => '0',
    'register_per_hour' => '1',
    'login_fail_per_hour' => '5',
    'reset_fail_per_hour' => '5',
    'captcha_charset' => 'alnum',
    'captcha_forms' => '',
    'post_interval_seconds' => '5',
    'pinned_topic_ids' => '',
];
$stmt = $db->prepare("INSERT OR REPLACE INTO settings(name,value) VALUES(?,?)");
foreach ($settings as $name => $value) $stmt->execute([$name, $value]);
$admin_pass = $admin_password;
$welcome_ts = i_now();
$db->prepare("INSERT INTO users(username,password,email,bio,avatar_style,avatar_seed,group_id,last_post_at,created_at) VALUES(?,?,?,?,?,?,?,?,?)")->execute([$admin_username, password_hash($admin_pass, PASSWORD_DEFAULT), $admin_email, '站点管理员', '', '', 1, $welcome_ts, $welcome_ts]);
$admin_id = (int)$db->lastInsertId();
$readme = i_readme_text();
$db->prepare("INSERT INTO topics(forum_id,user_id,title,body,highlight_style,reply_count,view_count,last_reply_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)")->execute([1, $admin_id, $topic_title, $readme, '', 0, 0, $welcome_ts, $welcome_ts, $welcome_ts]);
$topic_id = (int)$db->lastInsertId();
$db->prepare("UPDATE forums SET last_topic_id=?,last_topic_title=? WHERE id=1")->execute([$topic_id, $topic_title]);
if (!is_dir(INSTALL_CACHE_DIR)) mkdir(INSTALL_CACHE_DIR, 0755, true);
i_save_cache($db);
file_put_contents(INSTALL_LOCK_FILE, (string)i_now(), LOCK_EX);
i_result('安装完成', $admin_username, $admin_pass, $admin_email, $site_name, basename(i_db_file()));
