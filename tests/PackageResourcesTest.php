<?php

declare(strict_types=1);

namespace voku\AgentSession\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentSession\PackageResources;

/** @internal */
final class PackageResourcesTest extends TestCase
{
    public function testSkillsRootExists(): void
    {
        self::assertDirectoryExists(PackageResources::skillsRoot());
    }

    public function testConsumerSkillsIsEmpty(): void
    {
        self::assertSame([], PackageResources::consumerSkills());
    }

    public function testMaintainerSkillsExistAndHaveSkillMd(): void
    {
        $skills = PackageResources::maintainerSkills();
        self::assertArrayHasKey('agent-session-maintainer', $skills);

        foreach ($skills as $id => $path) {
            self::assertDirectoryExists($path, "Skill directory for {$id} must exist.");
            self::assertFileExists($path . '/SKILL.md', "SKILL.md for {$id} must exist.");
        }
    }

    public function testConsumerInstructionFragmentReturnsNull(): void
    {
        self::assertNull(PackageResources::consumerInstructionFragment());
    }
}
