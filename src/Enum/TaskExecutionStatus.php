<?php

declare(strict_types=1);

namespace Chep6915\HyperfScheduledTask\Enum;

enum TaskExecutionStatus: int
{
    case PENDING = 1;
    case RUNNING = 2;
    case SUCCESS = 3;
    case FAILED = 4;
    case CANCELLED = 5;

    public function label(): string
    {
        return match ($this) {
            self::PENDING => '待執行',
            self::RUNNING => '執行中',
            self::SUCCESS => '成功',
            self::FAILED => '失敗',
            self::CANCELLED => '已取消',
        };
    }
}
