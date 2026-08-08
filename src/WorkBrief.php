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
     * @param list<string> $behaviorAnchors Concrete runtime/request/consumer boundaries
     *        that must be inspected or verified when the task changes behavior.
     * @param list<string> $tags Project-defined relevance labels (domain, system, capability,
     *        or any other taxonomy), independent of directory layout. Recall consumers may match
     *        facts against these in addition to path scope.
     * @param list<OperatingPromptSelection> $operatingPrompts Approved reusable prompt recipes and
     *        explicit task-policy arguments. Resolution/rendering belongs to the recall layer.
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
        public array $tags = [],
        public array $behaviorAnchors = [],
        public ?string $operatingPromptManifest = null,
        public array $operatingPrompts = [],
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
            'tags' => $this->tags,
            'behavior_anchors' => $this->behaviorAnchors,
            'operating_prompt_manifest' => $this->operatingPromptManifest,
            'operating_prompts' => array_map(
                static fn (OperatingPromptSelection $selection): array => $selection->toArray(),
                $this->operatingPrompts,
            ),
            'status' => $this->status->value,
            'revision' => $this->revision,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
