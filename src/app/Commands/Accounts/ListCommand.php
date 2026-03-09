<?php

namespace App\Commands\Accounts;

use App\Services\Analytics;
use App\Services\GmcliEnv;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Input\InputOption;

/**
 * Lists configured Gmail accounts.
 */
class ListCommand extends Command
{
    protected $signature = 'accounts:list';

    protected $description = 'List configured Gmail accounts';

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    public function handle(GmcliEnv $env, Analytics $analytics): int
    {
        $startTime = microtime(true);

        if (! $env->hasCredentials()) {
            if ($this->shouldOutputJson()) {
                $analytics->track('accounts:list', self::SUCCESS, ['count' => 0], $startTime);

                return $this->outputJson([]);
            }
            $this->warn('No credentials configured.');
            $this->line('Run: gmcli accounts:credentials <file.json>');

            $analytics->track('accounts:list', self::SUCCESS, ['count' => 0], $startTime);

            return self::SUCCESS;
        }

        if (! $env->hasAccount()) {
            if ($this->shouldOutputJson()) {
                $analytics->track('accounts:list', self::SUCCESS, ['count' => 0], $startTime);

                return $this->outputJson([]);
            }
            $this->warn('No account configured.');
            $this->line('Run: gmcli accounts:add <email>');

            $analytics->track('accounts:list', self::SUCCESS, ['count' => 0], $startTime);

            return self::SUCCESS;
        }

        $email = $env->getEmail();
        $aliases = $env->getAliases();

        if ($this->shouldOutputJson()) {
            $analytics->track('accounts:list', self::SUCCESS, ['count' => 1], $startTime);

            return $this->outputJson([
                [
                    'email' => $email,
                    'aliases' => $aliases,
                ],
            ]);
        }

        $this->line($email);

        if (! empty($aliases)) {
            $this->line('  Aliases: '.implode(', ', $aliases));
        }

        // Check for permission warnings
        $warning = $env->getPermissionWarning();
        if ($warning) {
            $this->newLine();
            $this->warn($warning);
        }

        $analytics->track('accounts:list', self::SUCCESS, ['count' => 1], $startTime);

        return self::SUCCESS;
    }

    protected function shouldOutputJson(): bool
    {
        return $this->hasOption('json') && $this->option('json');
    }

    protected function outputJson(mixed $data): int
    {
        $this->line(json_encode(['data' => $data], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
