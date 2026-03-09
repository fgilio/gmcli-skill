<?php

namespace App\Providers;

use App\Services\GmailClientFactory;
use App\Services\GmcliEnv;
use App\Services\GmcliPaths;
use Illuminate\Support\ServiceProvider;
use Phar;

class AppServiceProvider extends ServiceProvider
{
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

        if (Phar::running() || getenv('SKILL_PRODUCTION')) {
            $this->hideDevCommands();
        }
    }

    private function hideDevCommands(): void
    {
        $devCommands = [
            \App\Commands\BuildCommand::class,
            \NunoMaduro\Collision\Adapters\Laravel\Commands\TestCommand::class,
            \LaravelZero\Framework\Commands\BuildCommand::class,
            \LaravelZero\Framework\Commands\InstallCommand::class,
            \LaravelZero\Framework\Commands\RenameCommand::class,
            \LaravelZero\Framework\Commands\MakeCommand::class,
            \LaravelZero\Framework\Commands\TestMakeCommand::class,
        ];

        $hidden = config('commands.hidden', []);
        config(['commands.hidden' => array_merge($hidden, $devCommands)]);
    }
}
