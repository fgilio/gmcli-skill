<?php

namespace App\Commands\Gmail;

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

    public function handle(): int
    {
        $threadIds = $this->option('thread-ids') ?: [];
        $addLabels = $this->option('add') ?: [];
        $removeLabels = $this->option('remove') ?: [];

        if (empty($threadIds)) {
            return $this->failWith('Missing thread IDs. Usage: gmcli gmail:labels:modify --thread-ids=<thread-id> [--add=<label>] [--remove=<label>]');
        }

        if (empty($addLabels) && empty($removeLabels)) {
            return $this->failWith('No labels to add or remove.');
        }

        if ($failure = $this->initGmail()) {
            return $failure;
        }

        $resolver = $this->loadLabels();

        $addResolved = $resolver->resolveMany($addLabels);
        $removeResolved = $resolver->resolveMany($removeLabels);

        if (! empty($addResolved['notFound'])) {
            $this->warn('Labels not found (add): '.implode(', ', $addResolved['notFound']));
        }
        if (! empty($removeResolved['notFound'])) {
            $this->warn('Labels not found (remove): '.implode(', ', $removeResolved['notFound']));
        }

        if (empty($addResolved['resolved']) && empty($removeResolved['resolved'])) {
            return $this->failWith('No valid labels to modify.');
        }

        foreach ($threadIds as $threadId) {
            $this->modifyThread($threadId, $addResolved['resolved'], $removeResolved['resolved']);
        }

        if ($this->wantsJson()) {
            return $this->outputJson([
                'threads' => $threadIds,
                'added' => $addResolved['resolved'],
                'removed' => $removeResolved['resolved'],
            ]);
        }

        $this->info('Labels modified.');

        return self::SUCCESS;
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
