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

            $table->unsignedBigInteger('task_id')->comment('所屬排程任務ID');
            $table->string('task_name', 100)->comment('任務名稱');

            $table->tinyInteger('status')->default(1)->comment('1=pending, 2=running, 3=success, 4=failed, 5=cancelled');

            $table->timestamp('plan_execute_time')->comment('預計執行時間');

            $table->timestamp('started_at')->nullable()->comment('實際開始時間');
            $table->timestamp('completed_at')->nullable()->comment('完成時間');

            $table->unsignedInteger('execution_time')->nullable()->comment('執行花費毫秒');

            $table->text('result')->nullable()->comment('執行結果 / 錯誤訊息');
            $table->string('remark', 255)->nullable()->comment('備註');

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate();

            // ==================== 索引優化 ====================
            $table->unique(['task_id', 'plan_execute_time'], 'uk_task_plan_time');     // 防重複最重要

            $table->index('status', 'idx_status');
            $table->index('plan_execute_time', 'idx_plan_time');
            $table->index(['task_id', 'status'], 'idx_task_status');
            $table->index(['status', 'plan_execute_time'], 'idx_status_plan');   // 常用查詢組合

            // 如果未來會大量查詢某個時間區間的紀錄，可以再加
            // $table->index(['created_at'], 'idx_created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_execution_logs');
    }
}