<?php

declare(strict_types=1);

namespace voku\AgentSession;

/**
 * Approval metadata deliberately names the work-brief revision it approves.
 */
final readonly class Approval
{
    public function __construct(
        public string $taskId,
        public int $workBriefRevision,
        public string $approvedBy,
        public string $approvedAt,
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
            'task_id' => $this->taskId,
            'work_brief_revision' => $this->workBriefRevision,
            'approved_by' => $this->approvedBy,
            'approved_at' => $this->approvedAt,
        ];
    }
}
