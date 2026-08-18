<?php

declare(strict_types=1);

namespace voku\AgentSession\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentSession\Session;
use voku\AgentSession\SessionStore;
use voku\AgentSession\ValidationEvidenceStore;
use voku\AgentSession\ValidationStatus;

/**
 * Evidence is append-only, so presence is not the question - binding is.
 */
final class ValidationEvidenceSelectionTest extends TestCase
{
    private const string SNAPSHOT_A = 'sha256:' . 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';
    private const string SNAPSHOT_B = 'sha256:' . '0f9e8d7c6b5a493827160f9e8d7c6b5a493827160f9e8d7c6b5a493827160f9e';

    private string $root;

    private Session $session;

    private ValidationEvidenceStore $evidence;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-session-selection-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0777, true);
        $this->session = (new SessionStore())->create($this->root, 'TASK-A');
        $this->evidence = new ValidationEvidenceStore();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testLatestObservationForTheExactBindingWins(): void
    {
        $this->record(2, 'composer ci', ValidationStatus::FAILED, 1, self::SNAPSHOT_A);
        $this->record(2, 'composer ci', ValidationStatus::PASSED, 0, self::SNAPSHOT_A);

        $selection = $this->evidence->select($this->session, 2, self::SNAPSHOT_A, ['composer ci']);

        self::assertTrue($selection->isFullyObserved());
        self::assertTrue($selection->isPassing());
        self::assertSame(ValidationStatus::PASSED, $selection->currentFor('composer ci')?->status);
        self::assertSame([], $selection->stale());
        self::assertSame([], $selection->failures());
    }

    public function testAPassRecordedForEarlierImplementationContentIsNotCurrent(): void
    {
        $this->record(2, 'composer ci', ValidationStatus::PASSED, 0, self::SNAPSHOT_A);

        $selection = $this->evidence->select($this->session, 2, self::SNAPSHOT_B, ['composer ci']);

        self::assertFalse($selection->isFullyObserved());
        self::assertNull($selection->currentFor('composer ci'));
        self::assertSame(['composer ci'], $selection->supersededByImplementation);
        self::assertSame([], $selection->missing);
        self::assertSame(['composer ci'], $selection->stale());
    }

    public function testAPassRecordedForAnEarlierContractRevisionIsNotCurrent(): void
    {
        $this->record(1, 'composer ci', ValidationStatus::PASSED, 0, self::SNAPSHOT_A);

        $selection = $this->evidence->select($this->session, 2, self::SNAPSHOT_A, ['composer ci']);

        self::assertFalse($selection->isFullyObserved());
        self::assertSame(['composer ci'], $selection->supersededByRevision);
        self::assertSame([], $selection->supersededByImplementation);
        self::assertSame([], $selection->missing);
    }

    public function testAnUnobservedCommandIsMissingRatherThanSuperseded(): void
    {
        $this->record(2, 'composer ci', ValidationStatus::PASSED, 0, self::SNAPSHOT_A);

        $selection = $this->evidence->select($this->session, 2, self::SNAPSHOT_A, ['composer ci', 'phpunit --group slow']);

        self::assertFalse($selection->isFullyObserved());
        self::assertSame(['phpunit --group slow'], $selection->missing);
        self::assertSame([], $selection->stale());
        self::assertSame(['phpunit --group slow'], $selection->unobserved());
    }

    public function testARecordedFailureIsAFullObservationButNotAPass(): void
    {
        $this->record(2, 'composer ci', ValidationStatus::FAILED, 1, self::SNAPSHOT_A);

        $selection = $this->evidence->select($this->session, 2, self::SNAPSHOT_A, ['composer ci']);

        self::assertTrue($selection->isFullyObserved(), 'a recorded failure is still an observation of the current state');
        self::assertFalse($selection->isPassing());
        self::assertCount(1, $selection->failures());
        self::assertSame(ValidationStatus::FAILED, $selection->failures()[0]->status);
    }

    public function testWithoutASnapshotOnlyTheContractRevisionBinds(): void
    {
        $this->record(2, 'composer ci', ValidationStatus::PASSED, 0, self::SNAPSHOT_A);
        $this->record(1, 'composer ci', ValidationStatus::PASSED, 0, self::SNAPSHOT_B);

        $selection = $this->evidence->select($this->session, 2, null, ['composer ci']);

        self::assertTrue($selection->isFullyObserved());
        self::assertSame(self::SNAPSHOT_A, $selection->currentFor('composer ci')?->implementationSnapshot);
        self::assertNull($selection->implementationSnapshot);
    }

    public function testSnapshotlessEvidenceDoesNotSatisfyASnapshotBoundQuestion(): void
    {
        $this->record(2, 'composer ci', ValidationStatus::PASSED, 0, null);

        $selection = $this->evidence->select($this->session, 2, self::SNAPSHOT_A, ['composer ci']);

        self::assertFalse($selection->isFullyObserved());
        self::assertSame(['composer ci'], $selection->supersededByImplementation);
    }

    public function testRepeatedCommandsAreAccountedForOnce(): void
    {
        $this->record(2, 'composer ci', ValidationStatus::PASSED, 0, self::SNAPSHOT_A);

        $selection = $this->evidence->select($this->session, 2, self::SNAPSHOT_A, ['composer ci', 'composer ci']);

        self::assertCount(1, $selection->current);
        self::assertTrue($selection->isFullyObserved());
    }

    public function testSelectionRejectsUnusableInput(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('positive Contract revision');

        $this->evidence->select($this->session, 0, null, ['composer ci']);
    }

    public function testSelectionRejectsAMalformedSnapshot(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sha256:<64 lowercase hex>');

        $this->evidence->select($this->session, 1, 'not-a-digest', ['composer ci']);
    }

    public function testSelectionRejectsAnEmptyCommand(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('non-empty commands');

        $this->evidence->select($this->session, 1, null, ['  ']);
    }

    public function testNoObligationsSelectsNothingRatherThanFailing(): void
    {
        $selection = $this->evidence->select($this->session, 1, null, []);

        self::assertTrue($selection->isFullyObserved());
        self::assertSame([], $selection->current);
    }

    private function record(
        int $revision,
        string $command,
        ValidationStatus $status,
        int $exitCode,
        ?string $snapshot,
    ): void {
        $this->evidence->record(
            $this->session,
            $revision,
            $command,
            $status,
            $exitCode,
            null,
            'lars',
            null,
            $snapshot,
        );
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            is_dir($child) ? $this->removeDirectory($child) : unlink($child);
        }
        rmdir($path);
    }
}
