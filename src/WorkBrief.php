<?php

declare(strict_types=1);

namespace voku\AgentSession;

/**
 * A revisioned, reviewable contract for one session's intended work.
 *
 * This remains session-local working memory. It records an approved task
 * boundary; it does not approve code or durable learning.
 */
final readonly class WorkBrief
{
    /**
     * @param list<string> $scope
     * @param list<string> $nonGoals
     * @param list<string> $validation
     */
    public function __construct(
        public string $taskId,
        public string $goal,
        public array $scope,
        public array $nonGoals,
        public array $validation,
        public WorkBriefStatus $status,
        public int $revision,
        public string $createdAt,
        public string $updatedAt,
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
            'goal' => $this->goal,
            'scope' => $this->scope,
            'non_goals' => $this->nonGoals,
            'validation' => $this->validation,
            'status' => $this->status->value,
            'revision' => $this->revision,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
