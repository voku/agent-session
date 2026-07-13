<?php

declare(strict_types=1);

namespace voku\AgentSession;

final readonly class LearningDecisionRecord
{
    public function __construct(
        public string $taskId,
        public LearningDecision $decision,
        public string $decidedBy,
        public ?string $reason,
        public string $decidedAt,
    ) {
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'schema_version' => '1.0',
            'task_id' => $this->taskId,
            'decision' => $this->decision->value,
            'decided_by' => $this->decidedBy,
            'reason' => $this->reason,
            'decided_at' => $this->decidedAt,
        ];
    }
}
