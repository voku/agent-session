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

    public function __construct(?SessionStore $store = null)
    {
        $this->store = $store ?? new SessionStore();
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
          prune       Retention cleanup. [--keep-days N] [--status done,dropped] [--dry-run]

        Global:
          --root PATH   Sessions root directory (default: <cwd>/session_plan).

        TXT);

        return 0;
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
     */
    private function hasFlag(array $options, string $name): bool
    {
        return isset($options[$name]);
    }
}
