<?php

namespace App\Commands\Gmail;

use App\Services\Analytics;
use App\Services\LabelResolver;

/**
 * Modifies labels on Gmail threads.
 */
class LabelsModifyCommand extends BaseGmailCommand
{
    protected $signature = 'gmail:labels:modify
        {--thread-ids=* : Thread IDs to modify}
        {--add=* : Labels to add}
        {--remove=* : Labels to remove}';

    protected $description = 'Modify labels on threads';

    public function handle(Analytics $analytics): int
    {
        $startTime = microtime(true);
        $email = null;
        $threadIds = $this->option('thread-ids') ?: [];
        $addLabels = $this->option('add') ?: [];
        $removeLabels = $this->option('remove') ?: [];

        if (empty($threadIds)) {
            if ($this->shouldOutputJson()) {
                $analytics->track('gmail:labels:modify', self::FAILURE, ['success' => false], $startTime);

                return $this->jsonError('Missing thread IDs.');
            }
            $this->error('Missing thread IDs.');
            $this->line('Usage: gmcli gmail:labels:modify --thread-ids=<thread-id> [--thread-ids=<thread-id>...] [--add=<label>] [--remove=<label>]');

            $analytics->track('gmail:labels:modify', self::FAILURE, ['success' => false], $startTime);

            return self::FAILURE;
        }

        if (empty($addLabels) && empty($removeLabels)) {
            if ($this->shouldOutputJson()) {
                $analytics->track('gmail:labels:modify', self::FAILURE, ['success' => false], $startTime);

                return $this->jsonError('No labels to add or remove.');
            }
            $this->error('No labels to add or remove.');
            $this->line('Usage: gmcli gmail:labels:modify --thread-ids=<thread-id> [--thread-ids=<thread-id>...] [--add=<label>] [--remove=<label>]');

            $analytics->track('gmail:labels:modify', self::FAILURE, ['success' => false], $startTime);

            return self::FAILURE;
        }

        if (! $this->initGmail()) {
            $analytics->track('gmail:labels:modify', self::FAILURE, ['success' => false], $startTime);

            return self::FAILURE;
        }

        try {
            // Load and resolve labels
            $resolver = $this->loadLabels();

            $addResolved = $resolver->resolveMany($addLabels);
            $removeResolved = $resolver->resolveMany($removeLabels);

            // Report any not found labels
            if (! empty($addResolved['notFound'])) {
                $this->warn('Labels not found (add): '.implode(', ', $addResolved['notFound']));
            }
            if (! empty($removeResolved['notFound'])) {
                $this->warn('Labels not found (remove): '.implode(', ', $removeResolved['notFound']));
            }

            // Nothing to do if all labels not found
            if (empty($addResolved['resolved']) && empty($removeResolved['resolved'])) {
                $analytics->track('gmail:labels:modify', self::FAILURE, ['success' => false], $startTime);

                return $this->jsonError('No valid labels to modify.');
            }

            // Modify each thread
            foreach ($threadIds as $threadId) {
                $this->modifyThread($threadId, $addResolved['resolved'], $removeResolved['resolved']);
            }

            if ($this->shouldOutputJson()) {
                $analytics->track('gmail:labels:modify', self::SUCCESS, ['success' => true, 'thread_count' => count($threadIds)], $startTime);

                return $this->outputJson([
                    'threads' => $threadIds,
                    'added' => $addResolved['resolved'],
                    'removed' => $removeResolved['resolved'],
                ]);
            }

            $this->info('Labels modified.');

            $analytics->track('gmail:labels:modify', self::SUCCESS, ['success' => true, 'thread_count' => count($threadIds)], $startTime);

            return self::SUCCESS;
        } catch (\RuntimeException $e) {
            $analytics->track('gmail:labels:modify', self::FAILURE, ['success' => false], $startTime);

            return $this->jsonError($e->getMessage());
        }
    }

    private function loadLabels(): LabelResolver
    {
        $this->logger->verbose('Loading labels...');

        $response = $this->gmail->get('/users/me/labels');
        $labels = $response['labels'] ?? [];

        $resolver = new LabelResolver;
        $resolver->load($labels);

        return $resolver;
    }

    private function modifyThread(string $threadId, array $addLabelIds, array $removeLabelIds): void
    {
        $this->logger->verbose("Modifying thread: {$threadId}");

        $body = [];

        if (! empty($addLabelIds)) {
            $body['addLabelIds'] = $addLabelIds;
        }

        if (! empty($removeLabelIds)) {
            $body['removeLabelIds'] = $removeLabelIds;
        }

        $this->gmail->post("/users/me/threads/{$threadId}/modify", $body);
    }
}
