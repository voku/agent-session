<?php

declare(strict_types=1);

namespace voku\AgentSession;

use DateTimeImmutable;
use DateTimeInterface;
use JsonException;
use RuntimeException;

/**
 * Stores a session-local work brief, its approval, and immutable superseded
 * revisions. Existing sessions need no migration: a missing work brief simply
 * means that none has been proposed yet.
 */
final class WorkBriefStore
{
    private const string WORK_BRIEF_FILE = 'work-brief.json';
    private const string WORK_BRIEF_MARKDOWN_FILE = 'work-brief.md';
    private const string APPROVAL_FILE = 'approval.json';
    private const string HISTORY_DIRECTORY = 'work-brief-history';

    /**
     * @param list<string> $scope
     * @param list<string> $nonGoals
     * @param list<string> $validation
     * @param list<string> $tags
     */
    public function create(Session $session, string $goal, array $scope, array $nonGoals, array $validation, array $tags = []): WorkBrief
    {
        if ($this->find($session) !== null) {
            throw new RuntimeException(sprintf("Session '%s' already has a work brief. Use revise instead.", $session->id));
        }

        $now = $this->now();
        $brief = $this->newBrief($session, $goal, $scope, $nonGoals, $validation, $tags, WorkBriefStatus::CANDIDATE, 1, $now, $now);
        $this->writeBrief($brief);

        return $brief;
    }

    public function find(Session $session): ?WorkBrief
    {
        $path = $this->workBriefPath($session);
        if (!is_file($path)) {
            return null;
        }

        return $this->decodeBrief((string) file_get_contents($path), $path, $session->taskId, $path);
    }

    public function load(Session $session): WorkBrief
    {
        return $this->find($session) ?? throw new RuntimeException(sprintf("Session '%s' has no work brief.", $session->id));
    }

    public function approval(Session $session): ?Approval
    {
        $path = $this->approvalPath($session);
        if (!is_file($path)) {
            return null;
        }

        $data = $this->decode((string) file_get_contents($path), $path);
        $this->assertSchemaVersion($data, $path);

        $taskId = $this->requiredString($data, 'task_id', $path);
        if ($taskId !== $session->taskId) {
            throw new RuntimeException(sprintf('Approval task id in %s does not match the session.', $path));
        }

        $revision = $this->positiveInt($data, 'work_brief_revision', $path);

        return new Approval(
            $taskId,
            $revision,
            $this->requiredString($data, 'approved_by', $path),
            $this->requiredString($data, 'approved_at', $path),
            $path,
        );
    }

    public function approve(Session $session, string $by): Approval
    {
        $brief = $this->load($session);
        if ($brief->status !== WorkBriefStatus::CANDIDATE) {
            throw new RuntimeException(sprintf('Work brief revision %d is not awaiting approval.', $brief->revision));
        }

        $by = trim($by);
        if ($by === '') {
            throw new RuntimeException('Work-brief approval requires --by <actor>.');
        }

        $now = $this->now();
        $approved = new WorkBrief(
            $brief->taskId,
            $brief->goal,
            $brief->scope,
            $brief->nonGoals,
            $brief->validation,
            WorkBriefStatus::APPROVED,
            $brief->revision,
            $brief->createdAt,
            $now,
            $brief->path,
            $brief->tags,
        );
        $this->writeBrief($approved);

        $approval = new Approval($brief->taskId, $brief->revision, $by, $now, $this->approvalPath($session));
        $this->writeJson($approval->path, $approval->toArray());

        return $approval;
    }

    /**
     * @param list<string> $scope
     * @param list<string> $nonGoals
     * @param list<string> $validation
     * @param list<string> $tags
     */
    public function revise(Session $session, string $goal, array $scope, array $nonGoals, array $validation, array $tags = []): WorkBrief
    {
        $previous = $this->load($session);
        $now = $this->now();
        $brief = $this->newBrief(
            $session,
            $goal,
            $scope,
            $nonGoals,
            $validation,
            $tags,
            WorkBriefStatus::CANDIDATE,
            $previous->revision + 1,
            $now,
            $now,
        );
        $this->archive($session, $previous, $this->approval($session));
        $this->writeBrief($brief);

        $approvalPath = $this->approvalPath($session);
        if (is_file($approvalPath) && !unlink($approvalPath)) {
            throw new RuntimeException(sprintf('Failed to invalidate approval: %s', $approvalPath));
        }

        return $brief;
    }

    private function archive(Session $session, WorkBrief $brief, ?Approval $approval): void
    {
        $directory = $session->path . '/' . self::HISTORY_DIRECTORY;
        $this->makeDirectory($directory);

        $superseded = new WorkBrief(
            $brief->taskId,
            $brief->goal,
            $brief->scope,
            $brief->nonGoals,
            $brief->validation,
            WorkBriefStatus::SUPERSEDED,
            $brief->revision,
            $brief->createdAt,
            $this->now(),
            $directory . '/' . $this->historyFilename($brief->revision),
            $brief->tags,
        );
        $this->writeJson($superseded->path, $superseded->toArray());

        if ($approval !== null) {
            $this->writeJson($directory . '/' . sprintf('approval.%03d.json', $brief->revision), $approval->toArray());
        }
    }

    /**
     * @param list<string> $scope
     * @param list<string> $nonGoals
     * @param list<string> $validation
     * @param list<string> $tags
     */
    private function newBrief(
        Session $session,
        string $goal,
        array $scope,
        array $nonGoals,
        array $validation,
        array $tags,
        WorkBriefStatus $status,
        int $revision,
        string $createdAt,
        string $updatedAt,
    ): WorkBrief {
        $goal = trim($goal);
        if ($goal === '') {
            throw new RuntimeException('A work brief requires a non-empty --goal.');
        }

        $scope = $this->normalizedLines($scope);
        if ($scope === []) {
            throw new RuntimeException('A work brief requires at least one --scope path.');
        }

        $validation = $this->normalizedLines($validation);
        if ($validation === []) {
            throw new RuntimeException('A work brief requires at least one --validation command.');
        }

        return new WorkBrief(
            $session->taskId,
            $goal,
            $scope,
            $this->normalizedLines($nonGoals),
            $validation,
            $status,
            $revision,
            $createdAt,
            $updatedAt,
            $this->workBriefPath($session),
            $this->normalizedLines($tags),
        );
    }

    private function writeBrief(WorkBrief $brief): void
    {
        $this->writeJson($brief->path, $brief->toArray());
        $this->writeFile(dirname($brief->path) . '/' . self::WORK_BRIEF_MARKDOWN_FILE, $this->renderMarkdown($brief));
    }

    private function workBriefPath(Session $session): string
    {
        return $session->path . '/' . self::WORK_BRIEF_FILE;
    }

    private function approvalPath(Session $session): string
    {
        return $session->path . '/' . self::APPROVAL_FILE;
    }

    private function historyFilename(int $revision): string
    {
        return sprintf('work-brief.%03d.json', $revision);
    }

    private function renderMarkdown(WorkBrief $brief): string
    {
        $lines = [
            '# Work brief: ' . $brief->taskId,
            '',
            'Status: ' . $brief->status->value,
            'Revision: ' . $brief->revision,
            '',
            '## Goal',
            '',
            $brief->goal,
            '',
            '## Approved scope',
            '',
            ...array_map(static fn (string $item): string => '- `' . $item . '`', $brief->scope),
            '',
            '## Non-goals',
            '',
            ...($brief->nonGoals === [] ? ['- None recorded.'] : array_map(static fn (string $item): string => '- ' . $item, $brief->nonGoals)),
            '',
            '## Validation',
            '',
            ...array_map(static fn (string $item): string => '- `' . $item . '`', $brief->validation),
            '',
            '## Relevance tags',
            '',
            ...($brief->tags === [] ? ['- None recorded.'] : array_map(static fn (string $item): string => '- `' . $item . '`', $brief->tags)),
            '',
        ];

        return implode("\n", $lines);
    }

    private function decodeBrief(string $json, string $jsonPath, string $expectedTaskId, string $briefPath): WorkBrief
    {
        $data = $this->decode($json, $jsonPath);
        $this->assertSchemaVersion($data, $jsonPath);

        $taskId = $this->requiredString($data, 'task_id', $jsonPath);
        if ($taskId !== $expectedTaskId) {
            throw new RuntimeException(sprintf('Work brief task id in %s does not match the session.', $jsonPath));
        }

        $statusValue = $this->requiredString($data, 'status', $jsonPath);
        $status = WorkBriefStatus::tryFromString($statusValue);
        if ($status === null) {
            throw new RuntimeException(sprintf('Unsupported work-brief status in %s: %s', $jsonPath, $statusValue));
        }

        return new WorkBrief(
            $taskId,
            $this->requiredString($data, 'goal', $jsonPath),
            $this->listField($data, 'scope', $jsonPath, true),
            $this->listField($data, 'non_goals', $jsonPath),
            $this->listField($data, 'validation', $jsonPath, true),
            $status,
            $this->positiveInt($data, 'revision', $jsonPath),
            $this->requiredString($data, 'created_at', $jsonPath),
            $this->requiredString($data, 'updated_at', $jsonPath),
            $briefPath,
            $this->listField($data, 'tags', $jsonPath),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json, string $path): array
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(sprintf('Invalid work-brief JSON in %s: %s', $path, $e->getMessage()));
        }

        if (!is_array($data)) {
            throw new RuntimeException(sprintf('Invalid work-brief JSON in %s.', $path));
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function assertSchemaVersion(array $data, string $path): void
    {
        if (($data['schema_version'] ?? null) !== '1.0') {
            throw new RuntimeException(sprintf('Unsupported work-brief schema version in %s.', $path));
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requiredString(array $data, string $field, string $path): string
    {
        $value = $data[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException(sprintf('Work brief %s requires a non-empty %s.', $path, $field));
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function positiveInt(array $data, string $field, string $path): int
    {
        $value = $data[$field] ?? null;
        if (!is_int($value) || $value < 1) {
            throw new RuntimeException(sprintf('Work brief %s requires a positive %s.', $path, $field));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function listField(array $data, string $field, string $path, bool $required = false): array
    {
        $value = $data[$field] ?? [];
        if (!is_array($value)) {
            throw new RuntimeException(sprintf('Work brief %s requires a list for %s.', $path, $field));
        }

        $lines = $this->normalizedLines($value);
        if ($required && $lines === []) {
            throw new RuntimeException(sprintf('Work brief %s requires at least one %s entry.', $path, $field));
        }

        return $lines;
    }

    /**
     * @param array<mixed> $values
     * @return list<string>
     */
    private function normalizedLines(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new RuntimeException('Work-brief list entries must be strings.');
            }
            $value = trim($value);
            if ($value !== '' && !in_array($value, $normalized, true)) {
                $normalized[] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeJson(string $path, array $data): void
    {
        $this->writeFile($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function writeFile(string $path, string $contents): void
    {
        $this->makeDirectory(dirname($path));
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException(sprintf('Failed to write work brief file: %s', $path));
        }
    }

    private function makeDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
            throw new RuntimeException(sprintf('Directory "%s" was not created.', $path));
        }
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM);
    }
}
