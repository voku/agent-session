<?php

declare(strict_types=1);

namespace voku\AgentSession;

use DateTimeImmutable;
use DateTimeInterface;
use JsonException;
use RuntimeException;

final class ValidationEvidenceStore
{
    private const string FILE = 'validation-evidence.jsonl';

    public function record(
        Session $session,
        int $workBriefRevision,
        string $command,
        ValidationStatus $status,
        int $exitCode,
        ?int $durationMs = null,
        ?string $recordedBy = null,
        ?string $note = null,
    ): ValidationEvidence {
        $command = trim($command);
        if ($workBriefRevision < 1) {
            throw new RuntimeException('Validation evidence requires a positive --brief-revision.');
        }
        if ($command === '') {
            throw new RuntimeException('Validation evidence requires a non-empty --command.');
        }
        if ($exitCode < 0) {
            throw new RuntimeException('Validation evidence requires a non-negative --exit-code.');
        }
        if ($durationMs !== null && $durationMs < 0) {
            throw new RuntimeException('--duration-ms must be non-negative.');
        }

        $evidence = new ValidationEvidence(
            $session->taskId,
            $workBriefRevision,
            $command,
            $status,
            $exitCode,
            $durationMs,
            $this->nullable($recordedBy),
            $this->nullable($note),
            (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
        );
        $encoded = json_encode($evidence->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($this->path($session), $encoded . "\n", FILE_APPEND) === false) {
            throw new RuntimeException('Unable to append validation evidence.');
        }

        $line = sprintf(
            "\n## Validation evidence (work brief revision %d)\n\n- Command: `%s`\n- Status: %s\n- Exit: %d\n- Executed: %s\n",
            $evidence->workBriefRevision,
            $evidence->command,
            $evidence->status->value,
            $evidence->exitCode,
            $evidence->executedAt,
        );
        if ($evidence->durationMs !== null) {
            $line .= '- Duration: ' . $evidence->durationMs . "ms\n";
        }
        if ($evidence->note !== null) {
            $line .= '- Note: ' . $evidence->note . "\n";
        }
        if (file_put_contents($session->path . '/validation.md', $line, FILE_APPEND) === false) {
            throw new RuntimeException('Unable to append validation summary.');
        }

        return $evidence;
    }

    /** @return list<ValidationEvidence> */
    public function all(Session $session): array
    {
        $path = $this->path($session);
        if (!is_file($path)) {
            return [];
        }

        $evidence = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            try {
                $data = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('Invalid validation evidence JSON: ' . $exception->getMessage());
            }
            if (!is_array($data) || ($data['schema_version'] ?? null) !== '1.0' || ($data['task_id'] ?? null) !== $session->taskId) {
                throw new RuntimeException('Invalid validation evidence record.');
            }
            $status = is_string($data['status'] ?? null) ? ValidationStatus::tryFrom($data['status']) : null;
            if ($status === null || !is_int($data['work_brief_revision'] ?? null) || $data['work_brief_revision'] < 1 || !is_string($data['command'] ?? null) || trim($data['command']) === '' || !is_int($data['exit_code'] ?? null) || $data['exit_code'] < 0 || !is_string($data['executed_at'] ?? null)) {
                throw new RuntimeException('Invalid validation evidence record.');
            }
            $duration = $data['duration_ms'] ?? null;
            if ($duration !== null && (!is_int($duration) || $duration < 0)) {
                throw new RuntimeException('Invalid validation evidence duration.');
            }
            $evidence[] = new ValidationEvidence(
                $session->taskId,
                $data['work_brief_revision'],
                trim($data['command']),
                $status,
                $data['exit_code'],
                $duration,
                $this->nullableValue($data['recorded_by'] ?? null),
                $this->nullableValue($data['note'] ?? null),
                $data['executed_at'],
            );
        }

        return $evidence;
    }

    private function path(Session $session): string
    {
        return $session->path . '/' . self::FILE;
    }

    private function nullable(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }

    private function nullableValue(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
