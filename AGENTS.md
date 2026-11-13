# AGENTS.md

## Repository role

`voku/agent-session` owns pruneable working memory for one coding task: Session identity, claim metadata, plans, assumptions, decisions, checkpoints, validation observations, and compact handoff projection.

Working memory is deliberately temporary. Do not turn Session state into durable project memory, workflow authority, Contract approval, review acknowledgement, or Learning truth.

## Dependency direction

This package is a low-level owner and has no runtime `agent-*` dependency. Preserve that direction.

- Do not add a runtime dependency on `agent-loop`, `agent-recall-compiler`, `agent-learning`, `agent-kanban`, or `agent-loop-runner` to solve an orchestration problem.
- Higher-level hosts should consume the typed PHP API such as `SessionStore` and `SessionHandoffProjector`; do not teach them to parse CLI output or reconstruct Session storage rules.
- Contract revision/approval remains owned by `agent-loop`; durable Learning remains owned by `agent-learning`.

## Invariants to preserve

- A task has at most one open governed Session. Ephemeral Sessions are explicitly outside that rule in both directions.
- `setStatus()`/`close()` cannot relabel a closed Session; an identical close may be a no-op. `SessionStore::reopen()` is a narrow exception for `DONE` Sessions: it preserves the exact id, requires a non-empty reason and no conflicting open Session, and records a checkpoint. `DROPPED` Sessions cannot be reopened. The caller still owns any required governed approval.
- `rehydrate()` restores an exact caller-authorized historical Session identity; it must not silently allocate a replacement identity.
- Validation evidence stored here is an observation bound to the recorded Contract revision/snapshot. Historical PASS/FAIL must never be promoted to current implementation truth by this package.
- Handoff output is a derived read projection, not a new persisted authority surface.
- Pruning working memory must remain safe because durable lineage belongs to its durable owner.

## Implementation guidance

Keep filesystem layout knowledge inside Session-owned APIs. When a caller needs a new Session fact, first ask whether it is genuinely working-memory state; if it must survive Session pruning, it belongs to a durable owner.

## Validation

Run the repository's declared gate before claiming completion:

```bash
composer ci
```

This includes strict Composer validation, PHPUnit, and PHPStan.

## Releases

Releases are marker-driven. A `.release/<version>.json` marker must point at a release-ready commit whose own `CHANGELOG.md` contains that version. Do not create or move release tags by hand when the repository workflow can do it.