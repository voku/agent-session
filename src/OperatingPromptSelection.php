<?php

declare(strict_types=1);

namespace voku\AgentSession;

use InvalidArgumentException;
use JsonException;

/**
 * Dependency-neutral task policy selecting one reusable operating-prompt recipe.
 *
 * agent-session owns the approved selection and arguments. It deliberately does
 * not know how agent-recall-compiler resolves or renders the selected recipe.
 */
final readonly class OperatingPromptSelection
{
    public string $id;

    /** @var array<string, bool|int|string> */
    public array $arguments;

    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(string $id, array $arguments = [])
    {
        $id = trim($id);
        if ($id === '' || preg_match('/^[a-z][a-z0-9._-]*$/', $id) !== 1) {
            throw new InvalidArgumentException('Operating prompt id must match [a-z][a-z0-9._-]*.');
        }

        $typedArguments = [];
        foreach ($arguments as $name => $value) {
            if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
                throw new InvalidArgumentException('Operating prompt argument names must match [a-z][a-z0-9_]*.');
            }
            if (!is_bool($value) && !is_int($value) && !is_string($value)) {
                throw new InvalidArgumentException('Operating prompt arguments must be bool, int, or string.');
            }
            $typedArguments[$name] = $value;
        }

        ksort($typedArguments);
        $this->id = $id;
        $this->arguments = $typedArguments;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $id = $data['id'] ?? null;
        if (!is_string($id)) {
            throw new InvalidArgumentException('Operating prompt selection requires string id.');
        }

        $arguments = $data['arguments'] ?? [];
        if (!is_array($arguments)) {
            throw new InvalidArgumentException('Operating prompt selection arguments must be an object.');
        }

        /** @var array<string, mixed> $arguments */
        return new self($id, $arguments);
    }

    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Operating prompt selection must be valid JSON: ' . $exception->getMessage(), 0, $exception);
        }
        if (!is_array($data)) {
            throw new InvalidArgumentException('Operating prompt selection must be a JSON object.');
        }

        return self::fromArray($data);
    }

    /**
     * @return array{id: string, arguments: array<string, bool|int|string>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'arguments' => $this->arguments,
        ];
    }
}
