# 資料庫連接池配置指南

## 問題說明

當出現以下錯誤時：
```
Connection pool exhausted. Cannot establish new connection before wait_timeout.
```

表示資料庫連接池的連接數不足以支撐當前的並發需求。

## 解決方案

### 方案 1：調整連接池大小（推薦）

編輯 `config/autoload/databases.php`：

```php
<?php
return [
    'default' => [
        'driver' => env('DB_DRIVER', 'mysql'),
        'host' => env('DB_HOST', 'localhost'),
        'port' => env('DB_PORT', 3306),
        'database' => env('DB_DATABASE', 'hyperf'),
        'username' => env('DB_USERNAME', 'root'),
        'password' => env('DB_PASSWORD', ''),
        'charset' => env('DB_CHARSET', 'utf8mb4'),
        'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
        'prefix' => env('DB_PREFIX', ''),
        'pool' => [
            'min_connections' => 5,        // 最小連接數
            'max_connections' => 50,       // 最大連接數（增加此值）
            'connect_timeout' => 10.0,     // 連接超時時間
            'wait_timeout' => 3.0,         // 等待連接超時時間
            'heartbeat' => -1,             // 心跳檢測
            'max_idle_time' => 60.0,       // 最大閒置時間
        ],
    ],
];
```

### 方案 2：降低並發數

如果無法調整連接池大小，可以降低 Consumer 的並發數。

編輯 `vendor/chep6915/hyperf-scheduled-task/src/Process/ScheduledTaskConsumerProcess.php`：

```php
$parallel = new Parallel(5);  // 從 10 降到 5
```

## 建議配置

根據不同場景選擇配置：

### 小型專案（輕量任務）
```php
'pool' => [
    'min_connections' => 3,
    'max_connections' => 20,
],
```
Consumer 並發數：5-10

### 中型專案（一般任務）
```php
'pool' => [
    'min_connections' => 5,
    'max_connections' => 50,
],
```
Consumer 並發數：10-20

### 大型專案（重度任務）
```php
'pool' => [
    'min_connections' => 10,
    'max_connections' => 100,
],
```
Consumer 並發數：20-50

## 計算公式

**最大連接數 ≥ 並發數 × 每個任務需要的連接數**

- 每個協程至少需要 1 個連接
- 如果任務內部有額外查詢，需要更多連接
- 建議預留 20% 的緩衝

例如：
- 並發數 10，每個任務 1 個連接
- 最小連接數 = 10 × 1 × 1.2 = 12
- 建議設定 `max_connections` = 20

## MySQL 伺服器端配置

確保 MySQL 的 `max_connections` 足夠大：

```sql
-- 查看當前最大連接數
SHOW VARIABLES LIKE 'max_connections';

-- 臨時調整（重啟後失效）
SET GLOBAL max_connections = 500;
```

在 `my.cnf` 中永久設定：
```ini
[mysqld]
max_connections = 500
```

## 監控建議

記錄連接池使用情況：

```php
use Hyperf\DbConnection\Pool\PoolFactory;

$factory = $container->get(PoolFactory::class);
$pool = $factory->getPool('default');

echo "當前連接數: " . $pool->getCurrentConnections() . PHP_EOL;
echo "最大連接數: " . $pool->getOption()->getMaxConnections() . PHP_EOL;
```

## 注意事項

1. **不要無限增加連接數**：過多連接會消耗 MySQL 資源
2. **平衡並發與連接數**：並發數應小於最大連接數
3. **考慮其他進程**：其他 Process 和 HTTP 請求也需要連接
4. **使用連接池**：不要手動創建連接，使用 Hyperf 的連接池機制
