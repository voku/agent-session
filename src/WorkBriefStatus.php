<?php

declare(strict_types=1);

namespace voku\AgentSession;

enum WorkBriefStatus: string
{
    case CANDIDATE = 'candidate';
    case APPROVED = 'approved';
    case SUPERSEDED = 'superseded';

    public static function tryFromString(string $value): ?self
    {
        return self::tryFrom(strtolower(trim($value)));
    }
}
