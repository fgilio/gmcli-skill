<?php

namespace App\Commands\Gmail;

/**
 * Deletes a Gmail draft.
 */
class DraftsDeleteCommand extends BaseGmailCommand
{
    protected $signature = 'gmail:drafts:delete
        {--draft-id= : Draft ID to delete}';

    protected $description = 'Delete a draft';

    public function handle(): int
    {
        $draftId = $this->option('draft-id');

        if (empty($draftId)) {
            return $this->failWith('Missing draft ID. Usage: gmcli gmail:drafts:delete --draft-id=<draft-id>');
        }

        if ($failure = $this->initGmail()) {
            return $failure;
        }

        $this->logger->verbose("Deleting draft: {$draftId}");

        $this->gmail->delete("/users/me/drafts/{$draftId}");

        if ($this->wantsJson()) {
            return $this->outputJson(['draftId' => $draftId]);
        }

        $this->info("Draft deleted: {$draftId}");

        return self::SUCCESS;
    }
}
