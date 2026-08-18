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
agent-session close 2026-06-07-remove-session-access --status dropped --reason "superseded by approved Contract revision 2"
agent-session list --status active
agent-session list --task task.002.remove-session-access
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

### One open Session per task

A task has at most one open working-memory Session. That rule belongs to this
package, so hosts select instead of re-deriving it:

```php
<?php

use voku\AgentSession\AmbiguousActiveSession;
use voku\AgentSession\SessionStore;

$sessions = new SessionStore();

$open = $sessions->openForTask($sessionsRoot, $taskId);   // report the real state
$active = $sessions->activeForTask($sessionsRoot, $taskId); // pick exactly one, or null
```

`activeForTask()` throws `AmbiguousActiveSession` — carrying the task id and the
offending Session ids — when more than one Session is open. A host that only
renders state uses `openForTask()` and counts, because "two are open" is
something a human has to resolve, not something the store may pick a winner for.

### Retiring working memory

A Session that stops being open records **why**:

```php
$sessions->close($session, SessionStatus::DROPPED, 'superseded by approved Contract revision 2');
```

`dropped` is written both by a human abandoning a task and by a governed owner
whose newer approved Contract revision superseded the Run that Session served.
Those are different facts. Recording the reason keeps a Session explainable
after it is pruned, when only durable state remains.

A closed Session is terminal: it cannot be reopened or relabelled as the other
closed status, and repeating the identical close is a no-op. Use `create()` for
fresh working memory and `rehydrate()` for caller-authorized historical
identity — reviving a finished Session would make a governed Run look live again
without any owner having decided that it is.

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
