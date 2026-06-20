<?php

declare(strict_types=1);

namespace voku\AgentSession\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentSession\Session;
use voku\AgentSession\SessionStatus;
use voku\AgentSession\SessionStore;

final class SessionStoreTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-session-test-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testCreateScaffoldsFilesAndMetadata(): void
    {
        $store = new SessionStore();
        $session = $store->create($this->root, 'task.002.remove-session-access', null, 'lars', 'abc123');

        self::assertStringContainsString(date('Y-m-d'), $session->id);
        self::assertSame(SessionStatus::ACTIVE, $session->status);
        self::assertSame('lars', $session->claimedBy);
        self::assertSame('abc123', $session->baseCommit);

        foreach (['session.json', 'plan.md', 'assumptions.md', 'decisions.md', 'validation.md', 'checkpoints/index.md'] as $file) {
            self::assertFileExists($session->path . '/' . $file);
        }
        self::assertStringContainsString('task.002.remove-session-access', (string) file_get_contents($session->path . '/plan.md'));
    }

    public function testLoadRoundTrip(): void
    {
        $store = new SessionStore();
        $created = $store->create($this->root, 'task.x');
        $loaded = $store->load($this->root, $created->id);

        self::assertSame($created->id, $loaded->id);
        self::assertSame('task.x', $loaded->taskId);
        self::assertNull($loaded->claimedBy);
    }

    public function testGeneratesUniqueIdsForSameTaskAndDay(): void
    {
        $store = new SessionStore();
        $a = $store->create($this->root, 'task.dup', 'dup');
        $b = $store->create($this->root, 'task.dup', 'dup');

        self::assertNotSame($a->id, $b->id);
    }

    public function testAddCheckpointIncrements(): void
    {
        $store = new SessionStore();
        $session = $store->create($this->root, 'task.x');

        $session = $store->addCheckpoint($session, 'Discovery', 'Found the boundary.');
        $session = $store->addCheckpoint($session, 'Implementation', 'Updated the service.');

        self::assertCount(2, $session->checkpoints);
        self::assertSame('001', $session->checkpoints[0]['id']);
        self::assertSame('002', $session->checkpoints[1]['id']);
        self::assertFileExists($session->path . '/checkpoints/001-discovery.md');
        self::assertStringContainsString('002 Implementation', (string) file_get_contents($session->path . '/checkpoints/index.md'));

        // persisted
        self::assertCount(2, $store->load($this->root, $session->id)->checkpoints);
    }

    public function testAppendRecordWritesToCorrectFile(): void
    {
        $store = new SessionStore();
        $session = $store->create($this->root, 'task.x');

        $store->appendRecord($session, 'decision', 'Keep it scoped', 'Only the affected path.');
        $store->appendRecord($session, 'assumption', 'Missing behaviour', 'Treat as exceptional.');

        self::assertStringContainsString('Decision: Keep it scoped', (string) file_get_contents($session->path . '/decisions.md'));
        self::assertStringContainsString('Assumption: Missing behaviour', (string) file_get_contents($session->path . '/assumptions.md'));
    }

    public function testAppendRecordRejectsUnknownKind(): void
    {
        $store = new SessionStore();
        $session = $store->create($this->root, 'task.x');

        $this->expectExceptionMessage('Unknown record kind');
        $store->appendRecord($session, 'opinion', 'Nope', '');
    }

    public function testSetStatusClose(): void
    {
        $store = new SessionStore();
        $session = $store->create($this->root, 'task.x');
        $session = $store->setStatus($session, SessionStatus::DONE);

        self::assertSame(SessionStatus::DONE, $store->load($this->root, $session->id)->status);
    }

    public function testClaimUpdatesMetadata(): void
    {
        $store = new SessionStore();
        $session = $store->create($this->root, 'task.x');
        $session = $store->claim($session, 'mara', 'def456');

        $loaded = $store->load($this->root, $session->id);
        self::assertSame('mara', $loaded->claimedBy);
        self::assertSame('def456', $loaded->baseCommit);
        self::assertNotNull($loaded->claimedAt);
    }

    public function testPruneRemovesOldClosedSessionsOnly(): void
    {
        $store = new SessionStore();
        $old = $store->create($this->root, 'task.old', 'old');
        $store->setStatus($old, SessionStatus::DONE);
        $this->backdate($old, '2000-01-01T00:00:00+00:00');

        $recent = $store->create($this->root, 'task.recent', 'recent');
        $store->setStatus($recent, SessionStatus::DONE);

        $active = $store->create($this->root, 'task.active', 'active');
        $this->backdate($active, '2000-01-01T00:00:00+00:00'); // old but still active

        $removed = $store->prune($this->root, 30, [SessionStatus::DONE, SessionStatus::DROPPED]);

        self::assertSame([$old->id], $removed);
        self::assertFalse($store->exists($this->root, $old->id));
        self::assertTrue($store->exists($this->root, $recent->id));
        self::assertTrue($store->exists($this->root, $active->id)); // active is never pruned
    }

    public function testPruneDryRunRemovesNothing(): void
    {
        $store = new SessionStore();
        $old = $store->create($this->root, 'task.old', 'old');
        $store->setStatus($old, SessionStatus::DROPPED);
        $this->backdate($old, '2000-01-01T00:00:00+00:00');

        $removed = $store->prune($this->root, 30, [SessionStatus::DONE, SessionStatus::DROPPED], true);

        self::assertSame([$old->id], $removed);
        self::assertTrue($store->exists($this->root, $old->id));
    }

    private function backdate(Session $session, string $iso): void
    {
        $metaPath = $session->path . '/session.json';
        $data = json_decode((string) file_get_contents($metaPath), true);
        self::assertIsArray($data);
        $data['updated_at'] = $iso;
        file_put_contents($metaPath, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
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
            $full = $path . '/' . $entry;
            is_dir($full) ? $this->removeDirectory($full) : unlink($full);
        }
        rmdir($path);
    }
}
