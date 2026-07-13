<?php

declare(strict_types=1);

namespace voku\AgentSession\Tests;

use PHPUnit\Framework\TestCase;
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
