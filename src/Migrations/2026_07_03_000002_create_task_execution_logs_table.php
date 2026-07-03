<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class CreateTaskExecutionLogsTable extends Migration
{
    public function up(): void
    {
        Schema::create('task_execution_logs', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('自增主鍵');
            $table->unsignedBigInteger('task_id')->comment('對應 scheduled_tasks 的 id');
            $table->string('task_name', 100)->comment('排程名稱');

            $table->enum('status', ['pending', 'processing', 'success', 'failed'])
                ->default('pending')
                ->comment('執行狀態');

            $table->timestamp('plan_execute_time', 3)->comment('預計執行時間');
            $table->timestamp('actual_start_time', 3)->nullable()->comment('實際開始時間');
            $table->timestamp('actual_end_time', 3)->nullable()->comment('實際結束時間');

            $table->unsignedInteger('duration_ms')->default(0)->comment('執行耗時（毫秒）');
            $table->text('error_message')->nullable()->comment('錯誤訊息');

            $table->timestamp('created_at', 3)->useCurrent()->comment('紀錄產生時間');
            $table->timestamp('updated_at', 3)->useCurrentOnUpdate()->comment('紀錄修改時間');

            $table->index(['status', 'plan_execute_time'], 'idx_status_plan_time');
            $table->index('task_id', 'idx_task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_execution_logs');
    }
}