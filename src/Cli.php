<?php

declare(strict_types=1);

namespace voku\AgentSession;

use Throwable;

/**
 * Hand-rolled CLI for run-scoped working memory.
 *
 * Sessions live under a sessions root (default `<cwd>/.agent-loop/sessions`). Override
 * with `--root`.
 */
final class Cli
{
    private readonly SessionStore $store;
    private readonly ValidationEvidenceStore $validationEvidence;

    /** @var resource */
    private $out;

    /** @var resource */
    private $err;

    /**
     * Output goes to `php://output`, not to the `STDOUT` constant.
     *
     * A PHP host that embeds this CLI has to be able to capture or discard what
     * it prints - a structured host response is corrupted by a stray progress
     * line. Writing to the `STDOUT` constant bypasses the host's own output
     * buffering, which leaves the host inventing stream filters to silence a
     * library it already called in-process.
     *
     * @param resource|null $out
     * @param resource|null $err
     */
    public function __construct(
        ?SessionStore $store = null,
        ?ValidationEvidenceStore $validationEvidence = null,
        $out = null,
        $err = null,
    ) {
        $this->store = $store ?? new SessionStore();
        $this->validationEvidence = $validationEvidence ?? new ValidationEvidenceStore();

        $resolvedOut = $out ?? fopen('php://output', 'wb');
        $resolvedErr = $err ?? fopen('php://stderr', 'wb');
        if (!is_resource($resolvedOut) || !is_resource($resolvedErr)) {
            throw new \RuntimeException('Unable to open CLI output streams.');
        }

        $this->out = $resolvedOut;
        $this->err = $resolvedErr;
    }

    /** @param list<string> $argv */
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
                'handoff' => $this->handoffCommand($tokens),
                'validation' => $this->validationCommand($tokens),
                'prune' => $this->pruneCommand($tokens),
                'help', '--help', '-h' => $this->helpCommand(),
                default => $this->unknownCommand($command),
            };
        } catch (Throwable $e) {
            fwrite($this->err, 'Error: ' . $e->getMessage() . "\n");

            return 1;
        }
    }

    /** @param list<string> $tokens */
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
            $this->hasFlag($parsed['options'], 'ephemeral'),
        );

        fwrite($this->out, sprintf("Started session: %s\n", $session->id));
        fwrite($this->out, sprintf("- path: %s\n", $session->path));
        fwrite($this->out, "- working-memory files: plan.md, assumptions.md, decisions.md, validation.md, checkpoints/\n");
        if ($session->ephemeral) {
            fwrite($this->out, "- ephemeral: repository-wide gates ignore this session; close it when the experiment is over\n");
        }

        return 0;
    }

    /** @param list<string> $tokens */
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
        fwrite($this->out, sprintf("Claimed session '%s' for '%s'.\n", $session->id, (string) $session->claimedBy));

        return 0;
    }

    /** @param list<string> $tokens */
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
        fwrite($this->out, sprintf("Recorded checkpoint %s on session '%s'.\n", $last['id'] ?? '?', $session->id));

        return 0;
    }

    /** @param list<string> $tokens */
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

        fwrite($this->out, sprintf("Recorded %s on session '%s'.\n", strtolower(trim($kind)), $session->id));

        return 0;
    }

    /** @param list<string> $tokens */
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

        $session = $this->store->close($session, $status, $this->stringOption($parsed['options'], 'reason'));
        fwrite($this->out, sprintf(
            "Closed session '%s' as %s%s.\n",
            $session->id,
            $session->status->value,
            $session->closedReason === null ? '' : ' (' . $session->closedReason . ')',
        ));

        return 0;
    }

    /** @param list<string> $tokens */
    private function listCommand(array $tokens): int
    {
        $parsed = $this->parseOptions($tokens);
        $root = $this->resolveRoot($parsed['options']);
        $statusFilter = SessionStatus::tryFromString($this->stringOption($parsed['options'], 'status') ?? '');
        $taskFilter = $this->stringOption($parsed['options'], 'task');

        $sessions = $taskFilter === null || trim($taskFilter) === ''
            ? $this->store->all($root)
            : $this->store->allForTask($root, $taskFilter);
        $shown = 0;
        foreach ($sessions as $session) {
            if ($statusFilter !== null && $session->status !== $statusFilter) {
                continue;
            }
            fwrite($this->out, sprintf(
                "%-40s %-8s task=%s claimed_by=%s%s\n",
                $session->id,
                $session->status->value,
                $session->taskId,
                $session->claimedBy ?? '-',
                $session->closedReason === null ? '' : ' reason=' . $session->closedReason,
            ));
            ++$shown;
        }

        if ($shown === 0) {
            fwrite($this->out, "No sessions found.\n");
        }

        return 0;
    }

    /** @param list<string> $tokens */
    private function showCommand(array $tokens): int
    {
        $parsed = $this->parseOptions($tokens);
        $root = $this->resolveRoot($parsed['options']);
        $session = $this->store->load($root, $this->requireId($parsed['arguments']));

        fwrite($this->out, json_encode($session->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");

        return 0;
    }

    /** @param list<string> $tokens */
    private function handoffCommand(array $tokens): int
    {
        $parsed = $this->parseOptions($tokens);
        $this->assertOnlyOptions($parsed['options'], ['root', 'format']);
        $root = $this->resolveRoot($parsed['options']);
        $session = $this->store->load($root, $this->requireId($parsed['arguments']));

        $format = $this->stringOption($parsed['options'], 'format') ?? 'md';
        if (!in_array($format, ['md', 'json'], true)) {
            throw new \InvalidArgumentException('--format must be md or json.');
        }

        $handoff = (new SessionHandoffProjector($this->validationEvidence))->project($session);
        fwrite($this->out, $format === 'json'
            ? json_encode($handoff->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n"
            : $handoff->toMarkdown());

        return 0;
    }

    /** @param list<string> $tokens */
    private function validationCommand(array $tokens): int
    {
        $action = array_shift($tokens) ?? 'help';
        if (in_array($action, ['help', '--help', '-h'], true)) {
            fwrite($this->out, "Usage: agent-session validation record <id> --contract-revision N --command COMMAND --status passed|failed --exit-code N [--duration-ms N] [--by ACTOR] [--note TEXT] [--implementation-snapshot sha256:DIGEST]\n");

            return 0;
        }
        if ($action !== 'record') {
            throw new \InvalidArgumentException('Unknown validation action: ' . $action);
        }
        $parsed = $this->parseOptions($tokens);
        $session = $this->store->load($this->resolveRoot($parsed['options']), $this->requireId($parsed['arguments']));
        $revision = $this->requiredPositiveInt($this->stringOption($parsed['options'], 'contract-revision'), '--contract-revision');
        $exitCode = $this->requiredNonNegativeInt($this->stringOption($parsed['options'], 'exit-code'), '--exit-code');
        $duration = $this->stringOption($parsed['options'], 'duration-ms');
        $durationMs = $duration === null ? null : $this->requiredNonNegativeInt($duration, '--duration-ms');
        $status = ValidationStatus::tryFrom($this->stringOption($parsed['options'], 'status') ?? '');
        if ($status === null) {
            throw new \InvalidArgumentException('--status must be passed or failed.');
        }
        $evidence = $this->validationEvidence->record(
            $session,
            $revision,
            $this->stringOption($parsed['options'], 'command') ?? '',
            $status,
            $exitCode,
            $durationMs,
            $this->stringOption($parsed['options'], 'by'),
            $this->stringOption($parsed['options'], 'note'),
            $this->stringOption($parsed['options'], 'implementation-snapshot'),
        );
        fwrite($this->out, sprintf(
            "Recorded %s validation evidence for Contract revision %d on session '%s'.\n",
            $evidence->status->value,
            $evidence->contractRevision,
            $session->id,
        ));

        return 0;
    }

    /** @param list<string> $tokens */
    private function pruneCommand(array $tokens): int
    {
        $parsed = $this->parseOptions($tokens);
        $this->assertOnlyOptions($parsed['options'], ['root', 'keep-days', 'status', 'dry-run']);
        $root = $this->resolveRoot($parsed['options']);
        $keepDays = (int) ($this->stringOption($parsed['options'], 'keep-days') ?? '30');
        $dryRun = $this->hasFlag($parsed['options'], 'dry-run');
        $statusValue = $this->stringOption($parsed['options'], 'status');
        $statuses = $statusValue === null
            ? [SessionStatus::DONE, SessionStatus::DROPPED]
            : $this->parseStatuses($statusValue);

        $removed = $this->store->prune($root, $keepDays, $statuses, $dryRun);
        $verb = $dryRun ? 'Would prune' : 'Pruned';
        fwrite($this->out, sprintf("%s %d session(s) older than %d day(s).\n", $verb, count($removed), $keepDays));
        foreach ($removed as $id) {
            fwrite($this->out, '- ' . $id . "\n");
        }

        return 0;
    }

    /** @return list<SessionStatus> */
    private function parseStatuses(string $value): array
    {
        $statuses = [];
        foreach (explode(',', $value) as $part) {
            $status = SessionStatus::tryFromString($part);
            if ($status === null) {
                throw new \InvalidArgumentException('Unknown prune status: ' . $part);
            }
            $statuses[] = $status;
        }

        return $statuses;
    }

    private function helpCommand(): int
    {
        fwrite($this->out, <<<TXT
        agent-session - pruneable working memory for coding-agent Runs.

        Usage:
          agent-session <command> [options]

        Commands:
          start       Start a session.   --task ID [--by ACTOR] [--base-commit SHA] [--slug S] [--ephemeral]
          claim       Claim a session.   <id> --by ACTOR [--base-commit SHA] [--force]
          checkpoint  Add a checkpoint.  <id> --title T [--body TEXT]
          record      Add a record.      <id> --kind decision|assumption --title T [--body TEXT]
          close       Close a session.   <id> --status done|dropped [--reason TEXT]
          list        List sessions.     [--status STATUS] [--task ID]
          show        Show metadata.     <id>
          handoff     Resume packet.     <id> [--format md|json]
          validation  Record run-local validation observation. <record> <id> [options]
          prune       Retention cleanup. [--keep-days N] [--status done,dropped] [--dry-run]

        Durable Contract/approval is owned by agent-loop. Durable Learning close-out is owned by agent-learning.
        Session data is working memory and may be pruned after the governed Run has durable close evidence.

        Global:
          --root PATH   Sessions root directory (default: <cwd>/.agent-loop/sessions).

        TXT);

        return 0;
    }

    private function unknownCommand(string $command): int
    {
        fwrite($this->err, 'Unknown command: ' . $command . "\n");
        fwrite($this->err, "Run 'agent-session help' to view usage.\n");

        return 1;
    }

    /** @param array<string, list<string>> $options */
    private function resolveRoot(array $options): string
    {
        $root = $this->stringOption($options, 'root');
        if ($root !== null && trim($root) !== '') {
            return $root;
        }

        return (getcwd() ?: '.') . '/.agent-loop/sessions';
    }

    /** @param list<string> $arguments */
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
     * @param list<string> $allowed
     */
    private function assertOnlyOptions(array $options, array $allowed): void
    {
        foreach (array_keys($options) as $name) {
            if (!in_array($name, $allowed, true)) {
                throw new \InvalidArgumentException('Unknown option: --' . $name);
            }
        }
    }

    /** @param array<string, list<string>> $options */
    private function stringOption(array $options, string $name): ?string
    {
        return $options[$name][0] ?? null;
    }

    /** @param array<string, list<string>> $options */
    private function hasFlag(array $options, string $name): bool
    {
        return isset($options[$name]);
    }

    private function requiredPositiveInt(?string $value, string $option): int
    {
        if ($value === null || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            throw new \InvalidArgumentException($option . ' requires a positive integer.');
        }

        return (int) $value;
    }

    private function requiredNonNegativeInt(?string $value, string $option): int
    {
        if ($value === null || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 0) {
            throw new \InvalidArgumentException($option . ' requires a non-negative integer.');
        }

        return (int) $value;
    }
}
