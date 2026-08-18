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
        /**
         * An experiment, not governed work: created to try a command out, never approved, and never
         * meant to be finished. Repository-wide gates must ignore it, because a throwaway that
         * blocks every other session's close is a gate punishing the wrong thing.
         */
        public bool $ephemeral = false,
        /**
         * When and why this Session stopped being open working memory.
         *
         * A Session can be retired by a human (`close`) or deterministically by
         * a governed owner that superseded the Run it served. Both may use the
         * same closed status, so the reason is recorded rather than inferred
         * while this pruneable Session still exists. Durable lifecycle
         * provenance that must survive pruning belongs to the durable owner.
         */
        public ?string $closedAt = null,
        public ?string $closedReason = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => '1.1',
            'id' => $this->id,
            'task_id' => $this->taskId,
            'status' => $this->status->value,
            'claimed_by' => $this->claimedBy,
            'claimed_at' => $this->claimedAt,
            'base_commit' => $this->baseCommit,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'checkpoints' => $this->checkpoints,
            'ephemeral' => $this->ephemeral,
            'closed_at' => $this->closedAt,
            'closed_reason' => $this->closedReason,
        ];
    }
}
