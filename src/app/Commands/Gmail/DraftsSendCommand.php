<?php

namespace App\Commands\Gmail;

/**
 * Sends a Gmail draft.
 */
class DraftsSendCommand extends BaseGmailCommand
{
    protected $signature = 'gmail:drafts:send
        {--draft-id= : Draft ID to send}';

    protected $description = 'Send a draft';

    public function handle(): int
    {
        $draftId = $this->option('draft-id');

        if (empty($draftId)) {
            return $this->failWith('Missing draft ID. Usage: gmcli gmail:drafts:send --draft-id=<draft-id>');
        }

        if ($failure = $this->initGmail()) {
            return $failure;
        }

        $this->logger->verbose("Sending draft: {$draftId}");

        $response = $this->gmail->post('/users/me/drafts/send', ['id' => $draftId]);

        $messageId = $response['id'] ?? '';
        $threadId = $response['threadId'] ?? '';

        if ($this->wantsJson()) {
            return $this->outputJson([
                'messageId' => $messageId,
                'threadId' => $threadId,
            ]);
        }

        $this->info('Draft sent successfully.');
        $this->line("Message-ID: {$messageId}");
        $this->line("Thread-ID: {$threadId}");

        return self::SUCCESS;
    }
}
