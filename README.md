# Agent Session (`voku/agent-session`)

[![Build Status](https://github.com/voku/agent-session/actions/workflows/ci.yml/badge.svg)](https://github.com/voku/agent-session/actions)
[![Latest Stable Version](https://poser.pugx.org/voku/agent-session/v/stable)](https://packagist.org/packages/voku/agent-session)
[![Total Downloads](https://poser.pugx.org/voku/agent-session/downloads)](https://packagist.org/packages/voku/agent-session)
[![Monthly Downloads](https://poser.pugx.org/voku/agent-session/d/monthly)](https://packagist.org/packages/voku/agent-session)
[![License](https://poser.pugx.org/voku/agent-session/license)](https://packagist.org/packages/voku/agent-session)
[![PHP Version Require](https://poser.pugx.org/voku/agent-session/require/php)](https://packagist.org/packages/voku/agent-session)
[![GitHub Stars](https://img.shields.io/github/stars/voku/agent-session?style=flat-square)](https://github.com/voku/agent-session/stargazers)
[![GitHub Forks](https://img.shields.io/github/forks/voku/agent-session?style=flat-square)](https://github.com/voku/agent-session/network/members)

The **working-memory** layer of the governed agentic-coding loop.

A *task* is durable intent. A *session* is the temporary, mutable context an agent
needs to finish that task: the plan, the assumptions it had to make, the decisions
it took, the validation it ran, and resumable checkpoints. Working memory is meant
to be volatile — it helps complete the current task and then it gets pruned. It is
**not** project memory, and it must not quietly become durable architecture.

This package keeps that layer explicit, claimable, and bounded.

## What it manages

Each session is one directory under the sessions root. The standalone CLI now
defaults to `.agent-loop/sessions/`:

```text
.agent-loop/
  sessions/
    2026-06-07-remove-session-access/
      session.json        # metadata: task id, status, claim, base commit, checkpoints
      plan.md
      assumptions.md
      decisions.md
      validation.md
      validation-evidence.jsonl # append-only machine-readable validation executions
      checkpoints/
        index.md
        001-discovery.md
```

`session.json` carries the **claim metadata** that makes parallel agents safe:
`claimed_by`, `claimed_at`, and `base_commit`.

`validation-evidence.jsonl` records actual executions against the durable
Contract revision that required them. A record cannot claim `passed` with a
non-zero exit code. Contract revisions and their approval are owned by
`voku/agent-loop`; durable Learning close-out is owned by `voku/agent-learning`.
Keeping those artifacts out of the Session is what makes pruning working memory
safe.

## Requirements

| Requirement | Version |
| --- | --- |
| PHP | 8.3 or newer |

## Install

```bash
composer require voku/agent-session
```

## Quick start

```bash
# start working memory for a task and claim it
agent-session start --task task.002.remove-session-access --by lars --base-commit abc123
agent-session claim 2026-06-07-remove-session-access --by lars

# record what the agent decided, assumed, and verified
agent-session record 2026-06-07-remove-session-access --kind decision --title "Keep change module-scoped" --body "..."
agent-session checkpoint 2026-06-07-remove-session-access --title "Implementation" --body "Updated the primary service."

# hand the task to a fresh agent, then retire the working memory
agent-session handoff 2026-06-07-remove-session-access
agent-session close 2026-06-07-remove-session-access --status done
agent-session prune --keep-days 30 --status done,dropped --dry-run
```

Sessions default to `<cwd>/.agent-loop/sessions`; use `--root PATH` to point
somewhere else. The full command surface is in [docs/cli.md](docs/cli.md).

Hosts should embed the typed API rather than shelling out:

```php
use voku\AgentSession\SessionStore;

$sessions = new SessionStore();
$active = $sessions->activeForTask($sessionsRoot, $taskId); // exactly one, or null
```

See [docs/php-api.md](docs/php-api.md) for the full host integration contract.

## Documentation

| Document | Covers |
| --- | --- |
| [docs/cli.md](docs/cli.md) | CLI reference and the relationship to the governed `agent-loop` lifecycle |
| [docs/php-api.md](docs/php-api.md) | Typed host integration, Session allocation rules, handoff, validation evidence |
| [UPGRADING.md](UPGRADING.md) | Breaking changes between releases |
| [AGENTS.md](AGENTS.md) | Repository role, dependency direction, invariants |

## Shipped assets

This package ships a maintainer skill for agents working on the package itself:

```text
resources/skills/agent-session-maintainer/SKILL.md
```

Resolve shipped assets through `PackageResources` instead of hard-coding paths:

```php
use voku\AgentSession\PackageResources;

PackageResources::SKILLS;       // 'resources/skills'
PackageResources::skillsRoot(); // absolute path inside the installed package
```

## Where it fits

This is one layer of the loop. It pairs with:

- `voku/agent-kanban` — the durable tasks the sessions serve.
- `voku/agent-learning` — findings/proposals distilled *from* a finished session.
- `voku/agent-recall-compiler` — deterministic recall and project-specific prompt construction from the approved Contract.
- `voku/agent-loop` — the unified `agent-loop` binary that exposes and governs the cross-package lifecycle (`agent-loop session …`, `agent-loop workflow …`).

## Development

```bash
composer install
composer ci   # validate + phpunit + phpstan
```

## License

MIT — see [LICENSE](LICENSE).
