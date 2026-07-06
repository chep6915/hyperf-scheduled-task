<?php

declare(strict_types=1);

namespace Chep6915\HyperfScheduledTask\Command;

use Carbon\Carbon;
use Chep6915\HyperfScheduledTask\Process\ScheduledTaskProducerProcess;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Command\Annotation\Command;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Database\ConnectionResolverInterface;
use Psr\Container\ContainerInterface;

#[Command]
class GeneratePendingRecordsCommand extends HyperfCommand
{
    public function __construct(protected ContainerInterface $container)
    {
        parent::__construct('task:generate-pending');
    }

    public function configure()
    {
        parent::configure();
        $this->setDescription('產生未來 4 小時的 Pending 任務紀錄');
        $this->addOption('time', 't', \Symfony\Component\Console\Input\InputOption::VALUE_OPTIONAL, '起始時間 (格式: Y-m-d H:i:s)，預設為當前時間');
    }

    public function handle()
    {
        $logger = $this->container->get(StdoutLoggerInterface::class);
        $resolver = $this->container->get(ConnectionResolverInterface::class);
        $db = $resolver->connection('default');

        // 取得時間參數，預設為當前時間
        $timeOption = $this->input->getOption('time');
        $startTime = $timeOption ? Carbon::parse($timeOption) : Carbon::now();

        $logger->info("🚀 開始產生 Pending 紀錄，起始時間: {$startTime->format('Y-m-d H:i:s')}");

        // 建立 Process 實例並同步任務
        $process = new ScheduledTaskProducerProcess($this->container, null);

        // 手動初始化任務清單
        $this->refreshTasks($process, $db, $logger);

        // 呼叫底層產生方法
        $process->generatePendingRecordsFromTime($startTime, $db, $logger);

        $logger->info("✅ Pending 紀錄產生完成");

        return 0;
    }

    /**
     * 同步任務清單（使用反射設定私有屬性）
     */
    private function refreshTasks(ScheduledTaskProducerProcess $process, $db, $logger): void
    {
        try {
            $tasks = $db->table('scheduled_tasks')
                ->where('status', 1)
                ->whereNull('deleted_at')
                ->get(['id', 'name', 'execute_class', 'cron_expression'])
                ->toArray();

            $tasksArray = json_decode(json_encode($tasks), true);

            // 使用反射設定私有屬性（PHP 8.1+ 不需要 setAccessible）
            $reflection = new \ReflectionClass($process);
            $property = $reflection->getProperty('scheduledTasks');
            $property->setValue($process, $tasksArray);

            $logger->info("🔄 已同步 " . count($tasksArray) . " 筆排程任務");
        } catch (\Throwable $e) {
            $logger->error("❌ 同步任務失敗: " . $e->getMessage());
        }
    }
}
