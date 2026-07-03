<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class CreateScheduledTasksTable extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_tasks', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('自增主鍵');
            $table->string('name', 100)->unique()->comment('排程名稱');
            $table->string('execute_class', 255)->comment('執行類別全名');
            $table->string('cron_expression', 100)->comment('Cron 表達式');
            $table->tinyInteger('status')->default(1)->comment('狀態: 0=停用, 1=啟用');
            $table->string('remark', 255)->nullable()->comment('備註');

            $table->timestamp('created_at', 3)->useCurrent()->comment('新增時間');
            $table->timestamp('updated_at', 3)->useCurrentOnUpdate()->comment('修改時間');
            $table->timestamp('deleted_at', 3)->nullable()->comment('軟刪除時間');

            $table->index('status', 'idx_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_tasks');
    }
}