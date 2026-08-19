<?php

namespace App\Commands\Accounts;

use App\Services\GmcliEnv;
use Fgilio\AgentSkillFoundation\Console\AgentCommand;
use LaravelZero\Framework\Commands\Command;

/**
 * Chooses the account Gmail commands use without --account.
 */
class DefaultCommand extends Command
{
    use AgentCommand;

    protected $signature = 'accounts:default {email? : Email address or alias of the account to make default}';

    protected $description = 'Set the default Gmail account';

    public function handle(GmcliEnv $env): int
    {
        $email = $this->argument('email');

        if (empty($email)) {
            return $this->failWith('Missing email address. Usage: gmcli accounts:default <email>');
        }

        if (! $env->hasAccount()) {
            return $this->failWith('No account configured. Run: gmcli accounts:add <email>');
        }

        $configured = implode(', ', $env->accountEmails());
        $account = $env->setDefaultAccount($email);

        if (! $account) {
            return $this->failWith("Account not found: {$email}. Configured accounts: {$configured}");
        }

        $env->save();

        if ($this->wantsJson()) {
            return $this->outputJson(['email' => $account['email']]);
        }

        $this->info("Default account: {$account['email']}");

        return self::SUCCESS;
    }
}
