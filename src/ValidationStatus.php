<?php

declare(strict_types=1);

namespace voku\AgentSession;

enum ValidationStatus: string
{
    case PASSED = 'passed';
    case FAILED = 'failed';
}
