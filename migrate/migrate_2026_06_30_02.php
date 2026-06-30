<?php
// 插入 IP 归属地功能相关的默认设置项
// 使用 INSERT OR IGNORE 保证幂等：已存在的设置不会被覆盖
$stmt = $db->prepare("INSERT OR IGNORE INTO settings(name,value) VALUES(?,?)");
$stmt->execute(['ip_location_enabled', '0']);
$stmt->execute(['ip_location_granularity', 'province']);
$stmt->execute(['ip_location_api_base', 'http://81.70.36.26:18184']);
