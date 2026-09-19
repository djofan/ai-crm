<?php

namespace App\Providers;

use App\Services\AI\Tools\HandoffToHumanTool;
use App\Services\AI\Tools\ScheduleFollowUpTool;
use App\Services\AI\Tools\SendMessageTool;
use App\Services\AI\Tools\ToolRegistry;
use App\Services\AI\Tools\UpdateLeadTool;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Urutan = urutan eksekusi: ubah state dulu, balas pelanggan terakhir.
        $this->app->singleton(ToolRegistry::class, fn ($app) => new ToolRegistry([
            $app->make(UpdateLeadTool::class),
            $app->make(ScheduleFollowUpTool::class),
            $app->make(HandoffToHumanTool::class),
            $app->make(SendMessageTool::class),
        ]));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
