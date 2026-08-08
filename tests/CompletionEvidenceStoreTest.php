<?php

declare(strict_types=1);

namespace voku\AgentSession\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentSession\LearningDecision;
use voku\AgentSession\LearningDecisionStore;
use voku\AgentSession\SessionStore;
use voku\AgentSession\ValidationEvidenceStore;
use voku\AgentSession\ValidationStatus;

final class CompletionEvidenceStoreTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-session-evidence-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testValidationEvidenceIsAppendOnlyAndRevisioned(): void
    {
        $session = (new SessionStore())->create($this->root, 'TASK-1');
        $store = new ValidationEvidenceStore();
        $store->record($session, 1, 'vendor/bin/phpunit', ValidationStatus::FAILED, 1, 205, 'lars');
        $store->record($session, 2, 'vendor/bin/phpunit', ValidationStatus::PASSED, 0, 188, 'lars', 'Fixed expected assertion.');

        $evidence = $store->all($session);
        self::assertCount(2, $evidence);
        self::assertSame(2, $evidence[1]->workBriefRevision);
        self::assertSame(ValidationStatus::PASSED, $evidence[1]->status);
        self::assertStringContainsString('work brief revision 2', (string) file_get_contents($session->path . '/validation.md'));
    }

    public function testPassingValidationEvidenceRejectsNonZeroExitCode(): void
    {
        $session = (new SessionStore())->create($this->root, 'TASK-1');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Passing validation evidence requires exit code 0.');

        (new ValidationEvidenceStore())->record(
            $session,
            1,
            'vendor/bin/phpunit',
            ValidationStatus::PASSED,
            1,
        );
    }

    public function testFailedValidationMayStillRecordZeroExitCode(): void
    {
        $session = (new SessionStore())->create($this->root, 'TASK-1');
        $store = new ValidationEvidenceStore();

        $store->record(
            $session,
            1,
            'php semantic-check.php',
            ValidationStatus::FAILED,
            0,
            note: 'The process completed but the semantic assertion failed.',
        );

        $evidence = $store->all($session);
        self::assertCount(1, $evidence);
        self::assertSame(ValidationStatus::FAILED, $evidence[0]->status);
        self::assertSame(0, $evidence[0]->exitCode);
    }

    public function testStoredContradictoryPassingEvidenceIsRejected(): void
    {
        $session = (new SessionStore())->create($this->root, 'TASK-1');
        $record = [
            'schema_version' => '1.0',
            'task_id' => 'TASK-1',
            'work_brief_revision' => 1,
            'command' => 'vendor/bin/phpunit',
            'status' => 'passed',
            'exit_code' => 2,
            'duration_ms' => 100,
            'recorded_by' => 'legacy-agent',
            'note' => null,
            'executed_at' => '2026-08-08T20:00:00+00:00',
        ];
        file_put_contents(
            $session->path . '/validation-evidence.jsonl',
            json_encode($record, JSON_THROW_ON_ERROR) . "\n",
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Passing validation evidence requires exit code 0.');

        (new ValidationEvidenceStore())->all($session);
    }

    public function testLearningDecisionIsExplicitAndReadable(): void
    {
        $session = (new SessionStore())->create($this->root, 'TASK-1');
        $store = new LearningDecisionStore();
        $store->decide($session, LearningDecision::NO_DURABLE_LEARNING, 'lars', 'No reusable fact emerged.');

        $decision = $store->find($session);
        self::assertNotNull($decision);
        self::assertSame(LearningDecision::NO_DURABLE_LEARNING, $decision->decision);
        self::assertSame('No reusable fact emerged.', $decision->reason);
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
