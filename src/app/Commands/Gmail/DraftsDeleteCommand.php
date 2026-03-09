<?php

namespace App\Commands\Gmail;

use App\Services\Analytics;

/**
 * Deletes a Gmail draft.
 */
class DraftsDeleteCommand extends BaseGmailCommand
{
    protected $signature = 'gmail:drafts:delete
        {--draft-id= : Draft ID to delete}';

    protected $description = 'Delete a draft';

    public function handle(Analytics $analytics): int
    {
        $startTime = microtime(true);
        $email = null;
        $draftId = $this->option('draft-id');

        if (empty($draftId)) {
            if ($this->shouldOutputJson()) {
                $analytics->track('gmail:drafts:delete', self::FAILURE, ['success' => false], $startTime);

                return $this->jsonError('Missing draft ID.');
            }
            $this->error('Missing draft ID.');
            $this->line('Usage: gmcli gmail:drafts:delete --draft-id=<draft-id>');

            $analytics->track('gmail:drafts:delete', self::FAILURE, ['success' => false], $startTime);

            return self::FAILURE;
        }

        if (! $this->initGmail()) {
            $analytics->track('gmail:drafts:delete', self::FAILURE, ['success' => false], $startTime);

            return self::FAILURE;
        }

        try {
            $this->logger->verbose("Deleting draft: {$draftId}");

            // Gmail API uses DELETE method, but we'll use the drafts.delete endpoint
            // Need to add delete support to GmailClient
            $this->deleteDraft($draftId);

            if ($this->shouldOutputJson()) {
                $analytics->track('gmail:drafts:delete', self::SUCCESS, ['success' => true], $startTime);

                return $this->outputJson([
                    'draftId' => $draftId,
                ]);
            }

            $this->info("Draft deleted: {$draftId}");

            $analytics->track('gmail:drafts:delete', self::SUCCESS, ['success' => true], $startTime);

            return self::SUCCESS;
        } catch (\RuntimeException $e) {
            $analytics->track('gmail:drafts:delete', self::FAILURE, ['success' => false], $startTime);

            return $this->jsonError($e->getMessage());
        }
    }

    private function deleteDraft(string $draftId): void
    {
        $this->gmail->delete("/users/me/drafts/{$draftId}");
    }
}
