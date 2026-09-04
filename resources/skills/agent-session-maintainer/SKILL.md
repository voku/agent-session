---
name: agent-session-maintainer
description: Maintain voku/agent-session package source, tests, docs, and CLI behavior for pruneable working memory - Session identity, claim metadata, checkpoints, handoff projection, validation evidence, and retention.
---

# Agent Session Maintainer

Use this skill when changing the `voku/agent-session` package itself. This package
owns *pruneable working memory* for one coding task. Keep it a low-level owner:
durable authority belongs to the packages above it.

## Dependency direction

This package has no runtime `agent-*` dependency. Preserve that direction.

- Do not add a runtime dependency on `agent-loop`, `agent-recall-compiler`,
  `agent-learning`, `agent-kanban`, or `agent-loop-runner` to solve an
  orchestration problem.
- Expose typed PHP APIs such as `SessionStore`, `SessionHandoffProjector`, and
  `ValidationEvidenceStore` for hosts. Do not teach hosts to parse CLI output or
  to reconstruct Session storage rules.
- Contract revision and approval stay owned by `agent-loop`; durable Learning
  stays owned by `agent-learning`.

## Invariants to preserve

Every change has to leave these true:

1. A task has at most one open **governed** Session. Ephemeral Sessions are
   outside that rule in both directions.
2. Closed Session status is terminal. An identical repeated close may be a no-op;
   reopening or relabelling terminal state is not allowed.
3. `rehydrate()` restores an exact caller-authorized historical Session identity
   and never silently allocates a replacement.
4. Stored validation evidence is an observation bound to its recorded Contract
   revision and implementation snapshot. Historical PASS/FAIL is never promoted
   to current implementation truth by this package.
5. Handoff output is a derived read projection, never a new persisted authority.
6. Pruning working memory stays safe, because durable lineage lives elsewhere.

## Fast path

1. Read `AGENTS.md` and the relevant source before editing; check `git status --short`.
2. Keep filesystem layout knowledge inside Session-owned APIs, not in callers.
3. Before adding a new Session fact, ask whether it is genuinely working-memory
   state. If it must survive pruning, it belongs to a durable owner instead.
4. Prefer small immutable value objects, explicit types, and fail-closed validation.
5. Add focused regression tests for identity allocation, terminal status, claim
   handling, evidence selection/binding, handoff projection, or CLI behavior
   whenever those change.
6. Package-shipped assets resolve through `PackageResources`; do not hard-code
   `resources/...` paths in production code or tests.

## Validation

Run the declared gate from the package repository before claiming completion:

```bash
composer ci
```

That covers strict Composer validation, PHPUnit, and PHPStan. When you also test
inside a consuming project, report those integration commands separately - they
do not substitute for the package gate.

## Releases

Releases are marker-driven: a `.release/<version>.json` marker must point at a
release-ready commit whose own `CHANGELOG.md` contains that version. Do not
create or move release tags by hand when the repository workflow can do it.
