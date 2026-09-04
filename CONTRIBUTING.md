# Contributing

Thanks for considering a contribution to `voku/agent-session`.

## Scope

`agent-session` is the working-memory layer of the governed agentic-coding
loop. It manages volatile context an agent needs during task execution: plans,
assumptions, decisions, validation evidence, checkpoints, and session claims.

## Development setup

```bash
git clone https://github.com/voku/agent-session.git
cd agent-session
composer install
```

## Before opening a PR

```bash
composer test      # PHPUnit
composer phpstan   # PHPStan at max level
composer ci        # Runs composer validate --strict, test, and phpstan
```

All checks must pass cleanly.

## Code style

- `declare(strict_types=1)` in every PHP file.
- `final` classes and `readonly` value objects wherever applicable.
- Strict typing with zero PHPStan errors.
- Unit tests under `tests/` mirroring the `src/` directory structure.
- Clear commit messages and focused pull requests.
