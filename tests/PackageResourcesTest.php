<?php

declare(strict_types=1);

namespace voku\AgentSession\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentSession\PackageResources;

final class PackageResourcesTest extends TestCase
{
    public function testPackageResourcesResolvesShippedSkills(): void
    {
        self::assertSame('resources/skills', PackageResources::SKILLS);
        self::assertDirectoryExists(PackageResources::skillsRoot());
    }

    public function testMaintainerSkillIsShipped(): void
    {
        self::assertFileExists(PackageResources::skillsRoot() . '/agent-session-maintainer/SKILL.md');
    }

    /**
     * A host projecting these skills reads `SKILL.md` from every direct child
     * directory, so a shipped entry without one would be projected as an empty
     * skill instead of failing loudly.
     */
    public function testEverySkillDirectoryShipsASkillFile(): void
    {
        $root = PackageResources::skillsRoot();

        $entries = array_values(array_filter(
            (array) scandir($root),
            static fn (mixed $entry): bool => is_string($entry)
                && !str_starts_with($entry, '.')
                && is_dir($root . '/' . $entry),
        ));

        self::assertNotSame([], $entries, 'The package must ship at least one skill.');

        foreach ($entries as $entry) {
            self::assertFileExists($root . '/' . $entry . '/SKILL.md');
        }
    }
}
