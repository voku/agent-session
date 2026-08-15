<?php

declare(strict_types=1);

namespace voku\AgentSession\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentSession\SessionStore;
use voku\AgentSession\ValidationEvidenceStore;
use voku\AgentSession\ValidationStatus;

final class ImplementationSnapshotValidationEvidenceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-session-implementation-snapshot-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testValidationEvidenceCarriesTheImplementationSnapshotItObserved(): void
    {
        $session = (new SessionStore())->create($this->root, 'LOOP-132');
        $snapshot = 'sha256:' . str_repeat('a', 64);
        $store = new ValidationEvidenceStore();

        $recorded = $store->record(
            $session,
            3,
            'vendor/bin/phpunit',
            ValidationStatus::PASSED,
            0,
            recordedBy: 'dogfood',
            implementationSnapshot: $snapshot,
        );

        self::assertSame($snapshot, $recorded->implementationSnapshot);
        self::assertSame($snapshot, $store->all($session)[0]->implementationSnapshot);
    }
}
