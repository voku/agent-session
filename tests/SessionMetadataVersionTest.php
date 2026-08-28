<?php

declare(strict_types=1);

namespace voku\AgentSession\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentSession\SessionStatus;
use voku\AgentSession\SessionStore;
use voku\AgentSession\UnsupportedSessionMetadataVersion;

final class SessionMetadataVersionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-session-schema-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
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

    public function testCurrentSchemaRoundTripsAsVersionOnePointOne(): void
    {
        $store = new SessionStore();
        $created = $store->create($this->root, 'PRE1-SESSION');

        $metadata = $this->metadata($created->path . '/session.json');
        self::assertSame('1.1', $metadata['schema_version'] ?? null);
        self::assertSame($created->id, $store->load($this->root, $created->id)->id);
    }

    public function testVersionOnePointZeroLoadsAsNamedLegacyShape(): void
    {
        $store = new SessionStore();
        $created = $store->create($this->root, 'PRE1-LEGACY', null, 'lars', 'abc123', true);
        $path = $created->path . '/session.json';
        $metadata = $this->metadata($path);
        $metadata['schema_version'] = '1.0';
        unset($metadata['closed_at'], $metadata['closed_reason']);
        $this->writeMetadata($path, $metadata);

        $loaded = $store->load($this->root, $created->id);

        self::assertSame($created->id, $loaded->id);
        self::assertSame('PRE1-LEGACY', $loaded->taskId);
        self::assertSame(SessionStatus::ACTIVE, $loaded->status);
        self::assertTrue($loaded->ephemeral);
        self::assertNull($loaded->closedAt);
        self::assertNull($loaded->closedReason);
    }

    public function testNormalMutationUpgradesVersionOnePointZeroToCurrentSchema(): void
    {
        $store = new SessionStore();
        $created = $store->create($this->root, 'PRE1-UPGRADE');
        $path = $created->path . '/session.json';
        $metadata = $this->metadata($path);
        $metadata['schema_version'] = '1.0';
        unset($metadata['closed_at'], $metadata['closed_reason']);
        $this->writeMetadata($path, $metadata);

        $legacy = $store->load($this->root, $created->id);
        $updated = $store->claim($legacy, 'mara', 'def456');
        $rewritten = $this->metadata($path);

        self::assertSame($created->id, $updated->id);
        self::assertSame('PRE1-UPGRADE', $updated->taskId);
        self::assertSame('1.1', $rewritten['schema_version'] ?? null);
        self::assertArrayHasKey('closed_at', $rewritten);
        self::assertArrayHasKey('closed_reason', $rewritten);
    }

    public function testUnknownSchemaVersionFailsClosed(): void
    {
        $store = new SessionStore();
        $created = $store->create($this->root, 'PRE1-UNKNOWN');
        $path = $created->path . '/session.json';
        $metadata = $this->metadata($path);
        $metadata['schema_version'] = '9.9';
        $this->writeMetadata($path, $metadata);

        $this->expectException(UnsupportedSessionMetadataVersion::class);
        $this->expectExceptionMessage('9.9');
        $store->load($this->root, $created->id);
    }

    public function testMissingSchemaVersionFailsClosed(): void
    {
        $store = new SessionStore();
        $created = $store->create($this->root, 'PRE1-MISSING');
        $path = $created->path . '/session.json';
        $metadata = $this->metadata($path);
        unset($metadata['schema_version']);
        $this->writeMetadata($path, $metadata);

        $this->expectException(UnsupportedSessionMetadataVersion::class);
        $this->expectExceptionMessage('<missing-or-invalid>');
        $store->load($this->root, $created->id);
    }

    /** @return array<string, mixed> */
    private function metadata(string $path): array
    {
        $metadata = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($metadata);

        return $metadata;
    }

    /** @param array<string, mixed> $metadata */
    private function writeMetadata(string $path, array $metadata): void
    {
        file_put_contents(
            $path,
            json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }
}
