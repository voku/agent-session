<?php

declare(strict_types=1);

namespace voku\AgentSession\Tests;

use PHPUnit\Framework\TestCase;

final class ComposerMetadataTest extends TestCase
{
    public function testMainBranchAliasMatchesTheCurrentChangelogReleaseLine(): void
    {
        $root = dirname(__DIR__);
        $composerJson = file_get_contents($root . '/composer.json');
        $changelog = file_get_contents($root . '/CHANGELOG.md');

        self::assertIsString($composerJson);
        self::assertIsString($changelog);

        $composer = json_decode($composerJson, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);

        $matched = preg_match('/^##\s+\[?(\d+)\.(\d+)\.\d+\]?\b/m', $changelog, $release);
        self::assertSame(1, $matched, 'CHANGELOG.md must expose a semantic version heading.');

        $expectedAlias = sprintf('%d.%d.x-dev', (int) $release[1], (int) $release[2]);
        $actualAlias = $composer['extra']['branch-alias']['dev-main'] ?? null;

        self::assertSame($expectedAlias, $actualAlias);
    }
}
