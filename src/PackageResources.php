<?php

declare(strict_types=1);

namespace voku\AgentSession;

/**
 * The single owner of package-shipped resource locations.
 */
final class PackageResources
{
    public const string SKILLS = 'resources/skills';

    public static function skillsRoot(): string
    {
        return dirname(__DIR__) . '/' . self::SKILLS;
    }

    /**
     * @return array<string, string> skill-id => absolute directory path
     */
    public static function consumerSkills(): array
    {
        return [];
    }

    /**
     * @return array<string, string> skill-id => absolute directory path
     */
    public static function maintainerSkills(): array
    {
        return [
            'agent-session-maintainer' => self::skillsRoot() . '/agent-session-maintainer',
        ];
    }

    public static function consumerInstructionFragment(): ?string
    {
        $path = dirname(__DIR__) . '/resources/instructions/consumer.md';

        return is_file($path) ? $path : null;
    }
}
