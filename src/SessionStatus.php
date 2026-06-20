<?php

declare(strict_types=1);

namespace voku\AgentSession;

/**
 * Lifecycle of a working-memory session. Deliberately small.
 */
enum SessionStatus: string
{
    case ACTIVE = 'active';
    case BLOCKED = 'blocked';
    case DONE = 'done';
    case DROPPED = 'dropped';

    public function isClosed(): bool
    {
        return $this === self::DONE || $this === self::DROPPED;
    }

    public static function tryFromString(string $value): ?self
    {
        return self::tryFrom(strtolower(trim($value)));
    }
}
