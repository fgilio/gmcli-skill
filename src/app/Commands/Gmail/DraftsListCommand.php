<?php

namespace App\Commands\Gmail;

/**
 * Lists all Gmail drafts.
 */
class DraftsListCommand extends BaseGmailCommand
{
    protected $signature = 'gmail:drafts:list';

    protected $description = 'List all drafts';

    public function handle(): int
    {
        if ($failure = $this->initGmail()) {
            return $failure;
        }

        $this->logger->verbose('Fetching drafts...');

        $response = $this->gmail->get('/users/me/drafts');
        $drafts = $response['drafts'] ?? [];

        if (empty($drafts)) {
            if ($this->wantsJson()) {
                return $this->outputJson([]);
            }

            $this->info('No drafts found.');

            return self::SUCCESS;
        }

        $results = array_map(fn ($d) => [
            'draftId' => $d['id'],
            'messageId' => $d['message']['id'] ?? '',
        ], $drafts);

        if ($this->wantsJson()) {
            return $this->outputJson($results);
        }

        foreach ($results as $result) {
            $this->line("{$result['draftId']}\t{$result['messageId']}");
        }

        return self::SUCCESS;
    }
}
