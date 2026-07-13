<?php

declare(strict_types=1);

namespace voku\AgentSession;

use Throwable;

/**
 * Hand-rolled CLI for the working-memory layer.
 *
 * Sessions live under a sessions root (default `<cwd>/session_plan`). Override
 * with `--root`.
 */
final class Cli
{
    private readonly SessionStore $store;
    private readonly WorkBriefStore $workBriefs;

    public function __construct(?SessionStore $store = null, ?WorkBriefStore $workBriefs = null)
    {
        $this->store = $store ?? new SessionStore();
        $this->workBriefs = $workBriefs ?? new WorkBriefStore();
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $tokens = $argv;
        array_shift($tokens);
        $command = array_shift($tokens) ?? 'help';

        try {
            return match ($command) {
                'start' => $this->startCommand($tokens),
                'claim' => $this->claimCommand($tokens),
                'checkpoint' => $this->checkpointCommand($tokens),
                'record' => $this->recordCommand($tokens),
                'close' => $this->closeCommand($tokens),
                'list' => $this->listCommand($tokens),
                'show' => $this->showCommand($tokens),
                'brief' => $this->briefCommand($tokens),
                'prune' => $this->pruneCommand($tokens),
                'help', '--help', '-h' => $this->helpCommand(),
                default => $this->unknownCommand($command),
            };
        } catch (Throwable $e) {
            fwrite(\STDERR, 'Error: ' . $e->getMessage() . "\n");

            return 1;
        }
    }

    /**
     * @param list<string> $tokens
     */
    private function startCommand(array $tokens): int
    {
        $parsed = $this->parseOptions($tokens);
        $root = $this->resolveRoot($parsed['options']);

        $session = $this->store->create(
            $root,
            $this->stringOption($parsed['options'], 'task') ?? '',
            $this->stringOption($parsed['options'], 'slug'),
            $this->stringOption($parsed['options'], 'by'),
            $this->stringOption($parsed['options'], 'base-commit'),
        );

        fwrite(\STDOUT, sprintf("Started session: %s\n", $session->id));
        fwrite(\STDOUT, sprintf("- path: %s\n", $session->path));
        fwrite(\STDOUT, "- working-memory files: plan.md, assumptions.md, decisions.md, validation.md, checkpoints/\n");

        return 0;
    }

    /**
     * @param list<string> $tokens
     */
    private function claimCommand(array $tokens): int
    {
        $parsed = $this->parseOptions($tokens);
        $root = $this->resolveRoot($parsed['options']);
        $session = $this->store->load($root, $this->requireId($parsed['arguments']));

        $by = $this->stringOption($parsed['options'], 'by');
        if ($by === null || trim($by) === '') {
            throw new \InvalidArgumentException('claim requires --by <actor>.');
        }

        if (
            $session->claimedBy !== null
            && $session->claimedBy !== trim($by)
            && $session->status === SessionStatus::ACTIVE
            && !$this->hasFlag($parsed['options'], 'force')
        ) {
            throw new \RuntimeException(sprintf(
                "Session '%s' is already claimed by '%s'. Use --force to take it over.",
                $session->id,
                $session->claimedBy,
            ));
        }

        $session = $this->store->claim($session, $by, $this->stringOption($parsed['options'], 'base-commit'));
        fwrite(\STDOUT, sprintf("Claimed session '%s' for '%s'.\n", $session->id, (string) $session->claimedBy));

        return 0;
    }

    /**
     * @param list<string> $tokens
     */
    private function checkpointCommand(array $tokens): int
    {
        $parsed = $this->parseOptions($tokens);
        $root = $this->resolveRoot($parsed['options']);
        $session = $this->store->load($root, $this->requireId($parsed['arguments']));

        $session = $this->store->addCheckpoint(
            $session,
            $this->stringOption($parsed['options'], 'title') ?? '',
            $this->stringOption($parsed['options'], 'body') ?? '',
        );

        $last = $session->checkpoints[count($session->checkpoints) - 1] ?? null;
        fwrite(\STDOUT, sprintf("Recorded checkpoint %s on session '%s'.\n", $last['id'] ?? '?', $session->id));

        return 0;
    }

    /**
     * @param list<string> $tokens
     */
    private function recordCommand(array $tokens): int
    {
        $parsed = $this->parseOptions($tokens);
        $root = $this->resolveRoot($parsed['options']);
        $session = $this->store->load($root, $this->requireId($parsed['arguments']));

        $kind = $this->stringOption($parsed['options'], 'kind') ?? '';
        $this->store->appendRecord(
            $session,
            strtolower(trim($kind)),
            $this->stringOption($parsed['options'], 'title') ?? '',
            $this->stringOption($parsed['options'], 'body') ?? '',
        );

        fwrite(\STDOUT, sprintf("Recorded %s on session '%s'.\n", strtolower(trim($kind)), $session->id));

        return 0;
    }

    /**
     * @param list<string> $tokens
     */
    private function closeCommand(array $tokens): int
    {
        $parsed = $this->parseOptions($tokens);
        $root = $this->resolveRoot($parsed['options']);
        $session = $this->store->load($root, $this->requireId($parsed['arguments']));

        $statusValue = $this->stringOption($parsed['options'], 'status') ?? 'done';
        $status = SessionStatus::tryFromString($statusValue);
        if ($status === null || !$status->isClosed()) {
            throw new \InvalidArgumentException('close requires --status done or --status dropped.');
        }

        $session = $this->store->setStatus($session, $status);
        fwrite(\STDOUT, sprintf("Closed session '%s' as %s.\n", $session->id, $session->status->value));

        return 0;
    }

    /**
     * @param list<string> $tokens
     */
    private function listCommand(array $tokens): int
    {
        $parsed = $this->parseOptions($tokens);
        $root = $this->resolveRoot($parsed['options']);
        $statusFilter = SessionStatus::tryFromString($this->stringOption($parsed['options'], 'status') ?? '');

        $sessions = $this->store->all($root);
        $shown = 0;
        foreach ($sessions as $session) {
            if ($statusFilter !== null && $session->status !== $statusFilter) {
                continue;
            }
            fwrite(\STDOUT, sprintf(
                "%-40s %-8s task=%s claimed_by=%s\n",
                $session->id,
                $session->status->value,
                $session->taskId,
                $session->claimedBy ?? '-',
            ));
            ++$shown;
        }

        if ($shown === 0) {
            fwrite(\STDOUT, "No sessions found.\n");
        }

        return 0;
    }

    /**
     * @param list<string> $tokens
     */
    private function showCommand(array $tokens): int
    {
        $parsed = $this->parseOptions($tokens);
        $root = $this->resolveRoot($parsed['options']);
        $session = $this->store->load($root, $this->requireId($parsed['arguments']));

        fwrite(\STDOUT, json_encode($session->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");

        return 0;
    }

    /**
     * @param list<string> $tokens
     */
    private function briefCommand(array $tokens): int
    {
        $action = array_shift($tokens) ?? 'help';
        if (in_array($action, ['help', '--help', '-h'], true)) {
            return $this->briefHelpCommand();
        }

        $parsed = $this->parseOptions($tokens);
        $root = $this->resolveRoot($parsed['options']);
        $session = $this->store->load($root, $this->requireId($parsed['arguments']));

        return match ($action) {
            'create' => $this->createBriefCommand($session, $parsed['options']),
            'revise' => $this->reviseBriefCommand($session, $parsed['options']),
            'approve' => $this->approveBriefCommand($session, $parsed['options']),
            'show' => $this->showBriefCommand($session),
            default => $this->unknownBriefAction($action),
        };
    }

    /**
     * @param array<string, list<string>> $options
     */
    private function createBriefCommand(Session $session, array $options): int
    {
        $brief = $this->workBriefs->create(
            $session,
            $this->stringOption($options, 'goal') ?? '',
            $this->stringOptions($options, 'scope'),
            $this->stringOptions($options, 'non-goal'),
            $this->stringOptions($options, 'validation'),
        );
        fwrite(STDOUT, sprintf("Created work brief revision %d for session '%s'.\n", $brief->revision, $session->id));

        return 0;
    }

    /**
     * @param array<string, list<string>> $options
     */
    private function reviseBriefCommand(Session $session, array $options): int
    {
        $brief = $this->workBriefs->revise(
            $session,
            $this->stringOption($options, 'goal') ?? '',
            $this->stringOptions($options, 'scope'),
            $this->stringOptions($options, 'non-goal'),
            $this->stringOptions($options, 'validation'),
        );
        fwrite(STDOUT, sprintf("Created candidate work brief revision %d for session '%s'; prior approval is superseded.\n", $brief->revision, $session->id));

        return 0;
    }

    /**
     * @param array<string, list<string>> $options
     */
    private function approveBriefCommand(Session $session, array $options): int
    {
        $approval = $this->workBriefs->approve($session, $this->stringOption($options, 'by') ?? '');
        fwrite(STDOUT, sprintf("Approved work brief revision %d for session '%s' by '%s'.\n", $approval->workBriefRevision, $session->id, $approval->approvedBy));

        return 0;
    }

    private function showBriefCommand(Session $session): int
    {
        $brief = $this->workBriefs->load($session);
        $data = $brief->toArray();
        $data['approval'] = $this->workBriefs->approval($session)?->toArray();
        fwrite(STDOUT, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");

        return 0;
    }

    /**
     * @param list<string> $tokens
     */
    private function pruneCommand(array $tokens): int
    {
        $parsed = $this->parseOptions($tokens);
        $root = $this->resolveRoot($parsed['options']);
        $keepDays = (int) ($this->stringOption($parsed['options'], 'keep-days') ?? '30');
        $dryRun = $this->hasFlag($parsed['options'], 'dry-run');

        $statuses = $this->parseStatuses($this->stringOption($parsed['options'], 'status') ?? 'done,dropped');

        $removed = $this->store->prune($root, $keepDays, $statuses, $dryRun);

        $verb = $dryRun ? 'Would prune' : 'Pruned';
        fwrite(\STDOUT, sprintf("%s %d session(s) older than %d day(s).\n", $verb, count($removed), $keepDays));
        foreach ($removed as $id) {
            fwrite(\STDOUT, '- ' . $id . "\n");
        }

        return 0;
    }

    /**
     * @return list<SessionStatus>
     */
    private function parseStatuses(string $value): array
    {
        $statuses = [];
        foreach (explode(',', $value) as $part) {
            $status = SessionStatus::tryFromString($part);
            if ($status !== null) {
                $statuses[] = $status;
            }
        }

        return $statuses === [] ? [SessionStatus::DONE, SessionStatus::DROPPED] : $statuses;
    }

    private function helpCommand(): int
    {
        fwrite(\STDOUT, <<<TXT
        agent-session - working memory for coding-agent tasks.

        Usage:
          agent-session <command> [options]

        Commands:
          start       Start a session.   --task ID [--by ACTOR] [--base-commit SHA] [--slug S]
          claim       Claim a session.   <id> --by ACTOR [--base-commit SHA] [--force]
          checkpoint  Add a checkpoint.  <id> --title T [--body TEXT]
          record      Add a record.      <id> --kind decision|assumption --title T [--body TEXT]
          close       Close a session.   <id> --status done|dropped
          list        List sessions.     [--status STATUS]
          show        Show metadata.     <id>
          brief       Manage a work brief. <create|revise|approve|show> <id> [options]
          prune       Retention cleanup. [--keep-days N] [--status done,dropped] [--dry-run]

        Global:
          --root PATH   Sessions root directory (default: <cwd>/session_plan).

        TXT);

        return 0;
    }

    private function briefHelpCommand(): int
    {
        fwrite(STDOUT, <<<TXT
        agent-session brief - versioned work-brief and approval artifacts.

        Usage:
          agent-session brief create <id> --goal TEXT --scope PATH [--scope PATH] [--non-goal TEXT] --validation COMMAND [--validation COMMAND]
          agent-session brief revise <id> --goal TEXT --scope PATH [--scope PATH] [--non-goal TEXT] --validation COMMAND [--validation COMMAND]
          agent-session brief approve <id> --by ACTOR
          agent-session brief show <id>

        Revising a brief archives the prior revision as superseded and clears
        its current approval. The historical work brief and approval remain in
        work-brief-history/ for audit.

        TXT);

        return 0;
    }

    private function unknownBriefAction(string $action): int
    {
        fwrite(STDERR, 'Unknown brief action: ' . $action . "\n");
        fwrite(STDERR, "Run 'agent-session brief help' to view usage.\n");

        return 1;
    }

    private function unknownCommand(string $command): int
    {
        fwrite(\STDERR, 'Unknown command: ' . $command . "\n");
        fwrite(\STDERR, "Run 'agent-session help' to view usage.\n");

        return 1;
    }

    /**
     * @param array<string, list<string>> $options
     */
    private function resolveRoot(array $options): string
    {
        $root = $this->stringOption($options, 'root');
        if ($root !== null && trim($root) !== '') {
            return $root;
        }

        return (getcwd() ?: '.') . '/session_plan';
    }

    /**
     * @param list<string> $arguments
     */
    private function requireId(array $arguments): string
    {
        $id = $arguments[0] ?? '';
        if (trim($id) === '') {
            throw new \InvalidArgumentException('This command requires a session id argument.');
        }

        return $id;
    }

    /**
     * @param list<string> $tokens
     * @return array{options: array<string, list<string>>, arguments: list<string>}
     */
    private function parseOptions(array $tokens): array
    {
        $options = [];
        $arguments = [];
        $count = count($tokens);
        $i = 0;
        while ($i < $count) {
            $token = $tokens[$i];
            if (str_starts_with($token, '--')) {
                $name = substr($token, 2);
                $value = '';
                if ($i + 1 < $count && !str_starts_with($tokens[$i + 1], '--')) {
                    $value = $tokens[$i + 1];
                    ++$i;
                }
                $options[$name][] = $value;
            } else {
                $arguments[] = $token;
            }
            ++$i;
        }

        return ['options' => $options, 'arguments' => $arguments];
    }

    /**
     * @param array<string, list<string>> $options
     */
    private function stringOption(array $options, string $name): ?string
    {
        return $options[$name][0] ?? null;
    }

    /**
     * @param array<string, list<string>> $options
     * @return list<string>
     */
    private function stringOptions(array $options, string $name): array
    {
        return $options[$name] ?? [];
    }

    /**
     * @param array<string, list<string>> $options
     */
    private function hasFlag(array $options, string $name): bool
    {
        return isset($options[$name]);
    }
}
