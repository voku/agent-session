<?php

declare(strict_types=1);

namespace voku\AgentSession;

use RuntimeException;

/**
 * More than one open governed Session exists for a single task.
 *
 * A task has at most one open governed working-memory Session; experiments are
 * outside that rule. Callers that must pick exactly one Session cannot proceed
 * here, and callers that only report state need the offending ids rather than a
 * rendered message, so both are carried.
 */
final class AmbiguousActiveSession extends RuntimeException
{
    /** @param list<string> $sessionIds */
    public function __construct(
        public readonly string $taskId,
        public readonly array $sessionIds,
    ) {
        parent::__construct(sprintf(
            'Task %s has %d open governed Sessions (%s); exactly one is allowed.',
            $taskId,
            count($sessionIds),
            implode(', ', $sessionIds),
        ));
    }
}
