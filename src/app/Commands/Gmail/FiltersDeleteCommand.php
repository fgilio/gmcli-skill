<?php

namespace App\Commands\Gmail;

/**
 * Deletes a Gmail filter.
 */
class FiltersDeleteCommand extends BaseGmailCommand
{
    protected $signature = 'gmail:filters:delete
        {--filter-id= : Filter ID to delete}';

    protected $description = 'Delete a Gmail filter';

    public function handle(): int
    {
        $filterId = $this->option('filter-id');

        if (empty($filterId)) {
            return $this->failWith('Missing filter ID.');
        }

        if ($failure = $this->initGmail()) {
            return $failure;
        }

        $this->gmail->delete("/users/me/settings/filters/{$filterId}");

        if ($this->wantsJson()) {
            return $this->outputJson(['filterId' => $filterId]);
        }

        $this->info("Filter deleted: {$filterId}");

        return self::SUCCESS;
    }
}
