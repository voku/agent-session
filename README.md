# Agent Session (`voku/agent-session`)

The **working-memory** layer of the governed agentic-coding loop.

A *task* is durable intent. A *session* is the temporary, mutable context an agent
needs to finish that task: the plan, the assumptions it had to make, the decisions
it took, the validation it ran, and resumable checkpoints. Working memory is meant
to be volatile — it helps complete the current task and then it gets pruned. It is
**not** project memory, and it must not quietly become durable architecture.

This package keeps that layer explicit, claimable, and bounded.

## What it manages

Each session is one directory under a sessions root (default `session_plan/`):

```
session_plan/
  2026-06-07-remove-session-access/
    session.json        # metadata: task id, status, claim, base commit, checkpoints
    plan.md
    assumptions.md
    decisions.md
    validation.md
    checkpoints/
      index.md
      001-discovery.md
```

`session.json` carries the **claim metadata** that makes parallel agents safe:
`claimed_by`, `claimed_at`, and `base_commit`.

## Requirements

| Requirement | Version |
| --- | --- |
| PHP | 8.3 or newer |

## Install

```bash
composer require voku/agent-session
```

## CLI

```bash
agent-session start --task task.002.remove-session-access --by lars --base-commit abc123
agent-session claim 2026-06-07-remove-session-access --by lars      # refuses a live claim by someone else unless --force
agent-session checkpoint 2026-06-07-remove-session-access --title "Implementation" --body "Updated the primary service."
agent-session record 2026-06-07-remove-session-access --kind decision   --title "Keep change module-scoped" --body "..."
agent-session record 2026-06-07-remove-session-access --kind assumption --title "Missing-context behaviour" --body "..."
agent-session close 2026-06-07-remove-session-access --status done
agent-session list --status active
agent-session show  2026-06-07-remove-session-access

# retention: working memory must be able to disappear
agent-session prune --keep-days 30 --status done,dropped --dry-run
```

Use `--root PATH` to point at a sessions directory other than `<cwd>/session_plan`.

## Where it fits

This is one layer of the loop. It pairs with:

- `voku/agent-kanban` — the durable tasks the sessions serve.
- `voku/agent-learning` — findings/proposals distilled *from* a finished session.
- `voku/agent-recall-compiler` — the briefing compiled *before* a session.
- `voku/agent-loop` — the unified `agent-loop` binary that exposes all of them (`agent-loop session …`).

## Development

```bash
composer install
composer ci   # validate + phpunit + phpstan (level 8)
```

## License

MIT — see [LICENSE](LICENSE).
