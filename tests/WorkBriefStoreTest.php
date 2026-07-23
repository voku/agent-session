<?php

declare(strict_types=1);

namespace voku\AgentSession\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentSession\Session;
use voku\AgentSession\SessionStore;
use voku\AgentSession\WorkBriefStatus;
use voku\AgentSession\WorkBriefStore;

final class WorkBriefStoreTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-session-brief-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testExistingSessionWithoutBriefRemainsReadable(): void
    {
        $session = (new SessionStore())->create($this->root, 'task.legacy');

        self::assertNull((new WorkBriefStore())->find($session));
    }

    public function testCreateWritesVersionedBriefAndMarkdown(): void
    {
        $session = (new SessionStore())->create($this->root, 'task.123');
        $briefs = new WorkBriefStore();

        $brief = $briefs->create(
            $session,
            'Add bounded context support.',
            ['src/ContextBrief.php', 'tests/ContextBriefTest.php'],
            ['Do not create a new memory layer.'],
            ['vendor/bin/phpunit tests/ContextBriefTest.php'],
        );

        self::assertSame(1, $brief->revision);
        self::assertSame(WorkBriefStatus::CANDIDATE, $brief->status);
        self::assertFileExists($session->path . '/work-brief.json');
        self::assertFileExists($session->path . '/work-brief.md');
        self::assertStringContainsString('## Approved scope', (string) file_get_contents($session->path . '/work-brief.md'));

        $loaded = $briefs->load($session);
        self::assertSame($brief->toArray(), $loaded->toArray());
    }

    public function testCreateRecordsRelevanceTagsIndependentlyOfScopePaths(): void
    {
        $session = (new SessionStore())->create($this->root, 'task.123');
        $briefs = new WorkBriefStore();

        $brief = $briefs->create(
            $session,
            'Sync employees from the directory.',
            ['modules/employee/Sync.php'],
            [],
            ['vendor/bin/phpunit'],
            ['identity', 'ldap', 'identity'],
        );

        self::assertSame(['identity', 'ldap'], $brief->tags);
        self::assertStringContainsString('## Relevance tags', (string) file_get_contents($session->path . '/work-brief.md'));
        self::assertStringContainsString('`ldap`', (string) file_get_contents($session->path . '/work-brief.md'));

        $loaded = $briefs->load($session);
        self::assertSame(['identity', 'ldap'], $loaded->tags);

        $decoded = json_decode((string) file_get_contents($session->path . '/work-brief.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['identity', 'ldap'], $decoded['tags']);
    }

    public function testCreateWithoutTagsDefaultsToEmptyListAndOlderBriefsWithoutTagsStayReadable(): void
    {
        $session = (new SessionStore())->create($this->root, 'task.legacy-brief');
        $briefs = new WorkBriefStore();
        $brief = $briefs->create($session, 'Keep the scope reviewable.', ['src/Scope.php'], [], ['vendor/bin/phpunit']);
        self::assertSame([], $brief->tags);

        // Simulate a work-brief.json written before tags existed.
        $decoded = json_decode((string) file_get_contents($session->path . '/work-brief.json'), true, 512, JSON_THROW_ON_ERROR);
        unset($decoded['tags']);
        file_put_contents($session->path . '/work-brief.json', json_encode($decoded, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $loaded = $briefs->load($session);
        self::assertSame([], $loaded->tags);
    }

    public function testApproveRecordsActorAndBriefRevision(): void
    {
        $session = (new SessionStore())->create($this->root, 'task.123');
        $briefs = new WorkBriefStore();
        $briefs->create($session, 'Make the scope reviewable.', ['src/Scope.php'], [], ['vendor/bin/phpunit']);

        $approval = $briefs->approve($session, 'lars');

        self::assertSame('lars', $approval->approvedBy);
        self::assertSame(1, $approval->workBriefRevision);
        self::assertSame(WorkBriefStatus::APPROVED, $briefs->load($session)->status);
        self::assertSame($approval->toArray(), $briefs->approval($session)?->toArray());
        self::assertFileExists($session->path . '/approval.json');
    }

    public function testRevisionSupersedesApprovalAndPreservesHistory(): void
    {
        $session = (new SessionStore())->create($this->root, 'task.123');
        $briefs = new WorkBriefStore();
        $briefs->create($session, 'Make the scope reviewable.', ['src/Scope.php'], [], ['vendor/bin/phpunit']);
        $briefs->approve($session, 'lars');

        $revised = $briefs->revise(
            $session,
            'Make the scope reviewable.',
            ['src/Scope.php', 'tests/ScopeTest.php'],
            ['Do not create a new memory layer.'],
            ['vendor/bin/phpunit tests/ScopeTest.php'],
        );

        self::assertSame(2, $revised->revision);
        self::assertSame(WorkBriefStatus::CANDIDATE, $revised->status);
        self::assertNull($briefs->approval($session));

        $history = json_decode((string) file_get_contents($session->path . '/work-brief-history/work-brief.001.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('superseded', $history['status']);
        self::assertFileExists($session->path . '/work-brief-history/approval.001.json');
        self::assertStringContainsString('tests/ScopeTest.php', (string) file_get_contents($session->path . '/work-brief.md'));
    }

    public function testCreateRequiresGoalScopeAndValidation(): void
    {
        $session = (new SessionStore())->create($this->root, 'task.123');
        $briefs = new WorkBriefStore();

        $this->expectExceptionMessage('non-empty --goal');
        $briefs->create($session, '', ['src/Scope.php'], [], ['vendor/bin/phpunit']);
    }

    public function testInvalidRevisionDoesNotArchiveOrInvalidateCurrentApproval(): void
    {
        $session = (new SessionStore())->create($this->root, 'task.123');
        $briefs = new WorkBriefStore();
        $briefs->create($session, 'Keep the scope reviewable.', ['src/Scope.php'], [], ['vendor/bin/phpunit']);
        $briefs->approve($session, 'lars');

        try {
            $briefs->revise($session, '', ['src/Scope.php'], [], ['vendor/bin/phpunit']);
            self::fail('Expected invalid goal to reject the revision.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('non-empty --goal', $exception->getMessage());
        }

        self::assertSame(WorkBriefStatus::APPROVED, $briefs->load($session)->status);
        self::assertNotNull($briefs->approval($session));
        self::assertDirectoryDoesNotExist($session->path . '/work-brief-history');
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
