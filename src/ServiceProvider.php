<?php

declare(strict_types=1);

namespace chep6915\ScheduledTask;

use Hyperf\Framework\AbstractServiceProvider;

class ServiceProvider extends AbstractServiceProvider
{
    public function register()
    {
        // 自動註冊 migration 路徑
        $this->registerMigrations();
    }

    private function registerMigrations(): void
    {
        $config = $this->container->get(\Hyperf\Contract\ConfigInterface::class);
        $paths = $config->get('migrations.paths', []);
        $paths[] = __DIR__ . '/../migrations';
        $config->set('migrations.paths', $paths);
    }
}