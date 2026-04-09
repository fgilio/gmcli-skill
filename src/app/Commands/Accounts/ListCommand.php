<?php

namespace App\Commands\Accounts;

use App\Services\GmcliEnv;
use Fgilio\AgentSkillFoundation\Console\AgentCommand;
use LaravelZero\Framework\Commands\Command;

/**
 * Lists configured Gmail accounts.
 */
class ListCommand extends Command
{
    use AgentCommand;

    protected $signature = 'accounts:list';

    protected $description = 'List configured Gmail accounts';

    public function handle(GmcliEnv $env): int
    {
        if (! $env->hasCredentials()) {
            if ($this->wantsJson()) {
                return $this->outputJson([]);
            }

            $this->warn('No credentials configured.');
            $this->line('Run: gmcli accounts:credentials <file.json>');

            return self::SUCCESS;
        }

        if (! $env->hasAccount()) {
            if ($this->wantsJson()) {
                return $this->outputJson([]);
            }

            $this->warn('No account configured.');
            $this->line('Run: gmcli accounts:add <email>');

            return self::SUCCESS;
        }

        $email = $env->getEmail();
        $aliases = $env->getAliases();

        if ($this->wantsJson()) {
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

        $warning = $env->getPermissionWarning();
        if ($warning) {
            $this->newLine();
            $this->warn($warning);
        }

        return self::SUCCESS;
    }
}
