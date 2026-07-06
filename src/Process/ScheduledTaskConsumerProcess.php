<?php

declare(strict_types=1);

namespace Chep6915\HyperfScheduledTask\Process;

use Carbon\Carbon;
use Chep6915\HyperfScheduledTask\Enum\TaskExecutionStatus;
use Hyperf\Process\AbstractProcess;
use Hyperf\Process\Annotation\Process;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Database\ConnectionResolverInterface;
use Swoole\Timer as SwooleTimer;
use Hyperf\Coroutine\Coroutine;
use Hyperf\Coroutine\Parallel;
use Throwable;

#[Process(name: "ScheduledTaskConsumer", nums: 1)]
class ScheduledTaskConsumerProcess extends AbstractProcess
{
    public bool $enable = true;

    public function handle(): void
    {
        $container = ApplicationContext::getContainer();
        $logger = $container->get(StdoutLoggerInterface::class);

        $logger->info("🎯 ScheduledTaskConsumer 已啟動，開始消費 Pending 任務...");

        // 每秒檢查一次待執行的任務
        SwooleTimer::tick(1000, function () use ($logger, $container) {
            $this->consumePendingTasks($logger, $container);
        });

        while (true) {
            sleep(30);
        }
    }

    /**
     * 消費待執行的任務
     */
    private function consumePendingTasks(StdoutLoggerInterface $logger, $container): void
    {
        try {
            // 獲取獨立的資料庫連接用於查詢
            $resolver = $container->get(ConnectionResolverInterface::class);
            $db = $resolver->connection('default');

            $now = Carbon::now()->format('Y-m-d H:i:s');

            // 查詢當前時間應該執行的 Pending 任務
            $pendingLogs = $db->table('task_execution_logs')
                ->where('status', TaskExecutionStatus::PENDING->value)
                ->where('plan_execute_time', '<=', $now)
                ->limit(100) // 每次最多處理 100 筆
                ->get()
                ->toArray();

            if (empty($pendingLogs)) {
                return;
            }

            $logger->debug("📦 發現 " . count($pendingLogs) . " 筆待執行任務，使用協程並發執行");

            // 使用協程並發執行任務（限制並發數避免連接池耗盡）
            $parallel = new Parallel(10); // 降低並發數到 10，避免連接池耗盡

            foreach ($pendingLogs as $log) {
                $parallel->add(function () use ($log, $logger, $container) {
                    // 每個協程獲取獨立的資料庫連接
                    $this->executeTask($log, $logger, $container);
                });
            }

            $parallel->wait();
        } catch (Throwable $e) {
            $logger->error("❌ 消費任務失敗: " . $e->getMessage());
        }
    }

    /**
     * 執行單一任務（優化連接使用，避免長時間佔用）
     */
    private function executeTask($log, StdoutLoggerInterface $logger, $container): void
    {
        $logId = $log->id;
        $taskName = $log->task_name;
        $taskId = $log->task_id;
        $executeClass = null;

        try {
            // ==================== 階段 1：更新狀態為 RUNNING ====================
            $resolver = $container->get(ConnectionResolverInterface::class);
            $db = $resolver->connection('default');

            $now = Carbon::now()->format('Y-m-d H:i:s');
            $updated = $db->table('task_execution_logs')
                ->where('id', $logId)
                ->where('status', TaskExecutionStatus::PENDING->value) // 樂觀鎖
                ->update([
                    'status' => TaskExecutionStatus::RUNNING->value,
                    'started_at' => $now,
                    'updated_at' => $now,
                ]);

            // 如果更新失敗，表示已被其他進程處理
            if ($updated === 0) {
                return;
            }

            // 取得任務執行類別
            $task = $db->table('scheduled_tasks')
                ->where('id', $taskId)
                ->first();

            if (!$task) {
                throw new \RuntimeException("任務不存在 (task_id: {$taskId})");
            }

            $executeClass = $task->execute_class;
            unset($db, $resolver); // 主動釋放連接

            // ==================== 階段 2：執行任務（不持有連接）====================
            $logger->info("▶️  開始執行任務 [{$taskName}] (LogID: {$logId})");

            if (!class_exists($executeClass)) {
                throw new \RuntimeException("執行類別不存在: {$executeClass}");
            }

            $taskInstance = $container->get($executeClass);

            if (!method_exists($taskInstance, 'execute')) {
                throw new \RuntimeException("執行類別缺少 execute() 方法: {$executeClass}");
            }

            $startTime = microtime(true);

            // 執行任務（此時不持有資料庫連接）
            $result = $taskInstance->execute($logId);

            $executionTime = (int)(microtime(true) - $startTime);

            // ==================== 階段 3：更新狀態為 SUCCESS ====================
            $resolver = $container->get(ConnectionResolverInterface::class);
            $db = $resolver->connection('default');

            $completeTime = Carbon::now()->format('Y-m-d H:i:s');
            $db->table('task_execution_logs')
                ->where('id', $logId)
                ->update([
                    'status' => TaskExecutionStatus::SUCCESS->value,
                    'completed_at' => $completeTime,
                    'execution_time' => $executionTime,
                    'result' => is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_UNICODE),
                    'updated_at' => $completeTime,
                ]);

            unset($db, $resolver); // 釋放連接

            $logger->info("✅ 任務執行成功 [{$taskName}] (LogID: {$logId}, 耗時: {$executionTime}s)");

        } catch (Throwable $e) {
            $executionTime = isset($startTime) ? (int)(microtime(true) - $startTime) : 0;

            // ==================== 異常處理：更新狀態為 FAILED ====================
            try {
                $resolver = $container->get(ConnectionResolverInterface::class);
                $db = $resolver->connection('default');

                $failTime = Carbon::now()->format('Y-m-d H:i:s');
                $db->table('task_execution_logs')
                    ->where('id', $logId)
                    ->update([
                        'status' => TaskExecutionStatus::FAILED->value,
                        'completed_at' => $failTime,
                        'execution_time' => $executionTime,
                        'result' => $e->getMessage(),
                        'updated_at' => $failTime,
                    ]);

                unset($db, $resolver);
            } catch (Throwable $dbError) {
                $logger->error("❌ 更新失敗狀態時發生錯誤: " . $dbError->getMessage());
            }

            $logger->error("❌ 任務執行失敗 [{$taskName}] (LogID: {$logId}): " . $e->getMessage());
        }
    }
}
