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
use Throwable;

#[Process(name: "ScheduledTaskConsumer", nums: 1)]
class ScheduledTaskConsumerProcess extends AbstractProcess
{
    public bool $enable = true;

    public function handle(): void
    {
        $container = ApplicationContext::getContainer();
        $logger = $container->get(StdoutLoggerInterface::class);
        $resolver = $container->get(ConnectionResolverInterface::class);
        $db = $resolver->connection('default');

        $logger->info("🎯 ScheduledTaskConsumer 已啟動，開始消費 Pending 任務...");

        // 每秒檢查一次待執行的任務
        SwooleTimer::tick(1000, function () use ($db, $logger, $container) {
            $this->consumePendingTasks($db, $logger, $container);
        });

        while (true) {
            sleep(30);
        }
    }

    /**
     * 消費待執行的任務
     */
    private function consumePendingTasks($db, StdoutLoggerInterface $logger, $container): void
    {
        try {
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

            $logger->debug("📦 發現 " . count($pendingLogs) . " 筆待執行任務");

            foreach ($pendingLogs as $log) {
                $this->executeTask($log, $db, $logger, $container);
            }
        } catch (Throwable $e) {
            $logger->error("❌ 消費任務失敗: " . $e->getMessage());
        }
    }

    /**
     * 執行單一任務
     */
    private function executeTask($log, $db, StdoutLoggerInterface $logger, $container): void
    {
        $logId = $log->id;
        $taskName = $log->task_name;

        try {
            // 1. 更新狀態為 RUNNING
            $now = Carbon::now();
            $updated = $db->table('task_execution_logs')
                ->where('id', $logId)
                ->where('status', TaskExecutionStatus::PENDING->value) // 樂觀鎖
                ->update([
                    'status' => TaskExecutionStatus::RUNNING->value,
                    'started_at' => $now->format('Y-m-d H:i:s.v'),
                    'updated_at' => $now->format('Y-m-d H:i:s.v'),
                ]);

            // 如果更新失敗，表示已被其他進程處理
            if ($updated === 0) {
                return;
            }

            $logger->info("▶️  開始執行任務 [{$taskName}] (LogID: {$logId})");

            $startTime = microtime(true);

            // 2. 取得任務執行類別
            $task = $db->table('scheduled_tasks')
                ->where('id', $log->task_id)
                ->first();

            if (!$task) {
                throw new \RuntimeException("任務不存在 (task_id: {$log->task_id})");
            }

            $executeClass = $task->execute_class;

            if (!class_exists($executeClass)) {
                throw new \RuntimeException("執行類別不存在: {$executeClass}");
            }

            // 3. 實例化並執行任務
            $taskInstance = $container->get($executeClass);

            if (!method_exists($taskInstance, 'execute')) {
                throw new \RuntimeException("執行類別缺少 execute() 方法: {$executeClass}");
            }

            $result = $taskInstance->execute($logId);

            $executionTime = (int)((microtime(true) - $startTime) * 1000); // 毫秒

            // 4. 更新狀態為 SUCCESS
            $completeTime = Carbon::now();
            $db->table('task_execution_logs')
                ->where('id', $logId)
                ->update([
                    'status' => TaskExecutionStatus::SUCCESS->value,
                    'completed_at' => $completeTime->format('Y-m-d H:i:s.v'),
                    'execution_time' => $executionTime,
                    'result' => is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_UNICODE),
                    'updated_at' => $completeTime->format('Y-m-d H:i:s.v'),
                ]);

            $logger->info("✅ 任務執行成功 [{$taskName}] (LogID: {$logId}, 耗時: {$executionTime}ms)");

        } catch (Throwable $e) {
            $executionTime = isset($startTime) ? (int)((microtime(true) - $startTime) * 1000) : 0;

            // 更新狀態為 FAILED
            $failTime = Carbon::now();
            $db->table('task_execution_logs')
                ->where('id', $logId)
                ->update([
                    'status' => TaskExecutionStatus::FAILED->value,
                    'completed_at' => $failTime->format('Y-m-d H:i:s.v'),
                    'execution_time' => $executionTime,
                    'result' => $e->getMessage(),
                    'updated_at' => $failTime->format('Y-m-d H:i:s.v'),
                ]);

            $logger->error("❌ 任務執行失敗 [{$taskName}] (LogID: {$logId}): " . $e->getMessage());
        }
    }
}
