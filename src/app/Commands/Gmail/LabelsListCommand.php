<?php

namespace App\Commands\Gmail;

/**
 * Lists all Gmail labels.
 */
class LabelsListCommand extends BaseGmailCommand
{
    protected $signature = 'gmail:labels:list';

    protected $description = 'List all Gmail labels';

    public function handle(): int
    {
        if ($failure = $this->initGmail()) {
            return $failure;
        }

        $this->logger->verbose('Fetching labels...');

        $response = $this->gmail->get('/users/me/labels');
        $labels = $response['labels'] ?? [];

        usort($labels, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        if ($this->wantsJson()) {
            $jsonLabels = array_map(fn ($l) => [
                'id' => $l['id'],
                'name' => $l['name'],
                'type' => $l['type'] ?? 'user',
            ], $labels);

            return $this->outputJson($jsonLabels);
        }

        foreach ($labels as $label) {
            $type = $label['type'] ?? 'user';
            $typeTag = $type === 'system' ? ' (system)' : '';
            $this->line("{$label['id']}\t{$label['name']}{$typeTag}");
        }

        return self::SUCCESS;
    }
}
