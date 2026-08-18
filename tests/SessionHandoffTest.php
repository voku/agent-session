<?php

declare(strict_types=1);

namespace voku\AgentSession\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentSession\SessionHandoffProjector;
use voku\AgentSession\SessionStatus;
use voku\AgentSession\SessionStore;
use voku\AgentSession\ValidationEvidenceStore;
use voku\AgentSession\ValidationStatus;

/**
 * The handoff packet is what a fresh agent reads instead of re-deriving the
 * previous one's work from the whole working-memory directory.
 */
final class SessionHandoffTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-session-handoff-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testAFreshSessionReportsNothingRecordedRatherThanScaffoldGuidance(): void
    {
        $store = new SessionStore();
        $session = $store->create($this->root, 'TASK-A', 'fresh', 'lars', 'abc123');

        $handoff = (new SessionHandoffProjector())->project($session);

        self::assertSame('TASK-A', $handoff->taskId);
        self::assertTrue($handoff->isResumable());
        self::assertSame('lars', $handoff->claimedBy);
        self::assertSame('abc123', $handoff->baseCommit);
        self::assertNull($handoff->goal);
        self::assertNull($handoff->nextAction);
        self::assertNull($handoff->latestCheckpoint);
        self::assertSame([], $handoff->decisions);
        self::assertSame([], $handoff->assumptions);
        self::assertStringContainsString('*nothing recorded*', $handoff->toMarkdown());
    }

    public function testFilledWorkingMemoryBecomesAResumePacketWithoutInventingAuthority(): void
    {
        $store = new SessionStore();
        $session = $store->create($this->root, 'TASK-A', 'filled', 'lars');

        file_put_contents($session->path . '/plan.md', <<<MD
        # Plan: TASK-A

        ## Goal

        Remove the direct Session access from the report command.

        ## Current checkpoint

        *none yet*

        ## Next action

        Replace the last `all()` call in the report projection.

        ## Constraints

        - No unrelated migration.

        MD);

        $store->appendRecord($session, 'decision', 'Keep the change module-scoped', 'Wider refactors need their own Contract.');
        $store->appendRecord($session, 'assumption', 'Report callers tolerate an empty list', 'Nothing in the repository says otherwise.');
        $session = $store->addCheckpoint($session, 'Implementation', 'Selection moved into the store; the report still needs updating.');

        (new ValidationEvidenceStore())->record($session, 2, 'composer ci', ValidationStatus::FAILED, 1, null, 'lars');

        $handoff = (new SessionHandoffProjector())->project($session);

        self::assertSame('Remove the direct Session access from the report command.', $handoff->goal);
        self::assertSame('Replace the last `all()` call in the report projection.', $handoff->nextAction);
        self::assertSame(['Keep the change module-scoped'], $handoff->decisions);
        self::assertSame(['Report callers tolerate an empty list'], $handoff->assumptions);
        self::assertStringContainsString('the report still needs updating', (string) $handoff->latestCheckpoint);
        self::assertCount(1, $handoff->checkpoints);
        self::assertCount(1, $handoff->recordedFailures());
        self::assertSame('composer ci', $handoff->recordedFailures()[0]->command);

        $markdown = $handoff->toMarkdown();
        self::assertStringContainsString('## Next action', $markdown);
        self::assertStringContainsString('Replace the last `all()` call', $markdown);
        self::assertStringContainsString('## Assumptions', $markdown);
        self::assertStringNotContainsString('Assumptions still unvalidated', $markdown);
        self::assertStringContainsString('## Validation history', $markdown);
        self::assertStringContainsString('`composer ci` - failed (exit 1, Contract revision 2, implementation unbound)', $markdown);
    }

    public function testAuthoredItalicMarkdownIsPreserved(): void
    {
        $store = new SessionStore();
        $session = $store->create($this->root, 'TASK-A', 'italic');
        file_put_contents($session->path . '/plan.md', <<<MD
        # Plan: TASK-A

        ## Goal

        *Run the migration dry-run*

        ## Next action

        *Inspect the generated diff*

        MD);

        $handoff = (new SessionHandoffProjector())->project($session);

        self::assertSame('*Run the migration dry-run*', $handoff->goal);
        self::assertSame('*Inspect the generated diff*', $handoff->nextAction);
    }

    public function testExistingUnreadableWorkingMemoryFailsExplicitly(): void
    {
        $store = new SessionStore();
        $session = $store->create($this->root, 'TASK-A', 'unreadable');
        $path = $session->path . '/plan.md';
        chmod($path, 0000);
        if (is_readable($path)) {
            chmod($path, 0644);
            self::markTestSkipped('Runtime can still read mode-000 files.');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to read Session handoff content');

        (new SessionHandoffProjector())->project($session);
    }

    public function testTheLatestCheckpointIsTheOneCarriedForward(): void
    {
        $store = new SessionStore();
        $session = $store->create($this->root, 'TASK-A');
        $session = $store->addCheckpoint($session, 'Discovery', 'Read the store.');
        $session = $store->addCheckpoint($session, 'Implementation', 'Wrote the selector.');

        $handoff = (new SessionHandoffProjector())->project($session);

        self::assertSame('Wrote the selector.', $handoff->latestCheckpoint);
        self::assertCount(2, $handoff->checkpoints);
    }

    public function testARetiredSessionSaysWhyItCannotBeResumed(): void
    {
        $store = new SessionStore();
        $session = $store->create($this->root, 'TASK-A');
        $retired = $store->close($session, SessionStatus::DROPPED, 'superseded by approved Contract revision 2');

        $handoff = (new SessionHandoffProjector())->project($retired);

        self::assertFalse($handoff->isResumable());
        self::assertSame(SessionStatus::DROPPED, $handoff->status);
        self::assertStringContainsString(
            'not resumable: superseded by approved Contract revision 2',
            $handoff->toMarkdown(),
        );
    }

    public function testAgentRewrittenPlanWithoutTheExpectedHeadingsIsNotGuessedAt(): void
    {
        $store = new SessionStore();
        $session = $store->create($this->root, 'TASK-A');
        file_put_contents($session->path . '/plan.md', "# Plan\n\nSome free prose the agent wrote instead.\n");

        $handoff = (new SessionHandoffProjector())->project($session);

        self::assertNull($handoff->goal);
        self::assertNull($handoff->nextAction);
    }

    public function testAMissingWorkingMemoryFileDoesNotBreakTheProjection(): void
    {
        $store = new SessionStore();
        $session = $store->create($this->root, 'TASK-A');
        unlink($session->path . '/plan.md');
        unlink($session->path . '/decisions.md');

        $handoff = (new SessionHandoffProjector())->project($session);

        self::assertNull($handoff->goal);
        self::assertSame([], $handoff->decisions);
    }

    public function testTheJsonPacketCarriesTheSameFacts(): void
    {
        $store = new SessionStore();
        $session = $store->create($this->root, 'TASK-A', 'json', 'lars');
        $session = $store->addCheckpoint($session, 'Implementation', 'Half done.');

        $packet = (new SessionHandoffProjector())->project($session)->toArray();

        self::assertSame('1.0', $packet['schema_version']);
        self::assertSame('TASK-A', $packet['task_id']);
        self::assertTrue($packet['resumable']);
        self::assertSame('Half done.', $packet['latest_checkpoint']);
        self::assertSame([], $packet['validation']);
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
