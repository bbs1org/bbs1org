<?php
// 给 topics / replies 增加 ip_location 列（记录发帖/回复时的 IP 归属地）
// 给 users 增加最后登录 IP 与归属地字段
// 使用 PRAGMA table_info 先检查列是否已存在，保证幂等可重复执行
$add_column_if_missing = static function (string $table, string $column, string $def) use ($db): void {
    $cols = $db->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array($column, $cols, true)) {
        $db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$def}");
    }
};
$add_column_if_missing('topics', 'ip_location', "TEXT NOT NULL DEFAULT ''");
$add_column_if_missing('replies', 'ip_location', "TEXT NOT NULL DEFAULT ''");
$add_column_if_missing('users', 'last_login_ip', "TEXT NOT NULL DEFAULT ''");
$add_column_if_missing('users', 'last_login_ip_location', "TEXT NOT NULL DEFAULT ''");
