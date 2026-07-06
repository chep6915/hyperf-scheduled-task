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
                    'source' => __DIR__ . '/migrations/2026_07_03_000001_create_scheduled_tasks_table.php', // 你套件內的源路徑
                    'destination' => BASE_PATH . '/migrations/' . date('Y_m_d_His') . '_create_scheduled_tasks_table.php', // 目標專案路徑
                ],
                [
                    'id' => 'migration_log',
                    'description' => 'The migrations for hyperf-scheduled-task execution logs.',
                    'source' => __DIR__ . '/migrations/2026_07_03_000002_create_task_execution_logs_table.php',
                    'destination' => BASE_PATH . '/migrations/' . date('Y_m_d_His', time() + 1) . '_create_task_execution_logs_table.php',
                ],
            ],
        ];
    }
}