<?php

namespace App\Commands\Gmail;

use App\Services\Analytics;
use App\Services\LabelResolver;

/**
 * Creates a Gmail filter.
 */
class FiltersCreateCommand extends BaseGmailCommand
{
    private const SYSTEM_LABELS = [
        'INBOX',
        'UNREAD',
        'STARRED',
        'TRASH',
        'SPAM',
    ];

    protected $signature = 'gmail:filters:create
        {--from= : Match sender}
        {--to= : Match recipient}
        {--subject= : Match subject}
        {--query= : Gmail query to include}
        {--negated-query= : Gmail query to exclude}
        {--has-attachment : Match messages with attachments}
        {--exclude-chats : Exclude chats}
        {--add-label=* : Labels to add}
        {--remove-label=* : Labels to remove}
        {--skip-inbox : Archive matching messages}
        {--mark-read : Mark matching messages as read}
        {--star : Star matching messages}
        {--trash : Send matching messages to trash}
        {--never-spam : Never send matching messages to spam}
        {--forward= : Forward matching messages}';

    protected $description = 'Create a Gmail filter';

    public function handle(Analytics $analytics): int
    {
        $startTime = microtime(true);

        if (! $this->hasCriteria()) {
            return $this->failCommand($analytics, $startTime, 'At least one filter criterion is required.');
        }

        if (! $this->hasAction()) {
            return $this->failCommand($analytics, $startTime, 'At least one filter action is required.');
        }

        if (! $this->initGmail()) {
            $analytics->track('gmail:filters:create', self::FAILURE, ['success' => false], $startTime);

            return self::FAILURE;
        }

        try {
            [$resolver, $labelsById] = $this->loadLabels();
            $addLabelIds = $this->resolveAddLabelIds($resolver, $labelsById, $this->option('add-label') ?: []);

            [$resolver, $labelsById] = $this->loadLabels();
            $removeLabelIds = $this->resolveExistingLabelIds($resolver, $this->option('remove-label') ?: [], 'remove');

            $payload = [
                'criteria' => $this->buildCriteria(),
                'action' => $this->buildAction($addLabelIds, $removeLabelIds),
            ];

            $filter = $this->gmail->post('/users/me/settings/filters', $payload);

            if ($this->shouldOutputJson()) {
                $analytics->track('gmail:filters:create', self::SUCCESS, ['success' => true], $startTime);

                return $this->outputJson($filter);
            }

            $this->info('Filter created: '.($filter['id'] ?? ''));

            $analytics->track('gmail:filters:create', self::SUCCESS, ['success' => true], $startTime);

            return self::SUCCESS;
        } catch (\RuntimeException $e) {
            $exception = $this->normalizeScopeError($e);

            $analytics->track('gmail:filters:create', self::FAILURE, ['success' => false], $startTime);

            if ($this->shouldOutputJson()) {
                return $this->jsonError($exception->getMessage());
            }

            $this->renderRuntimeException($exception);

            return self::FAILURE;
        }
    }

    private function hasCriteria(): bool
    {
        return ! empty(array_filter([
            $this->option('from'),
            $this->option('to'),
            $this->option('subject'),
            $this->option('query'),
            $this->option('negated-query'),
            $this->option('has-attachment'),
            $this->option('exclude-chats'),
        ]));
    }

    private function hasAction(): bool
    {
        return ! empty($this->option('add-label'))
            || ! empty($this->option('remove-label'))
            || $this->option('skip-inbox')
            || $this->option('mark-read')
            || $this->option('star')
            || $this->option('trash')
            || $this->option('never-spam')
            || ! empty($this->option('forward'));
    }

    private function failCommand(Analytics $analytics, float $startTime, string $message): int
    {
        $analytics->track('gmail:filters:create', self::FAILURE, ['success' => false], $startTime);

        if ($this->shouldOutputJson()) {
            return $this->jsonError($message);
        }

        $this->error($message);

        return self::FAILURE;
    }

    private function loadLabels(): array
    {
        $response = $this->gmail->get('/users/me/labels');
        $labels = $response['labels'] ?? [];
        $labelsById = [];

        foreach ($labels as $label) {
            $labelsById[$label['id']] = $label;
        }

        return [(new LabelResolver)->load($labels), $labelsById];
    }

    private function resolveAddLabelIds(LabelResolver $resolver, array $labelsById, array $labels): array
    {
        $resolved = $resolver->resolveMany($labels);
        $labelIds = $resolved['resolved'];

        foreach ($resolved['notFound'] as $label) {
            if ($this->looksLikeLabelId($label) || $this->isReservedSystemLabel($label)) {
                throw new \RuntimeException("Unable to find label to add: {$label}");
            }

            $created = $this->gmail->post('/users/me/labels', [
                'name' => $label,
                'labelListVisibility' => 'labelShow',
                'messageListVisibility' => 'show',
            ]);

            $labelIds[] = $created['id'] ?? '';
            $labelsById[$created['id']] = $created;
        }

        return $this->dedupe(array_filter($labelIds));
    }

    private function resolveExistingLabelIds(LabelResolver $resolver, array $labels, string $action): array
    {
        $resolved = $resolver->resolveMany($labels);

        if (! empty($resolved['notFound'])) {
            throw new \RuntimeException(
                'Unable to find label(s) to '.$action.': '.implode(', ', $resolved['notFound'])
            );
        }

        return $this->dedupe($resolved['resolved']);
    }

    private function buildCriteria(): array
    {
        return array_filter([
            'from' => $this->option('from'),
            'to' => $this->option('to'),
            'subject' => $this->option('subject'),
            'query' => $this->option('query'),
            'negatedQuery' => $this->option('negated-query'),
            'hasAttachment' => $this->option('has-attachment') ? true : null,
            'excludeChats' => $this->option('exclude-chats') ? true : null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function buildAction(array $addLabelIds, array $removeLabelIds): array
    {
        if ($this->option('skip-inbox')) {
            $removeLabelIds[] = 'INBOX';
        }

        if ($this->option('mark-read')) {
            $removeLabelIds[] = 'UNREAD';
        }

        if ($this->option('never-spam')) {
            $removeLabelIds[] = 'SPAM';
        }

        if ($this->option('star')) {
            $addLabelIds[] = 'STARRED';
        }

        if ($this->option('trash')) {
            $addLabelIds[] = 'TRASH';
        }

        $action = array_filter([
            'addLabelIds' => $this->dedupe($addLabelIds),
            'removeLabelIds' => $this->dedupe($removeLabelIds),
            'forward' => $this->option('forward') ?: null,
        ]);

        return $action;
    }

    private function dedupe(array $labelIds): array
    {
        return array_values(array_unique($labelIds));
    }

    private function looksLikeLabelId(string $label): bool
    {
        return str_starts_with($label, 'Label_');
    }

    private function isReservedSystemLabel(string $label): bool
    {
        return in_array(strtoupper($label), self::SYSTEM_LABELS, true);
    }
}
