<?php

declare(strict_types=1);

namespace voku\AgentSession;

/**
 * An immutable view of a working-memory session.
 *
 * A session is temporary and mutable on disk by design; this object is just a
 * decoded snapshot of its `session.json` metadata plus the directory location.
 */
final readonly class Session
{
    /**
     * @param list<array{id: string, title: string, created_at: string}> $checkpoints
     */
    public function __construct(
        public string $id,
        public string $taskId,
        public SessionStatus $status,
        public ?string $claimedBy,
        public ?string $claimedAt,
        public ?string $baseCommit,
        public string $createdAt,
        public string $updatedAt,
        public array $checkpoints,
        public string $path,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => '1.0',
            'id' => $this->id,
            'task_id' => $this->taskId,
            'status' => $this->status->value,
            'claimed_by' => $this->claimedBy,
            'claimed_at' => $this->claimedAt,
            'base_commit' => $this->baseCommit,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'checkpoints' => $this->checkpoints,
        ];
    }
}
