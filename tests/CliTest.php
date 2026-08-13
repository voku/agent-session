<?php

declare(strict_types=1);

namespace voku\AgentSession\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentSession\Cli;
use voku\AgentSession\SessionStatus;
use voku\AgentSession\SessionStore;

final class CliTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-session-cli-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testWorkingMemoryLifecycleStillWorks(): void
    {
        self::assertSame(0, $this->invoke(['start', '--task', 'TASK-1', '--slug', 'flow', '--by', 'lars', '--root', $this->root]));
        $id = $this->firstSessionId();
        $store = new SessionStore();

        self::assertSame(0, $this->invoke(['checkpoint', $id, '--title', 'Discovery', '--root', $this->root]));
        self::assertSame(0, $this->invoke(['record', $id, '--kind', 'decision', '--title', 'Keep scope narrow', '--root', $this->root]));
        self::assertSame(0, $this->invoke(['claim', $id, '--by', 'mara', '--force', '--root', $this->root]));
        self::assertSame('mara', $store->load($this->root, $id)->claimedBy);
        self::assertSame(0, $this->invoke(['close', $id, '--status', 'done', '--root', $this->root]));
        self::assertSame(SessionStatus::DONE, $store->load($this->root, $id)->status);
    }

    public function testPublicCliDefaultsToCompactSessionRoot(): void
    {
        $projectRoot = $this->root . '/project';
        mkdir($projectRoot, 0777, true);
        $previousCwd = getcwd();
        self::assertIsString($previousCwd);
        self::assertTrue(chdir($projectRoot));

        try {
            self::assertSame(0, $this->invoke(['start', '--task', 'task.default', '--by', 'lars']));
            self::assertNotEmpty(glob($projectRoot . '/.agent-loop/sessions/*/session.json'));
            self::assertDirectoryDoesNotExist($projectRoot . '/session_plan');
        } finally {
            self::assertTrue(chdir($previousCwd));
        }
    }

    public function testDurableAuthorityCommandsAreNotCompatibilitySurfaces(): void
    {
        self::assertSame(1, $this->invoke(['brief', 'help']));
        self::assertSame(1, $this->invoke(['learning', 'help']));
    }

    public function testValidationObservationUsesContractRevision(): void
    {
        self::assertSame(0, $this->invoke(['start', '--task', 'TASK-1', '--slug', 'validation', '--root', $this->root]));
        $id = $this->firstSessionId();

        self::assertSame(0, $this->invoke([
            'validation', 'record', $id,
            '--contract-revision', '3',
            '--command', 'vendor/bin/phpunit',
            '--status', 'passed',
            '--exit-code', '0',
            '--by', 'lars',
            '--root', $this->root,
        ]));

        $path = (new SessionStore())->pathFor($this->root, $id) . '/validation-evidence.jsonl';
        $record = json_decode(trim((string) file_get_contents($path)), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('2.0', $record['schema_version']);
        self::assertSame(3, $record['contract_revision']);
        self::assertArrayNotHasKey('work_brief_revision', $record);
    }

    public function testOldBriefRevisionOptionIsRejectedRatherThanGuessed(): void
    {
        self::assertSame(0, $this->invoke(['start', '--task', 'TASK-1', '--slug', 'legacy-validation', '--root', $this->root]));
        $id = $this->firstSessionId();

        self::assertSame(1, $this->invoke([
            'validation', 'record', $id,
            '--brief-revision', '1',
            '--command', 'vendor/bin/phpunit',
            '--status', 'passed',
            '--exit-code', '0',
            '--root', $this->root,
        ]));
    }

    public function testEphemeralFlagSurvivesWorkingMemoryReload(): void
    {
        self::assertSame(0, $this->invoke(['start', '--task', 'EXP-1', '--root', $this->root, '--ephemeral']));
        $store = new SessionStore();
        $session = $store->load($this->root, $this->firstSessionId());
        self::assertTrue($session->ephemeral);
        self::assertTrue($store->setStatus($session, SessionStatus::DROPPED)->ephemeral);
    }

    public function testPruneRejectsUnknownOptionsAndStatusesInsteadOfChangingScope(): void
    {
        self::assertSame(1, $this->invoke([
            'prune', '--root', $this->root, '--totally-bogus-option', 'xyz', '--dry-run',
        ]));
        self::assertSame(1, $this->invoke([
            'prune', '--root', $this->root, '--status', 'activ', '--dry-run',
        ]));
        self::assertSame(1, $this->invoke([
            'prune', '--root', $this->root, '--status', 'done,activ', '--dry-run',
        ]));
    }

    /** @param list<string> $args */
    private function invoke(array $args): int
    {
        return (new Cli())->run(['agent-session', ...$args]);
    }

    private function firstSessionId(): string
    {
        return basename((string) (glob($this->root . '/*', GLOB_ONLYDIR)[0] ?? ''));
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
