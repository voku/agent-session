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
      work-brief.json     # versioned candidate/approved task-policy contract (created on demand)
      work-brief.md       # human-readable projection of the current brief
      approval.json       # current approval metadata, only when the current brief is approved
      work-brief-history/ # superseded briefs and their historical approvals
      plan.md
      assumptions.md
      decisions.md
      validation.md
      validation-evidence.jsonl # append-only machine-readable validation executions
      learning-decision.json    # explicit close-out decision (created on demand)
      checkpoints/
        index.md
        001-discovery.md
```

`session.json` carries the **claim metadata** that makes parallel agents safe:
`claimed_by`, `claimed_at`, and `base_commit`.

`work-brief.json` is intentionally separate from mutable plan notes. It records
the task goal, approved scope, non-goals, validation commands, optional behavior
anchors, optional relevance tags, and optional operating-prompt policy, plus a
schema version and revision/status (`candidate`, `approved`, or `superseded`).
A behavior anchor names the real request, runtime, consumer, data, or integration
boundary that owns a behavioral change; documentation-only work may deliberately
have none.

Operating-prompt policy is part of the same approval boundary. A WorkBrief may
carry an explicit prompt-manifest source and typed prompt selections with
`bool|int|string` arguments. The selection is policy, not generated prompt text:
`agent-session` seals which recipe and thresholds were approved, while a consumer
such as `voku/agent-recall-compiler` can combine that policy with current project
evidence to construct a project-specific execution prompt. `voku/agent-loop`
provides the user-facing workflow commands for that governed L2 -> L1 flow.

Changing goal, scope, validation, anchors, tags, prompt manifest, or prompt
selection creates a new candidate revision, archives the prior revision, and
invalidates its approval. Existing sessions without a work brief or without
operating-prompt policy remain valid.

`validation-evidence.jsonl` records actual executions against the brief revision
that required them. A record cannot claim `passed` with a non-zero exit code.
Re-planning does not delete old evidence; the next workflow can therefore show it
as stale instead of accidentally treating it as proof for the new scope.
`learning-decision.json` makes forgetting explicit: a completed session records
whether it produced findings, no durable learning, or a follow-up.

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
agent-session brief create 2026-06-07-remove-session-access --goal "Remove obsolete session access." --scope src/SessionAccess.php --scope tests/SessionAccessTest.php --non-goal "Do not add a new memory layer." --validation "vendor/bin/phpunit tests/SessionAccessTest.php" --behavior-anchor "request -> SessionAccess service -> persisted access state"
agent-session brief approve 2026-06-07-remove-session-access --by lars
# A changed scope creates a new candidate revision and clears the current approval.
agent-session brief revise 2026-06-07-remove-session-access --goal "Remove obsolete session access." --scope src/SessionAccess.php --scope tests/SessionAccessTest.php --scope docs/session-access.md --validation "vendor/bin/phpunit tests/SessionAccessTest.php"
agent-session brief show 2026-06-07-remove-session-access
agent-session validation record 2026-06-07-remove-session-access --brief-revision 1 --command "vendor/bin/phpunit tests/SessionAccessTest.php" --status passed --exit-code 0 --duration-ms 1840 --by lars
agent-session learning decide 2026-06-07-remove-session-access --status no_durable_learning --by lars --reason "No reusable finding emerged."
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

The standalone `agent-session brief` CLI intentionally stays focused on the
basic brief lifecycle. Orchestrators can use the typed `WorkBriefStore` /
`OperatingPromptSelection` API when they need to seal operating-prompt policy;
`agent-loop workflow plan` is the primary integrated CLI for that path.

## Where it fits

This is one layer of the loop. It pairs with:

- `voku/agent-kanban` — the durable tasks the sessions serve.
- `voku/agent-learning` — findings/proposals distilled *from* a finished session.
- `voku/agent-recall-compiler` — deterministic recall and project-specific prompt construction from the approved brief.
- `voku/agent-loop` — the unified `agent-loop` binary that exposes and governs the cross-package lifecycle (`agent-loop session …`, `agent-loop workflow …`).

## Development

```bash
composer install
composer ci   # validate + phpunit + phpstan
```

## License

MIT — see [LICENSE](LICENSE).
