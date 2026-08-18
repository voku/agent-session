<?php

declare(strict_types=1);

namespace voku\AgentSession;

/**
 * Which recorded observation, if any, describes the implementation state a
 * caller is actually asking about.
 *
 * The evidence file is append-only, so it accumulates observations for earlier
 * Contract revisions and earlier implementation content. "Was this command
 * validated?" is therefore not a question about presence but about binding, and
 * the three ways the answer can be no are different problems:
 *
 * - never recorded  -> the obligation was not met at all;
 * - recorded for an earlier implementation snapshot -> the code moved after it passed;
 * - recorded for an earlier Contract revision -> the obligation itself changed.
 *
 * Collapsing those into one "missing" is what lets a superseded PASS read as
 * current, so they are reported apart.
 */
final readonly class ValidationEvidenceSelection
{
    /**
     * @param array<string, ValidationEvidence|null> $current command => the observation bound to the requested binding
     * @param list<string> $supersededByImplementation commands observed for this revision, but only against other implementation content
     * @param list<string> $supersededByRevision commands observed only against another Contract revision
     * @param list<string> $missing commands with no observation at all
     */
    public function __construct(
        public int $contractRevision,
        public ?string $implementationSnapshot,
        public array $current,
        public array $supersededByImplementation,
        public array $supersededByRevision,
        public array $missing,
    ) {
    }

    public function currentFor(string $command): ?ValidationEvidence
    {
        return $this->current[trim($command)] ?? null;
    }

    /**
     * Commands with no observation bound to the requested Contract revision and
     * implementation snapshot.
     *
     * @return list<string>
     */
    public function unobserved(): array
    {
        return array_values(array_filter(
            array_keys($this->current),
            fn (string $command): bool => $this->current[$command] === null,
        ));
    }

    /**
     * Commands whose only observations describe superseded state.
     *
     * A subset of `unobserved()`, separated because evidence exists and may
     * even have passed - the case a caller must refuse rather than report as
     * never run.
     *
     * @return list<string>
     */
    public function stale(): array
    {
        return array_values(array_unique(array_merge(
            $this->supersededByImplementation,
            $this->supersededByRevision,
        )));
    }

    /**
     * Every obligation has an observation bound to the requested state.
     *
     * Deliberately says nothing about whether those observations passed: an
     * honest recorded failure is a complete answer about the current state.
     */
    public function isFullyObserved(): bool
    {
        return $this->unobserved() === [];
    }

    /**
     * Every obligation is observed against the requested state, and passed.
     *
     * This is the question a close gate asks; `isFullyObserved()` is the
     * question a status projection asks.
     */
    public function isPassing(): bool
    {
        return $this->isFullyObserved() && $this->failures() === [];
    }

    /**
     * Every failing observation among the current ones.
     *
     * @return list<ValidationEvidence>
     */
    public function failures(): array
    {
        return array_values(array_filter(
            $this->current,
            static fn (?ValidationEvidence $evidence): bool => $evidence !== null
                && $evidence->status !== ValidationStatus::PASSED,
        ));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => '1.0',
            'contract_revision' => $this->contractRevision,
            'implementation_snapshot' => $this->implementationSnapshot,
            'fully_observed' => $this->isFullyObserved(),
            'passing' => $this->isPassing(),
            'current' => array_map(
                static fn (?ValidationEvidence $evidence): ?array => $evidence?->toArray(),
                $this->current,
            ),
            'unobserved' => $this->unobserved(),
            'superseded_by_implementation' => $this->supersededByImplementation,
            'superseded_by_revision' => $this->supersededByRevision,
            'missing' => $this->missing,
        ];
    }
}
