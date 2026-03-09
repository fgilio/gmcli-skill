<?php

namespace App\Commands\Gmail;

use App\Services\Analytics;
use App\Services\LabelResolver;

/**
 * Lists Gmail filters.
 */
class FiltersListCommand extends BaseGmailCommand
{
    protected $signature = 'gmail:filters:list';

    protected $description = 'List Gmail filters';

    public function handle(Analytics $analytics): int
    {
        $startTime = microtime(true);

        if (! $this->initGmail()) {
            $analytics->track('gmail:filters:list', self::FAILURE, ['count' => 0], $startTime);

            return self::FAILURE;
        }

        try {
            $resolver = $this->loadLabels();
            $response = $this->gmail->get('/users/me/settings/filters');
            $filters = $response['filter'] ?? [];

            if ($this->shouldOutputJson()) {
                $analytics->track('gmail:filters:list', self::SUCCESS, ['count' => count($filters)], $startTime);

                return $this->outputJson($filters);
            }

            foreach ($filters as $filter) {
                $criteria = $this->summarizeCriteria($filter['criteria'] ?? []);
                $action = $this->summarizeAction($filter['action'] ?? [], $resolver);

                $this->line(sprintf(
                    "%s\t%s\t%s",
                    $filter['id'] ?? '',
                    $criteria ?: '-',
                    $action ?: '-'
                ));
            }

            $analytics->track('gmail:filters:list', self::SUCCESS, ['count' => count($filters)], $startTime);

            return self::SUCCESS;
        } catch (\RuntimeException $e) {
            $analytics->track('gmail:filters:list', self::FAILURE, ['count' => 0], $startTime);

            if ($this->shouldOutputJson()) {
                return $this->jsonError($e->getMessage());
            }

            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function loadLabels(): LabelResolver
    {
        $response = $this->gmail->get('/users/me/labels');
        $labels = $response['labels'] ?? [];

        return (new LabelResolver)->load($labels);
    }

    private function summarizeCriteria(array $criteria): string
    {
        $parts = [];

        foreach ([
            'from' => 'from',
            'to' => 'to',
            'subject' => 'subject',
            'query' => 'query',
            'negatedQuery' => 'not',
        ] as $field => $label) {
            if (! empty($criteria[$field])) {
                $parts[] = "{$label}:{$criteria[$field]}";
            }
        }

        if (! empty($criteria['hasAttachment'])) {
            $parts[] = 'has:attachment';
        }

        if (! empty($criteria['excludeChats'])) {
            $parts[] = 'exclude:chats';
        }

        return implode(', ', $parts);
    }

    private function summarizeAction(array $action, LabelResolver $resolver): string
    {
        $parts = [];

        foreach (($action['addLabelIds'] ?? []) as $labelId) {
            $parts[] = '+'.($resolver->getName($labelId) ?? $labelId);
        }

        foreach (($action['removeLabelIds'] ?? []) as $labelId) {
            $parts[] = '-'.($resolver->getName($labelId) ?? $labelId);
        }

        if (! empty($action['forward'])) {
            $parts[] = 'forward:'.$action['forward'];
        }

        return implode(', ', $parts);
    }
}
