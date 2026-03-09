<?php

namespace App\Commands\Gmail;

use App\Services\Analytics;

/**
 * Sends a Gmail draft.
 */
class DraftsSendCommand extends BaseGmailCommand
{
    protected $signature = 'gmail:drafts:send
        {--draft-id= : Draft ID to send}';

    protected $description = 'Send a draft';

    public function handle(Analytics $analytics): int
    {
        $startTime = microtime(true);
        $email = null;
        $draftId = $this->option('draft-id');

        if (empty($draftId)) {
            if ($this->shouldOutputJson()) {
                $analytics->track('gmail:drafts:send', self::FAILURE, ['success' => false], $startTime);

                return $this->jsonError('Missing draft ID.');
            }
            $this->error('Missing draft ID.');
            $this->line('Usage: gmcli gmail:drafts:send --draft-id=<draft-id>');

            $analytics->track('gmail:drafts:send', self::FAILURE, ['success' => false], $startTime);

            return self::FAILURE;
        }

        if (! $this->initGmail()) {
            $analytics->track('gmail:drafts:send', self::FAILURE, ['success' => false], $startTime);

            return self::FAILURE;
        }

        try {
            $this->logger->verbose("Sending draft: {$draftId}");

            $response = $this->gmail->post('/users/me/drafts/send', [
                'id' => $draftId,
            ]);

            $messageId = $response['id'] ?? '';
            $threadId = $response['threadId'] ?? '';

            if ($this->shouldOutputJson()) {
                $analytics->track('gmail:drafts:send', self::SUCCESS, ['success' => true], $startTime);

                return $this->outputJson([
                    'messageId' => $messageId,
                    'threadId' => $threadId,
                ]);
            }

            $this->info('Draft sent successfully.');
            $this->line("Message-ID: {$messageId}");
            $this->line("Thread-ID: {$threadId}");

            $analytics->track('gmail:drafts:send', self::SUCCESS, ['success' => true], $startTime);

            return self::SUCCESS;
        } catch (\RuntimeException $e) {
            $analytics->track('gmail:drafts:send', self::FAILURE, ['success' => false], $startTime);

            return $this->jsonError($e->getMessage());
        }
    }
}
