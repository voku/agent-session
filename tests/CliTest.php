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

    /**
     * @param list<string> $argv
     */
    private function invoke(array $argv): int
    {
        // CLI writes to STDOUT/STDERR (process streams); assertions below use
        // exit codes and the on-disk session state, not captured text.
        return (new Cli())->run(['agent-session', ...$argv]);
    }

    private function firstSessionId(): string
    {
        return basename((string) (glob($this->root . '/*', GLOB_ONLYDIR)[0] ?? ''));
    }

    public function testStartCreatesSession(): void
    {
        self::assertSame(0, $this->invoke(['start', '--task', 'task.x', '--root', $this->root, '--by', 'lars']));

        $matches = glob($this->root . '/*/session.json');
        self::assertNotEmpty($matches);
    }

    public function testStartRequiresTask(): void
    {
        self::assertSame(1, $this->invoke(['start', '--root', $this->root]));
    }

    public function testHelpReturnsZero(): void
    {
        self::assertSame(0, $this->invoke(['help']));
    }

    public function testUnknownCommandReturnsOne(): void
    {
        self::assertSame(1, $this->invoke(['frobnicate']));
    }

    public function testClaimRefusesForeignActiveSessionWithoutForce(): void
    {
        self::assertSame(0, $this->invoke(['start', '--task', 'task.x', '--slug', 'shared', '--by', 'lars', '--root', $this->root]));
        $id = $this->firstSessionId();
        $store = new SessionStore();

        self::assertSame(1, $this->invoke(['claim', $id, '--by', 'mara', '--root', $this->root]));
        self::assertSame('lars', $store->load($this->root, $id)->claimedBy);

        self::assertSame(0, $this->invoke(['claim', $id, '--by', 'mara', '--force', '--root', $this->root]));
        self::assertSame('mara', $store->load($this->root, $id)->claimedBy);
    }

    public function testCheckpointRecordCloseAndListFlow(): void
    {
        self::assertSame(0, $this->invoke(['start', '--task', 'task.x', '--slug', 'flow', '--root', $this->root]));
        $id = $this->firstSessionId();
        $store = new SessionStore();

        self::assertSame(0, $this->invoke(['checkpoint', $id, '--title', 'Discovery', '--root', $this->root]));
        self::assertCount(1, $store->load($this->root, $id)->checkpoints);

        self::assertSame(0, $this->invoke(['record', $id, '--kind', 'decision', '--title', 'Scoped', '--root', $this->root]));
        self::assertStringContainsString('Decision: Scoped', (string) file_get_contents($store->pathFor($this->root, $id) . '/decisions.md'));

        self::assertSame(0, $this->invoke(['close', $id, '--status', 'done', '--root', $this->root]));
        self::assertSame(SessionStatus::DONE, $store->load($this->root, $id)->status);

        self::assertSame(0, $this->invoke(['list', '--status', 'done', '--root', $this->root]));
    }

    public function testCloseRequiresClosedStatus(): void
    {
        self::assertSame(0, $this->invoke(['start', '--task', 'task.x', '--slug', 'badclose', '--root', $this->root]));
        $id = $this->firstSessionId();

        self::assertSame(1, $this->invoke(['close', $id, '--status', 'active', '--root', $this->root]));
    }

    public function testBriefCreateApproveReviseAndShowFlow(): void
    {
        self::assertSame(0, $this->invoke(['start', '--task', 'task.x', '--slug', 'brief', '--root', $this->root]));
        $id = $this->firstSessionId();
        $store = new SessionStore();

        self::assertSame(0, $this->invoke([
            'brief', 'create', $id,
            '--goal', 'Make task scope explicit.',
            '--scope', 'src/Scope.php',
            '--validation', 'vendor/bin/phpunit tests/ScopeTest.php',
            '--root', $this->root,
        ]));
        self::assertFileExists($store->pathFor($this->root, $id) . '/work-brief.json');

        self::assertSame(0, $this->invoke(['brief', 'approve', $id, '--by', 'lars', '--root', $this->root]));
        self::assertFileExists($store->pathFor($this->root, $id) . '/approval.json');

        self::assertSame(0, $this->invoke([
            'brief', 'revise', $id,
            '--goal', 'Make task scope explicit.',
            '--scope', 'src/Scope.php',
            '--scope', 'tests/ScopeTest.php',
            '--validation', 'vendor/bin/phpunit tests/ScopeTest.php',
            '--root', $this->root,
        ]));
        self::assertFileDoesNotExist($store->pathFor($this->root, $id) . '/approval.json');
        self::assertSame(0, $this->invoke(['brief', 'show', $id, '--root', $this->root]));
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
