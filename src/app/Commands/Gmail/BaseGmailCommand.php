<?php

namespace App\Commands\Gmail;

use App\Services\GmailClient;
use App\Services\GmailClientFactory;
use App\Services\GmailLogger;
use App\Services\GmcliEnv;
use Fgilio\AgentSkillFoundation\Console\AgentCommand;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

/**
 * Base class for Gmail commands.
 *
 * Provides common functionality:
 * - Account resolution (from --account option or default)
 * - Gmail client creation with logging
 * - Verbose/debug output support
 * - JSON output + exception handling via the AgentCommand trait
 */
abstract class BaseGmailCommand extends Command
{
    use AgentCommand;

    protected GmcliEnv $env;

    protected GmailClient $gmail;

    protected GmailLogger $logger;

    protected string $account;

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('account', 'a', InputOption::VALUE_REQUIRED, 'Account email (uses default if not specified)');
    }

    /**
     * Resolves account from --account option or default.
     */
    protected function resolveAccount(): ?string
    {
        $this->env = app(GmcliEnv::class);

        return $this->option('account') ?: $this->env->getEmail();
    }

    /**
     * Initializes Gmail client for the resolved account.
     *
     * Returns null on success, or a failure exit code (with a
     * user-facing message already emitted) when auth setup fails.
     */
    protected function initGmail(?string $email = null): ?int
    {
        $this->env = app(GmcliEnv::class);
        $this->logger = new GmailLogger(
            $this->output,
            $this->output->isVerbose(),
            $this->output->isVeryVerbose()
        );

        $email = $email ?? $this->resolveAccount();

        if (! $email) {
            return $this->failWith(
                'No account specified and no default configured. '
                .'Either pass --account you@gmail.com or run: gmcli accounts:add you@gmail.com'
            );
        }

        $this->account = $email;

        if (! $this->env->hasCredentials()) {
            return $this->failWith('No credentials configured. Run: gmcli accounts:credentials <file.json>');
        }

        if (! $this->env->hasAccount()) {
            return $this->failWith('No account configured. Run: gmcli accounts:add <email>');
        }

        if (! $this->env->matchesEmail($email)) {
            return $this->failWith("Email does not match configured account: {$this->env->getEmail()}");
        }

        $this->gmail = app(GmailClientFactory::class)->make(
            $this->env->get('GOOGLE_CLIENT_ID'),
            $this->env->get('GOOGLE_CLIENT_SECRET'),
            $this->env->get('GMAIL_REFRESH_TOKEN'),
            $this->logger
        );

        $warning = $this->env->getPermissionWarning();
        if ($warning) {
            $this->warn($warning);
            $this->newLine();
        }

        return null;
    }

    /**
     * Rewrite Gmail API scope errors into an actionable re-consent hint.
     *
     * @return array{message: string, meta: array<string, mixed>}|null
     */
    protected function extractExceptionDetails(Throwable $e): ?array
    {
        if (! $e instanceof RuntimeException) {
            return null;
        }

        $normalized = strtolower($e->getMessage());

        $isScopeError = str_contains($normalized, 'insufficient authentication scopes')
            || str_contains($normalized, 'insufficientpermissions')
            || str_contains($normalized, 'insufficient permissions');

        if (! $isScopeError) {
            return null;
        }

        $account = $this->account ?? '<email>';

        return [
            'message' => "Filter management requires renewed Gmail consent.\n"
                .'Run: gmcli accounts:remove '.$account."\n"
                .'Then: gmcli accounts:add '.$account,
            'meta' => [],
        ];
    }
}
