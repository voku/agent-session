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
}
