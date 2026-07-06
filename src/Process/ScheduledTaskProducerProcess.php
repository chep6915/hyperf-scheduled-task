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
use Hyperf\Database\ConnectionResolverInterface;
use Hyperf\Coroutine\Coroutine;
use Carbon\Carbon;
use Chep6915\HyperfScheduledTask\Enum\TaskExecutionStatus;

#[Process(name: "ScheduledTaskProducer")]
class ScheduledTaskProducerProcess extends AbstractProcess
{
    private array $scheduledTasks = [];
    private array $cronCache = [];

    public bool $enable = true;

    public function handle(): void
    {
        $container = ApplicationContext::getContainer();
        $logger = $container->get(StdoutLoggerInterface::class);
        $resolver = $container->get(ConnectionResolverInterface::class);

        $logger->info("🚀 ScheduledTaskProducer（預產生 Pending）已啟動...");

        // 初始同步任務
        $db = $resolver->connection('default');
        $this->refreshTasksFromDatabase($db, $logger);

        // 每 60 秒刷新一次任務清單
        SwooleTimer::tick(60 * 1000, function () use ($resolver, $logger) {
            Coroutine::create(function () use ($resolver, $logger) {
                $db = $resolver->connection('default');
                $this->refreshTasksFromDatabase($db, $logger);
            });
        });

        // 每 30 秒執行一次「產生未來 4 小時的 Pending 紀錄」
        SwooleTimer::tick(30 * 1000, function () use ($resolver, $logger) {
            Coroutine::create(function () use ($resolver, $logger) {
                $db = $resolver->connection('default');
                $this->generatePendingRecordsFromTime(Carbon::now(), $db, $logger);
            });
        });

        $logger->info("⏳ Producer 已進入排程預產生模式（每 30 秒產生未來 4 小時紀錄）");

        while (true) {
            sleep(30);
        }
    }

    /**
     * 產生未來 4 小時的 Pending 紀錄（從指定時間開始）
     */
    public function generatePendingRecordsFromTime(Carbon $startTime, ConnectionInterface $db, StdoutLoggerInterface $logger): void
    {
        $endTime = $startTime->copy()->addHours(4);

        $logger->info("📅 開始產生未來 4 小時 Pending 紀錄，從 {$startTime->format('H:i:s')} 到 {$endTime->format('H:i:s')}");

        foreach ($this->scheduledTasks as $task) {
            try {
                // 暫時使用簡單秒級邏輯產生未來紀錄
                $this->generatePendingWithSimpleLogic($task, $startTime, $endTime, $db, $logger);
            } catch (Throwable $e) {
                $logger->error("❌ 產生任務 [{$task['name']}] Pending 失敗: " . $e->getMessage());
            }
        }
    }

    private function createPendingRecord(array $task, Carbon $executeTime, ConnectionInterface $db, StdoutLoggerInterface $logger): void
    {
        try {
            $logId = $db->table('task_execution_logs')->insertGetId([
                'task_id'          => $task['id'],
                'task_name'        => $task['name'],
                'status'           => TaskExecutionStatus::PENDING->value,
                'plan_execute_time'=> $executeTime->format('Y-m-d H:i:s'),
                'created_at'       => date('Y-m-d H:i:s.v'),
                'updated_at'       => date('Y-m-d H:i:s.v'),
            ]);

            $logger->debug("📝 已預產生 Pending 紀錄 | 任務: {$task['name']} | 執行時間: {$executeTime->format('H:i:s')} | LogID: {$logId}");
        } catch (Throwable $e) {
            $logger->error("❌ 寫入 Pending 失敗: " . $e->getMessage());
        }
    }

    /**
     * 取得 CronExpression（支援 6 欄位）
     */
    private function getCronExpression(string $cronExpr)
    {
        if (!isset($this->cronCache[$cronExpr])) {
            try {
                $this->cronCache[$cronExpr] = CronExpression::factory($cronExpr);
            } catch (Throwable $e) {
                // 如果還是失敗，就建立一個簡單的每分鐘 Cron
                $this->cronCache[$cronExpr] = CronExpression::factory('* * * * *');
            }
        }
        return $this->cronCache[$cronExpr];
    }

    private function refreshTasksFromDatabase(ConnectionInterface $db, StdoutLoggerInterface $logger): void
    {
        try {
            $tasks = $db->table('scheduled_tasks')
                ->where('status', 1)
                ->whereNull('deleted_at')
                ->get(['id', 'name', 'execute_class', 'cron_expression'])
                ->toArray();

            $this->scheduledTasks = json_decode(json_encode($tasks), true);
            $this->cronCache = []; // 清快取

            $logger->debug("🔄 已同步 " . count($this->scheduledTasks) . " 筆排程任務");
        } catch (Throwable $e) {
            $logger->error("❌ 同步任務失敗: " . $e->getMessage());
        }
    }

    private function generatePendingWithSimpleLogic(array $task, Carbon $startTime, Carbon $endTime, ConnectionInterface $db, StdoutLoggerInterface $logger): void
    {
        $cronExpr = trim($task['cron_expression']);
        $parts = preg_split('/\s+/', $cronExpr);
        $secondField = $parts[0] ?? '';

        $interval = 60; // 預設 60 秒

        if (str_starts_with($secondField, '*/')) {
            $interval = (int)str_replace('*/', '', $secondField);
        }

        $current = $startTime->copy();

        while ($current <= $endTime) {
            if ($current->second % $interval === 0) {
                $this->createPendingRecord($task, $current, $db, $logger);
            }
            $current->addSeconds(1);
        }
    }
}