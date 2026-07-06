<?php

declare(strict_types=1);

namespace Chep6915\HyperfScheduledTask\Process;

use Hyperf\Process\AbstractProcess;
use Hyperf\Process\Annotation\Process;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Database\ConnectionInterface;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Cron\CronExpression;
use Swoole\Timer as SwooleTimer;
use Throwable;

#[Process(name: "HighFrequencySchedulerProcess")]
class HighFrequencySchedulerProcess extends AbstractProcess
{
    /**
     * 記憶體快取：儲存目前所有啟用的排程任務
     */
    private array $scheduledTasks = [];

    /**
     * 狀態鎖陣列：[ 任務ID => 上次成功觸發的秒級時間戳 ]
     */
    private array $taskLastTriggeredMap = [];

    /**
     * 決定這個進程是否隨著 Hyperf 啟動而自動執行
     */
    public bool $enable = true;

    public function handle(): void
    {
        $container = ApplicationContext::getContainer();
        $logger = $container->get(StdoutLoggerInterface::class);
        $db = $container->get(ConnectionInterface::class);
        $driverFactory = $container->get(DriverFactory::class);

        $logger->info("🚀 高精度多頻率排程守護進程（Producer）已成功啟動...");

        // 1. 啟動時先同步一次資料庫的排程清單
        $this->refreshTasksFromDatabase($db, $logger);

        // 2. 設定一個「每 60 秒」執行的常規時鐘，用來定時刷新資料庫配置（動態上下架排程）
        $refreshIntervalMs = 60 * 1000;
        SwooleTimer::tick($refreshIntervalMs, function () use ($db, $logger) {
            $this->refreshTasksFromDatabase($db, $logger);
        });

        // 3. 🔑 核心：開啟 1 毫秒打點一次的終極高頻微秒時鐘
        SwooleTimer::tick(1, function () use ($db, $driverFactory, $logger) {
            $now = time(); // 取得當前秒級時間戳（例如：1719920015）

            foreach ($this->scheduledTasks as $task) {
                $taskId = $task['id'];
                $cronExpr = $task['cron_expression'];

                // 鎖定檢查：如果該任務在當前這秒已經被派發過了，剩下的毫秒直接跳過
                if (isset($this->taskLastTriggeredMap[$taskId]) && $now === $this->taskLastTriggeredMap[$taskId]) {
                    continue;
                }

                try {
                    // 利用標準 Cron 解析器判斷這一秒是否該觸發
                    $cron = new CronExpression($cronExpr);
                    if ($cron->isDue(date('Y-m-d H:i:s', $now))) {

                        // 💥 秒速鎖定！防止本秒內接下來的毫秒重複觸發
                        $this->taskLastTriggeredMap[$taskId] = $now;

                        // 丟進非同步協程，完全不卡住每毫秒的打點主時鐘
                        co(function () use ($task, $now, $db, $driverFactory, $logger) {
                            $this->dispatchTask($task, $now, $db, $driverFactory, $logger);
                        });
                    }
                } catch (Throwable $e) {
                    // 防止單一任務的 Cron 格式錯誤導致整個每毫秒時鐘崩潰
                    $logger->error("❌ 排程 ID {$taskId} 的 Cron 表達式解析錯誤: " . $e->getMessage());
                }
            }
        });
    }

    /**
     * 從資料庫動態載入最新排程配置到記憶體中
     */
    private function refreshTasksFromDatabase(ConnectionInterface $db, StdoutLoggerInterface $logger): void
    {
        try {
            // 只撈取啟用中 (status = 1) 且未被軟刪除的排程
            $tasks = $db->table('scheduled_tasks')
                ->where('status', 1)
                ->whereNull('deleted_at')
                ->get(['id', 'name', 'execute_class', 'cron_expression'])
                ->toArray();

            // 轉換為純陣列儲存
            $this->scheduledTasks = json_decode(json_encode($tasks), true);

            $logger->debug("🔄 [排程同步] 已成功從資料庫同步 " . count($this->scheduledTasks) . " 筆啟用中的排程至記憶體快取。");
        } catch (Throwable $e) {
            $logger->error("❌ [同步失敗] 無法從資料庫載入排程配置: " . $e->getMessage());
        }
    }

    /**
     * 核心派發動作：1. 生成 pending 紀錄 2. 推入非同步隊列
     */
    private function dispatchTask(array $task, int $timestamp, ConnectionInterface $db, DriverFactory $driverFactory, StdoutLoggerInterface $logger): void
    {
        $planTime = date('Y-m-d H:i:s', $timestamp);

        try {
            // 🔑 動作一：在 task_execution_logs 資料庫中建立一筆 pending (待執行) 紀錄
            // 使用帶有微秒精度的當前時間填入 created_at
            $logId = $db->table('task_execution_logs')->insertGetId([
                'task_id' => $task['id'],
                'task_name' => $task['name'],
                'status' => 'pending',
                'plan_execute_time' => $planTime,
                'created_at' => date('Y-m-d H:i:s.v'),
                'updated_at' => date('Y-m-d H:i:s.v'),
            ]);

            $logger->notice("📝 [紀錄生成] 任務【{$task['name']}】已成功產生 pending 紀錄 (Log ID: {$logId})。");

            // 🔑 動作二：將要執行的任務 Class 與對應的 Log ID 打包，推入 Hyperf Async Queue
            // 假設你套件或專案中未來會有一個通用的非同步執行 Job 叫單入 `AbstractCronTaskJob`
            if (class_exists($task['execute_class'])) {
                $driverFactory->get('default')->push(
                    new $task['execute_class']($logId, $task)
                );
            } else {
                throw new \RuntimeException("找不到對應的執行類別: " . $task['execute_class']);
            }

        } catch (Throwable $e) {
            $logger->error("❌ [派發失敗] 任務【{$task['name']}】在派發過程中發生異常: " . $e->getMessage());
        }
    }
}