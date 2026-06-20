<?php

declare(strict_types=1);

namespace voku\AgentSession;

/**
 * Initial working-memory files for a new session.
 *
 * These are starting points the agent is expected to keep up to date during the
 * task. They are intentionally short: working memory should help finish the
 * current task, not become a second source of durable truth.
 */
final class SessionScaffold
{
    /**
     * @return array<string, string> relative file path => contents
     */
    public function files(string $taskId): array
    {
        return [
            'plan.md' => <<<MD
            # Plan: {$taskId}

            ## Goal

            *What durable intent does this session serve? (mirror the task, do not redefine it)*

            ## Current checkpoint

            *none yet*

            ## Next action

            *the single next concrete step*

            ## Constraints

            - *boundaries that must hold (scope, permissions, types, no unrelated migration)*

            ## Done when

            - *the observable conditions that prove completion*

            MD,
            'assumptions.md' => <<<MD
            # Assumptions

            *Record what you had to assume because the repository did not answer it.*
            *Each assumption stays an assumption until validated.*

            MD,
            'decisions.md' => <<<MD
            # Decisions

            *Record observable decisions (context, decision, reason, validation).*
            *Not a transcript of internal reasoning.*

            MD,
            'validation.md' => <<<MD
            # Validation

            *Commands run and their status. Pending checks are honest, not hidden.*

            MD,
            'checkpoints/index.md' => <<<MD
            # Checkpoints

            *Resumable state. Each checkpoint records completed / open / blocked / next action.*

            MD,
        ];
    }

    public function record(string $kind, string $title, string $body): string
    {
        $heading = ucfirst($kind);
        $bodyText = trim($body);
        $block = "\n## {$heading}: {$title}\n";
        if ($bodyText !== '') {
            $block .= "\n{$bodyText}\n";
        }

        return $block;
    }

    public function checkpoint(string $checkpointId, string $title, string $body): string
    {
        $bodyText = trim($body);
        $content = "# Checkpoint {$checkpointId}: {$title}\n";
        if ($bodyText !== '') {
            $content .= "\n{$bodyText}\n";
        }

        return $content;
    }
}
