<?php

declare(strict_types=1);

namespace voku\AgentSession;

/**
 * What a fresh agent needs to pick this Session up, projected from the Session's
 * own working memory.
 *
 * A resumed agent that re-reads plan.md, decisions.md, assumptions.md,
 * validation evidence and every checkpoint spends its first budget
 * reconstructing what the previous one already knew, and quietly redoes work
 * whose only record is prose it skimmed. The packet is derived, never stored:
 * working memory stays pruneable, and nothing here becomes a second source of
 * durable truth.
 */
final readonly class SessionHandoff
{
    /**
     * @param list<array{id: string, title: string, created_at: string}> $checkpoints
     * @param list<string> $decisions
     * @param list<string> $assumptions
     * @param list<ValidationEvidence> $validation
     */
    public function __construct(
        public string $sessionId,
        public string $taskId,
        public SessionStatus $status,
        public ?string $claimedBy,
        public ?string $baseCommit,
        public ?string $goal,
        public ?string $nextAction,
        public array $checkpoints,
        public ?string $latestCheckpoint,
        public array $decisions,
        public array $assumptions,
        public array $validation,
        public ?string $closedReason,
    ) {
    }

    /**
     * Observations that recorded a non-passing result.
     *
     * Kept separate because a failure is the single most expensive thing for a
     * resuming agent to rediscover: without it the obvious next move is to
     * re-run what already failed and reach the same wall.
     *
     * @return list<ValidationEvidence>
     */
    public function failures(): array
    {
        return array_values(array_filter(
            $this->validation,
            static fn (ValidationEvidence $evidence): bool => $evidence->status !== ValidationStatus::PASSED,
        ));
    }

    public function isResumable(): bool
    {
        return !$this->status->isClosed();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => '1.0',
            'session_id' => $this->sessionId,
            'task_id' => $this->taskId,
            'status' => $this->status->value,
            'resumable' => $this->isResumable(),
            'closed_reason' => $this->closedReason,
            'claimed_by' => $this->claimedBy,
            'base_commit' => $this->baseCommit,
            'goal' => $this->goal,
            'next_action' => $this->nextAction,
            'latest_checkpoint' => $this->latestCheckpoint,
            'checkpoints' => $this->checkpoints,
            'decisions' => $this->decisions,
            'assumptions' => $this->assumptions,
            'validation' => array_map(
                static fn (ValidationEvidence $evidence): array => $evidence->toArray(),
                $this->validation,
            ),
        ];
    }

    /**
     * The packet as prose, for an agent that reads a prompt rather than JSON.
     */
    public function toMarkdown(): string
    {
        $lines = ['# Session handoff: ' . $this->sessionId, ''];
        $lines[] = '- Task: ' . $this->taskId;
        $lines[] = '- Status: ' . $this->status->value
            . ($this->isResumable() ? '' : ' (not resumable' . ($this->closedReason === null ? '' : ': ' . $this->closedReason) . ')');
        $lines[] = '- Claimed by: ' . ($this->claimedBy ?? 'nobody');
        $lines[] = '- Base commit: ' . ($this->baseCommit ?? 'unrecorded');
        $lines[] = '';

        $lines = array_merge($lines, $this->prose('Goal', $this->goal));
        $lines = array_merge($lines, $this->prose('Next action', $this->nextAction));
        $lines = array_merge($lines, $this->prose('Latest checkpoint', $this->latestCheckpoint));
        $lines = array_merge($lines, $this->section('Decisions', $this->decisions));
        $lines = array_merge($lines, $this->section('Assumptions still unvalidated', $this->assumptions));

        $validation = [];
        foreach ($this->validation as $evidence) {
            $validation[] = sprintf(
                '`%s` - %s (exit %d, Contract revision %d)',
                $evidence->command,
                $evidence->status->value,
                $evidence->exitCode,
                $evidence->contractRevision,
            );
        }
        $lines = array_merge($lines, $this->section('Validation already run', $validation));

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param list<string> $items
     * @return list<string>
     */
    private function section(string $heading, array $items): array
    {
        if ($items === []) {
            return $this->empty($heading);
        }

        $lines = ['## ' . $heading, ''];
        foreach ($items as $item) {
            $lines[] = '- ' . $item;
        }
        $lines[] = '';

        return $lines;
    }

    /** @return list<string> */
    private function prose(string $heading, ?string $body): array
    {
        if ($body === null) {
            return $this->empty($heading);
        }

        return ['## ' . $heading, '', $body, ''];
    }

    /**
     * An empty section is still rendered.
     *
     * A resuming agent must be able to tell "nothing was recorded here" from
     * "this section was not part of the packet"; a silently omitted heading
     * reads as the second and invites re-deriving what is genuinely absent.
     *
     * @return list<string>
     */
    private function empty(string $heading): array
    {
        return ['## ' . $heading, '', '*nothing recorded*', ''];
    }
}
