# Agent Session (`voku/agent-session`)

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

## CLI

```bash
agent-session start --task task.002.remove-session-access --by lars --base-commit abc123
agent-session claim 2026-06-07-remove-session-access --by lars      # refuses a live claim by someone else unless --force
agent-session checkpoint 2026-06-07-remove-session-access --title "Implementation" --body "Updated the primary service."
agent-session record 2026-06-07-remove-session-access --kind decision   --title "Keep change module-scoped" --body "..."
agent-session record 2026-06-07-remove-session-access --kind assumption --title "Missing-context behaviour" --body "..."
agent-session validation record 2026-06-07-remove-session-access --contract-revision 1 --command "vendor/bin/phpunit tests/SessionAccessTest.php" --status passed --exit-code 0 --duration-ms 1840 --by lars
agent-session close 2026-06-07-remove-session-access --status done
agent-session list --status active
agent-session show  2026-06-07-remove-session-access

# retention: working memory must be able to disappear
agent-session prune --keep-days 30 --status done,dropped --dry-run
```

Use `--root PATH` to point at a sessions directory other than
`<cwd>/.agent-loop/sessions`.

### Breaking path migration

Older releases defaulted to `<cwd>/session_plan`. This release deliberately does
not auto-discover, copy, symlink, or dual-write that directory. Move existing
working-memory state explicitly if you want to adopt the new default:

```text
session_plan/ -> .agent-loop/sessions/
```

Or keep using the old location by passing `--root session_plan` explicitly.

For the governed lifecycle, use `agent-loop workflow plan`, `approve`, `learn`,
and `close`. The standalone Session CLI deliberately exposes only pruneable
working-memory operations and validation observations.

## PHP host integration

Lifecycle/orchestration packages should use `SessionStore` directly instead of
shelling out to the Session CLI or recreating Session storage rules.

When durable workflow state already identifies the exact historical Session and
that pruneable working-memory directory has disappeared, use `rehydrate()` to
restore **that same Session identity**:

```php
<?php

declare(strict_types=1);

use voku\AgentSession\SessionStore;

$session = (new SessionStore())->rehydrate(
    $sessionsRoot,
    $exactSessionId,
    $taskId,
    $createdBy,
    $baseCommit,
);
```

Use `create()` when a genuinely new Session is required. Use `rehydrate()` only
when a durable owner already supplies the exact Session id to restore after
working memory was pruned. Rehydration does not invent Task, Contract, Run, or
approval authority and must not be used to silently rebind a governed Run to a
different active Session.

That distinction is intentional:

- `create()` allocates fresh pruneable working context;
- `rehydrate()` restores caller-authorized historical identity;
- Session state remains pruneable either way;
- durable lifecycle identity remains owned outside this package.

Host code should treat conflicting or unreadable existing Session state as an
explicit failure rather than deleting or replacing it to make a resume succeed.

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
