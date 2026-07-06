<?php

declare(strict_types=1);

namespace Chep6915\HyperfScheduledTask;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            // 🔑 讓 Hyperf 自動偵測並載入這個常駐進程
            'processes' => [
                \Chep6915\HyperfScheduledTask\Process\ScheduledTaskProducerProcess::class,
                \Chep6915\HyperfScheduledTask\Process\ScheduledTaskConsumerProcess::class,
            ],
            // 🔑 註冊 Command
            'commands' => [
                \Chep6915\HyperfScheduledTask\Command\GeneratePendingRecordsCommand::class,
            ],
            // 🔑 關鍵：定義發布的檔案對照
            'publish' => [
                [
                    'id' => 'migration',
                    'description' => 'The migrations for hyperf-scheduled-task.',
                    'source' => __DIR__ . '/migrations/2026_07_03_000001_create_scheduled_tasks_table.php',
                    'destination' => BASE_PATH . '/migrations/2026_07_03_000001_create_scheduled_tasks_table.php', // 固定時間戳
                ],
                [
                    'id' => 'migration_log',
                    'description' => 'The migrations for hyperf-scheduled-task execution logs.',
                    'source' => __DIR__ . '/migrations/2026_07_03_000002_create_task_execution_logs_table.php',
                    'destination' => BASE_PATH . '/migrations/2026_07_03_000002_create_task_execution_logs_table.php', // 固定時間戳
                ],
                [
                    'id' => 'crontab',
                    'description' => 'The Crontab directory for scheduled task classes.',
                    'source' => __DIR__ . '/stubs/Crontab/.gitkeep',
                    'destination' => BASE_PATH . '/app/Crontab/.gitkeep',
                ],
            ],
        ];
    }
}