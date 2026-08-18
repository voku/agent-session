<?php

declare(strict_types=1);

namespace voku\AgentSession\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentSession\AmbiguousActiveSession;
use voku\AgentSession\SessionStatus;
use voku\AgentSession\SessionStore;

/**
 * A task has at most one open working-memory Session, and a Session that stops
 * being open records why while that pruneable working memory still exists.
 */
final class TaskSessionOwnershipTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-session-ownership-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testActiveForTaskIgnoresOtherTasksAndClosedSessions(): void
    {
        $store = new SessionStore();
        $other = $store->create($this->root, 'TASK-B', 'other');
        $closed = $store->create($this->root, 'TASK-A', 'closed');
        $store->close($closed, SessionStatus::DONE);
        $open = $store->create($this->root, 'TASK-A', 'open');

        self::assertSame($open->id, $store->activeForTask($this->root, 'TASK-A')?->id);
        self::assertSame($other->id, $store->activeForTask($this->root, 'TASK-B')?->id);
        self::assertNull($store->activeForTask($this->root, 'TASK-C'));
        self::assertCount(2, $store->allForTask($this->root, 'TASK-A'));
        self::assertCount(1, $store->openForTask($this->root, 'TASK-A'));
    }

    public function testCreateRejectsASecondOpenSessionForTheSameTask(): void
    {
        $store = new SessionStore();
        $first = $store->create($this->root, 'TASK-A', 'first');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already has open Session ' . $first->id);

        $store->create($this->root, 'TASK-A', 'second');
    }

    public function testRehydrateRejectsParallelOpenWorkingMemoryForTheSameTask(): void
    {
        $store = new SessionStore();
        $first = $store->create($this->root, 'TASK-A', 'first');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already has open Session ' . $first->id);

        $store->rehydrate($this->root, 'historical-task-a', 'TASK-A');
    }

    public function testActiveForTaskReportsEveryOpenSessionWhenLegacyStateIsBroken(): void
    {
        $store = new SessionStore();
        $first = $store->create($this->root, 'TASK-A', 'first');
        $second = $store->create($this->root, 'TASK-B', 'second');
        $second = $store->setStatus($second, SessionStatus::BLOCKED);

        $metadataPath = $second->path . '/session.json';
        $metadata = json_decode((string) file_get_contents($metadataPath), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($metadata);
        $metadata['task_id'] = 'TASK-A';
        file_put_contents($metadataPath, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        try {
            $store->activeForTask($this->root, 'TASK-A');
            self::fail('Expected an ambiguous active Session.');
        } catch (AmbiguousActiveSession $exception) {
            self::assertSame('TASK-A', $exception->taskId);
            self::assertSame([$first->id, $second->id], $exception->sessionIds);
        }

        self::assertCount(2, $store->openForTask($this->root, 'TASK-A'));
    }

    public function testClosingRecordsWhyWorkingMemoryWasRetired(): void
    {
        $store = new SessionStore();
        $session = $store->create($this->root, 'TASK-A');

        $retired = $store->close(
            $session,
            SessionStatus::DROPPED,
            'superseded by approved Contract revision 2',
        );

        self::assertSame(SessionStatus::DROPPED, $retired->status);
        self::assertSame('superseded by approved Contract revision 2', $retired->closedReason);
        self::assertNotNull($retired->closedAt);

        $reloaded = $store->load($this->root, $session->id);
        self::assertSame('superseded by approved Contract revision 2', $reloaded->closedReason);
        self::assertSame($retired->closedAt, $reloaded->closedAt);
    }

    public function testClosingWithoutAReasonStaysUnexplainedRatherThanInventingOne(): void
    {
        $store = new SessionStore();
        $session = $store->create($this->root, 'TASK-A');

        $closed = $store->close($session, SessionStatus::DONE);

        self::assertNull($closed->closedReason);
        self::assertNotNull($closed->closedAt);
    }

    public function testClosedSessionCannotBeReopened(): void
    {
        $store = new SessionStore();
        $session = $store->close($store->create($this->root, 'TASK-A'), SessionStatus::DONE);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is closed as done and cannot be moved to active');

        $store->setStatus($session, SessionStatus::ACTIVE);
    }

    public function testClosedSessionCannotBeRelabelledToAnotherClosedStatus(): void
    {
        $store = new SessionStore();
        $session = $store->close($store->create($this->root, 'TASK-A'), SessionStatus::DONE, 'finished');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot be moved to dropped');

        $store->close($session, SessionStatus::DROPPED, 'superseded');
    }

    public function testRepeatingTheSameCloseIsIdempotent(): void
    {
        $store = new SessionStore();
        $session = $store->close($store->create($this->root, 'TASK-A'), SessionStatus::DONE, 'finished');

        $again = $store->close($session, SessionStatus::DONE, 'finished');
        self::assertSame($session->closedAt, $again->closedAt);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('its recorded reason cannot be rewritten');

        $store->close($session, SessionStatus::DONE, 'a different story');
    }

    public function testCloseRequiresAClosedStatus(): void
    {
        $store = new SessionStore();
        $session = $store->create($this->root, 'TASK-A');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires a closed status, got blocked');

        $store->close($session, SessionStatus::BLOCKED);
    }

    public function testCheckpointsDoNotDropClosureProvenanceWhileSessionStillExists(): void
    {
        $store = new SessionStore();
        $session = $store->create($this->root, 'TASK-A');
        $store->addCheckpoint($session, 'Discovery', 'Looked at the store.');

        $reloaded = $store->load($this->root, $session->id);
        $closed = $store->close($reloaded, SessionStatus::DROPPED, 'superseded');

        $after = $store->addCheckpoint($closed, 'Late note', 'Recorded after retirement.');
        self::assertSame('superseded', $after->closedReason);
        self::assertSame('superseded', $store->load($this->root, $session->id)->closedReason);
    }

    public function testLegacyMetadataWithoutClosureFieldsStillLoads(): void
    {
        $store = new SessionStore();
        $session = $store->create($this->root, 'TASK-A');
        file_put_contents($session->path . '/session.json', json_encode([
            'schema_version' => '1.0',
            'id' => $session->id,
            'task_id' => 'TASK-A',
            'status' => 'dropped',
            'claimed_by' => null,
            'claimed_at' => null,
            'base_commit' => null,
            'created_at' => $session->createdAt,
            'updated_at' => $session->updatedAt,
            'checkpoints' => [],
        ], JSON_THROW_ON_ERROR));

        $reloaded = $store->load($this->root, $session->id);
        self::assertSame(SessionStatus::DROPPED, $reloaded->status);
        self::assertNull($reloaded->closedAt);
        self::assertNull($reloaded->closedReason);
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
