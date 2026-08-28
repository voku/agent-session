<?php

declare(strict_types=1);

namespace voku\AgentSession;

use RuntimeException;

final class UnsupportedSessionMetadataVersion extends RuntimeException
{
    public function __construct(string $path, mixed $version)
    {
        parent::__construct(sprintf(
            'Unsupported Session metadata schema version %s in %s; supported versions are 1.0 and 1.1.',
            is_string($version) && trim($version) !== '' ? $version : '<missing-or-invalid>',
            $path,
        ));
    }
}
