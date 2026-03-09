<?php

namespace App\Commands\Gmail;

use App\Services\Analytics;

/**
 * Generates Gmail web URLs for threads.
 *
 * Uses account index format for reliable multi-account support.
 */
class UrlCommand extends BaseGmailCommand
{
    protected $signature = 'gmail:url
        {--thread-ids=* : Thread IDs}';

    protected $description = 'Generate Gmail web URLs for threads';

    public function handle(Analytics $analytics): int
    {
        $startTime = microtime(true);
        $email = null;
        $threadIds = $this->option('thread-ids') ?: [];

        if (empty($threadIds)) {
            if ($this->shouldOutputJson()) {
                $analytics->track('gmail:url', self::FAILURE, ['count' => 0], $startTime);

                return $this->jsonError('Missing thread IDs.');
            }
            $this->error('Missing thread IDs.');
            $this->line('Usage: gmcli gmail:url --thread-ids=<thread-id> [--thread-ids=<thread-id>...]');

            $analytics->track('gmail:url', self::FAILURE, ['count' => 0], $startTime);

            return self::FAILURE;
        }

        if (! $this->initGmail()) {
            $analytics->track('gmail:url', self::FAILURE, ['count' => 0], $startTime);

            return self::FAILURE;
        }

        $configuredEmail = $this->env->getEmail();

        $results = [];
        foreach ($threadIds as $threadId) {
            $url = $this->buildGmailUrl($threadId, $configuredEmail);
            $results[] = ['threadId' => $threadId, 'url' => $url];
        }

        if ($this->shouldOutputJson()) {
            $analytics->track('gmail:url', self::SUCCESS, ['count' => count($results)], $startTime);

            return $this->outputJson($results);
        }

        foreach ($results as $result) {
            $this->line("{$result['threadId']}\t{$result['url']}");
        }

        $analytics->track('gmail:url', self::SUCCESS, ['count' => count($results)], $startTime);

        return self::SUCCESS;
    }

    /**
     * Builds Gmail web URL for a thread using authuser parameter.
     */
    public function buildGmailUrl(string $threadId, string $email): string
    {
        // Gmail uses lowercase hex thread IDs in URLs
        $hex = strtolower(ltrim($threadId, '0x'));
        $encodedEmail = urlencode($email);

        return "https://mail.google.com/mail/u/?authuser={$encodedEmail}#all/{$hex}";
    }
}
