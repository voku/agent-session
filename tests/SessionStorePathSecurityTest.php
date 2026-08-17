<?php

declare(strict_types=1);

namespace voku\AgentSession\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentSession\SessionStatus;
use voku\AgentSession\SessionStore;

final class SessionStorePathSecurityTest extends TestCase
{
    private string $container;

    private string $root;

    protected function setUp(): void
    {
        $this->container = sys_get_temp_dir() . '/agent-session-path-security-' . bin2hex(random_bytes(8));
        $this->root = $this->container . '/sessions';
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removePath($this->container);
    }

    public function testLoadRejectsIdentityThatEscapesSessionRoot(): void
    {
        $store = new SessionStore();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('path-safe');

        $store->load($this->root, '../outside');
    }

    public function testExistsRejectsIdentityThatEscapesSessionRoot(): void
    {
        $store = new SessionStore();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('path-safe');

        $store->exists($this->root, '../outside');
    }

    public function testPathForRejectsIdentityThatEscapesSessionRoot(): void
    {
        $store = new SessionStore();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('path-safe');

        $store->pathFor($this->root, '../outside');
    }

    public function testPruneNeverTraversesSymlinkedSessionDirectory(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Symlink containment regression is exercised on Unix-like CI.');
        }

        $outside = $this->container . '/outside-session';
        mkdir($outside, 0o775, true);
        file_put_contents($outside . '/marker.txt', "must-survive\n");
        file_put_contents(
            $outside . '/session.json',
            json_encode(
                [
                    'id' => 'linked-session',
                    'task_id' => 'task.security',
                    'status' => 'done',
                    'updated_at' => '2000-01-01T00:00:00+00:00',
                ],
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
            ),
        );
        self::assertTrue(symlink($outside, $this->root . '/linked-session'));

        $removed = (new SessionStore())->prune($this->root, 30, [SessionStatus::DONE]);

        self::assertSame([], $removed);
        self::assertFileExists($outside . '/marker.txt');
        self::assertFileExists($outside . '/session.json');
    }

    public function testRegularSessionIdentityStillResolves(): void
    {
        $store = new SessionStore();
        $session = $store->rehydrate($this->root, 'safe-session-1', 'task.security');

        self::assertSame($session->path, $store->pathFor($this->root, $session->id));
        self::assertTrue($store->exists($this->root, $session->id));
        self::assertSame($session->id, $store->load($this->root, $session->id)->id);
    }

    private function removePath(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removePath($path . '/' . $entry);
        }
        rmdir($path);
    }
}
