<?php
// 给 users 增加 last_post_at 列（记录用户最后一次发帖/回复的时间戳，用于发帖频率控制）
// 该字段在新版 install.php 建表语句中已存在，但旧库从更早版本安装时缺失，本迁移用于补齐。
// 使用 PRAGMA table_info 先检查列是否已存在，保证幂等可重复执行
$add_column_if_missing = static function (string $table, string $column, string $def) use ($db): void {
    $cols = $db->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array($column, $cols, true)) {
        $db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$def}");
    }
};
$add_column_if_missing('users', 'last_post_at', 'INTEGER NOT NULL DEFAULT 0');
