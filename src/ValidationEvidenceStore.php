<?php

declare(strict_types=1);

namespace voku\AgentSession;

use DateTimeImmutable;
use DateTimeInterface;
use JsonException;
use RuntimeException;

final class ValidationEvidenceStore
{
    private const string FILE = 'validation-evidence.jsonl';

    public function record(
        Session $session,
        int $contractRevision,
        string $command,
        ValidationStatus $status,
        int $exitCode,
        ?int $durationMs = null,
        ?string $recordedBy = null,
        ?string $note = null,
        ?string $implementationSnapshot = null,
    ): ValidationEvidence {
        $command = trim($command);
        if ($contractRevision < 1) {
            throw new RuntimeException('Validation evidence requires a positive --contract-revision.');
        }
        if ($command === '') {
            throw new RuntimeException('Validation evidence requires a non-empty --command.');
        }
        if ($exitCode < 0) {
            throw new RuntimeException('Validation evidence requires a non-negative --exit-code.');
        }
        $this->assertPassingExitCode($status, $exitCode);
        if ($durationMs !== null && $durationMs < 0) {
            throw new RuntimeException('--duration-ms must be non-negative.');
        }
        $implementationSnapshot = $this->snapshot($implementationSnapshot);

        $evidence = new ValidationEvidence(
            $session->taskId,
            $contractRevision,
            $command,
            $status,
            $exitCode,
            $durationMs,
            $this->nullable($recordedBy),
            $this->nullable($note),
            (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
            $implementationSnapshot,
        );
        $encoded = json_encode($evidence->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($this->path($session), $encoded . "\n", FILE_APPEND) === false) {
            throw new RuntimeException('Unable to append validation evidence.');
        }

        $line = sprintf(
            "\n## Validation evidence (Contract revision %d)\n\n- Command: `%s`\n- Status: %s\n- Exit: %d\n- Executed: %s\n",
            $evidence->contractRevision,
            $evidence->command,
            $evidence->status->value,
            $evidence->exitCode,
            $evidence->executedAt,
        );
        if ($evidence->implementationSnapshot !== null) {
            $line .= '- Implementation snapshot: ' . $evidence->implementationSnapshot . "\n";
        }
        if ($evidence->durationMs !== null) {
            $line .= '- Duration: ' . $evidence->durationMs . "ms\n";
        }
        if ($evidence->note !== null) {
            $line .= '- Note: ' . $evidence->note . "\n";
        }
        if (file_put_contents($session->path . '/validation.md', $line, FILE_APPEND) === false) {
            throw new RuntimeException('Unable to append validation summary.');
        }

        return $evidence;
    }

    /** @return list<ValidationEvidence> */
    public function all(Session $session): array
    {
        $path = $this->path($session);
        if (!is_file($path)) {
            return [];
        }

        $evidence = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            try {
                $data = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('Invalid validation evidence JSON: ' . $exception->getMessage());
            }
            if (!is_array($data) || ($data['schema_version'] ?? null) !== '2.0' || ($data['task_id'] ?? null) !== $session->taskId) {
                throw new RuntimeException('Invalid validation evidence record.');
            }
            $status = is_string($data['status'] ?? null) ? ValidationStatus::tryFrom($data['status']) : null;
            if (
                $status === null
                || !is_int($data['contract_revision'] ?? null)
                || $data['contract_revision'] < 1
                || !is_string($data['command'] ?? null)
                || trim($data['command']) === ''
                || !is_int($data['exit_code'] ?? null)
                || $data['exit_code'] < 0
                || !is_string($data['executed_at'] ?? null)
            ) {
                throw new RuntimeException('Invalid validation evidence record.');
            }
            $this->assertPassingExitCode($status, $data['exit_code']);
            $duration = $data['duration_ms'] ?? null;
            if ($duration !== null && (!is_int($duration) || $duration < 0)) {
                throw new RuntimeException('Invalid validation evidence duration.');
            }
            $evidence[] = new ValidationEvidence(
                $session->taskId,
                $data['contract_revision'],
                trim($data['command']),
                $status,
                $data['exit_code'],
                $duration,
                $this->nullableValue($data['recorded_by'] ?? null),
                $this->nullableValue($data['note'] ?? null),
                $data['executed_at'],
                $this->snapshotValue($data['implementation_snapshot'] ?? null),
            );
        }

        return $evidence;
    }

    /**
     * Select the observation that describes one exact implementation state.
     *
     * The evidence file is append-only and outlives both the Contract revision
     * and the implementation content an observation was recorded against, so
     * every caller that asks "is this command validated?" has to re-apply the
     * same binding rule. Applying it here keeps one answer for one storage
     * layout, and keeps "superseded" distinguishable from "never recorded".
     *
     * The latest matching observation wins: re-running a command after fixing
     * it is the normal way an obligation gets met.
     *
     * Exact-state selection deliberately requires an implementation snapshot.
     * A caller that cannot compute current implementation identity cannot safely
     * turn snapshot-bound evidence into a current PASS merely because the
     * Contract revision still matches.
     *
     * @param list<string> $commands the obligations the caller must account for
     */
    public function select(
        Session $session,
        int $contractRevision,
        string $implementationSnapshot,
        array $commands,
    ): ValidationEvidenceSelection {
        if ($contractRevision < 1) {
            throw new RuntimeException('Selecting validation evidence requires a positive Contract revision.');
        }
        $implementationSnapshot = trim($implementationSnapshot);
        if (preg_match('/^sha256:[a-f0-9]{64}$/', $implementationSnapshot) !== 1) {
            throw new RuntimeException('Selecting validation evidence requires an implementation snapshot sha256:<64 lowercase hex> digest.');
        }

        $all = $this->all($session);
        $current = [];
        $supersededByImplementation = [];
        $supersededByRevision = [];
        $missing = [];

        foreach ($commands as $rawCommand) {
            $command = trim($rawCommand);
            if ($command === '') {
                throw new RuntimeException('Selecting validation evidence requires non-empty commands.');
            }
            if (array_key_exists($command, $current)) {
                continue;
            }

            $forCommand = array_values(array_filter(
                $all,
                static fn (ValidationEvidence $evidence): bool => $evidence->command === $command,
            ));
            $forRevision = array_values(array_filter(
                $forCommand,
                static fn (ValidationEvidence $evidence): bool => $evidence->contractRevision === $contractRevision,
            ));
            $bound = array_values(array_filter(
                $forRevision,
                static fn (ValidationEvidence $evidence): bool => $evidence->implementationSnapshot === $implementationSnapshot,
            ));

            $current[$command] = $bound === [] ? null : $bound[count($bound) - 1];
            if ($bound !== []) {
                continue;
            }
            if ($forRevision !== []) {
                $supersededByImplementation[] = $command;
            } elseif ($forCommand !== []) {
                $supersededByRevision[] = $command;
            } else {
                $missing[] = $command;
            }
        }

        return new ValidationEvidenceSelection(
            $contractRevision,
            $implementationSnapshot,
            $current,
            $supersededByImplementation,
            $supersededByRevision,
            $missing,
        );
    }

    private function assertPassingExitCode(ValidationStatus $status, int $exitCode): void
    {
        if ($status === ValidationStatus::PASSED && $exitCode !== 0) {
            throw new RuntimeException('Passing validation evidence requires exit code 0.');
        }
    }

    private function path(Session $session): string
    {
        return $session->path . '/' . self::FILE;
    }

    private function nullable(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }

    private function nullableValue(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function snapshot(?string $value): ?string
    {
        $value = $this->nullable($value);
        if ($value !== null && preg_match('/^sha256:[a-f0-9]{64}$/', $value) !== 1) {
            throw new RuntimeException('implementation_snapshot must be a sha256:<64 lowercase hex> digest.');
        }

        return $value;
    }

    private function snapshotValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new RuntimeException('Invalid validation evidence implementation_snapshot.');
        }

        return $this->snapshot($value);
    }
}
