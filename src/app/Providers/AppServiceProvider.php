<?php

namespace App\Providers;

use App\Services\GmailClientFactory;
use App\Services\GmcliEnv;
use App\Services\GmcliPaths;
use Fgilio\AgentSkillFoundation\Console\BuildCommand;
use Fgilio\AgentSkillFoundation\Console\Concerns\HidesDevCommands;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    use HidesDevCommands;

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(GmcliPaths::class);
        $this->app->singleton(GmcliEnv::class);
        $this->app->singleton(GmailClientFactory::class);

        $this->hideDevCommands([
            BuildCommand::class,
            \NunoMaduro\Collision\Adapters\Laravel\Commands\TestCommand::class,
            \LaravelZero\Framework\Commands\BuildCommand::class,
            \LaravelZero\Framework\Commands\InstallCommand::class,
            \LaravelZero\Framework\Commands\RenameCommand::class,
            \LaravelZero\Framework\Commands\MakeCommand::class,
            \LaravelZero\Framework\Commands\TestMakeCommand::class,
        ]);
    }
}
