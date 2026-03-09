<?php

namespace App\Commands\Gmail;

use App\Services\Analytics;

/**
 * Deletes a Gmail filter.
 */
class FiltersDeleteCommand extends BaseGmailCommand
{
    protected $signature = 'gmail:filters:delete
        {--filter-id= : Filter ID to delete}';

    protected $description = 'Delete a Gmail filter';

    public function handle(Analytics $analytics): int
    {
        $startTime = microtime(true);
        $filterId = $this->option('filter-id');

        if (empty($filterId)) {
            if ($this->shouldOutputJson()) {
                $analytics->track('gmail:filters:delete', self::FAILURE, ['success' => false], $startTime);

                return $this->jsonError('Missing filter ID.');
            }

            $this->error('Missing filter ID.');

            $analytics->track('gmail:filters:delete', self::FAILURE, ['success' => false], $startTime);

            return self::FAILURE;
        }

        if (! $this->initGmail()) {
            $analytics->track('gmail:filters:delete', self::FAILURE, ['success' => false], $startTime);

            return self::FAILURE;
        }

        try {
            $this->gmail->delete("/users/me/settings/filters/{$filterId}");

            if ($this->shouldOutputJson()) {
                $analytics->track('gmail:filters:delete', self::SUCCESS, ['success' => true], $startTime);

                return $this->outputJson(['filterId' => $filterId]);
            }

            $this->info("Filter deleted: {$filterId}");

            $analytics->track('gmail:filters:delete', self::SUCCESS, ['success' => true], $startTime);

            return self::SUCCESS;
        } catch (\RuntimeException $e) {
            $exception = $this->normalizeScopeError($e);

            $analytics->track('gmail:filters:delete', self::FAILURE, ['success' => false], $startTime);

            if ($this->shouldOutputJson()) {
                return $this->jsonError($exception->getMessage());
            }

            $this->renderRuntimeException($exception);

            return self::FAILURE;
        }
    }
}
