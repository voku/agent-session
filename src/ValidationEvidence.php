<?php

declare(strict_types=1);

namespace voku\AgentSession;

final readonly class ValidationEvidence
{
    public function __construct(
        public string $taskId,
        public int $workBriefRevision,
        public string $command,
        public ValidationStatus $status,
        public int $exitCode,
        public ?int $durationMs,
        public ?string $recordedBy,
        public ?string $note,
        public string $executedAt,
    ) {
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'schema_version' => '1.0',
            'task_id' => $this->taskId,
            'work_brief_revision' => $this->workBriefRevision,
            'command' => $this->command,
            'status' => $this->status->value,
            'exit_code' => $this->exitCode,
            'duration_ms' => $this->durationMs,
            'recorded_by' => $this->recordedBy,
            'note' => $this->note,
            'executed_at' => $this->executedAt,
        ];
    }
}
