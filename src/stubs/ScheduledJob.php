<?php

declare(strict_types=1);

namespace App\Job;

use Hyperf\AsyncQueue\Job;
use Hyperf\Contract\StdoutLoggerInterface;

/**
 * 排程任務 Job
 *
 * 使用方式：
 * 1. 在資料庫 scheduled_tasks 表新增記錄
 * 2. job_class 填寫：App\Job\ScheduledJob
 * 3. queue_name 填寫：default（或其他隊列）
 */
class ScheduledJob extends Job
{
    public function __construct(
        public int $logId
    ) {
    }

    public function handle()
    {
        $logger = di(StdoutLoggerInterface::class);

        $logger->info("📝 排程任務執行開始 (LogID: {$this->logId})");

        // ===== 你的業務邏輯寫在這裡 =====
        sleep(2); // 模擬任務執行

        $logger->info("✅ 排程任務執行完成 (LogID: {$this->logId})");

        return "執行成功";
    }
}
