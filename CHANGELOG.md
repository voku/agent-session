# Changelog

All notable changes to this project are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

### Added

- `SessionStore::activeForTask()`, `openForTask()` and `allForTask()` own the
  "one open Session per task" invariant. Every governed owner previously
  re-derived it from `all()`, which is how one storage rule ended up with
  several different failure behaviours: some callers threw, one silently
  returned nothing, and one reported a count.
- `Session::$closedAt` / `$closedReason` and `SessionStore::close()` record
  *why* working memory stopped being open. `dropped` is written both by a human
  abandoning a task and by a governed owner whose newer approved Contract
  revision superseded the Run a Session served; those are different facts, and
  an unexplained `dropped` is the one thing nobody can reconstruct after the
  Session is pruned.
- `agent-session close <id> --reason TEXT` and `agent-session list --task ID`.

### Changed

- A closed Session is terminal. `SessionStore::setStatus()` refuses to reopen it
  or relabel it as the other closed status, and repeating the identical close is
  idempotent. `create()` allocates fresh working memory and `rehydrate()`
  restores caller-authorized historical identity; silently reviving a finished
  Session would make a governed Run look live again without any owner deciding
  that it is.
- `Cli` writes to `php://output` / `php://stderr` instead of the `STDOUT` /
  `STDERR` constants, and accepts explicit streams. A PHP host embedding the CLI
  in-process can now capture or discard its output with ordinary output
  buffering, instead of installing a stream filter to silence a library it
  called itself.
- `session.json` is schema `1.1`. The added fields are optional and `1.0`
  metadata still loads unchanged.

## 0.6.1 - 2026-08-17

### Fixed

- `SessionStore::rehydrate()` can recreate pruneable working memory at an exact,
  already-authoritative Session ID after pruning or a clean checkout. It rejects
  unsafe IDs and existing paths instead of deriving a new date-based identity or
  overwriting surviving Session state.
- `dev-main` again matches the current 0.6 release line instead of advertising
  the stale `0.5.x-dev` alias.
- The README reflects the 0.5 ownership boundary and current CLI: Session owns
  pruneable working memory and Contract-revisioned validation observations, not
  the removed work-brief, approval, or Learning commands and files.

## 0.6.0 - 2026-08-15

### Changed

- **Breaking:** validation evidence may carry the deterministic implementation
  snapshot observed by the validation command. Governed consumers can now
  reject a PASS recorded for an earlier implementation state even when the
  Contract revision and command are unchanged.
- `validation record` accepts `--implementation-snapshot sha256:<digest>` and
  persists that opaque identity without trying to compute repository state in
  `agent-session` itself.

## 0.5.0 - 2026-08-12

Session becomes what its name claims: disposable working memory for one governed
Run. Everything a Run must still be able to explain after its Session is pruned
now belongs to the package that owns it.

### Removed

- **Breaking:** Session no longer owns durable approved work. `WorkBrief`,
  `WorkBriefStatus`, `WorkBriefStore`, `Approval` and `OperatingPromptSelection`
  are removed. A durable Contract and its approval are owned by `agent-loop`,
  which persists them before any Session exists.
- **Breaking:** Session no longer owns Learning close-out. `LearningDecision`,
  `LearningDecisionRecord` and `LearningDecisionStore` are removed.
  `agent-learning` owns the durable run Learning decision.

  Both removals delete a real contradiction rather than move code: while Session
  held them, pruning working memory destroyed the evidence that explained why a
  Run was allowed to close.

### Changed

- **Breaking:** validation observations are recorded against an explicit
  Contract revision, so evidence gathered for a superseded revision is
  distinguishable from evidence for the current one instead of silently
  counting.
- **Breaking:** the standalone CLI now defaults its sessions root to
  `<cwd>/.agent-loop/sessions` instead of `<cwd>/session_plan`. Explicit
  `--root` remains authoritative. Existing state is not copied, symlinked, or
  dual-written; migrate `session_plan/` explicitly or keep selecting it with
  `--root session_plan`.

### Upgrading

Consumers that read Session-owned work briefs, approvals or learning decisions
must read them from their new owners. There is no compatibility shim: a
pre-1.0 breaking migration that silently kept answering from the old location
would reintroduce exactly the ambiguity this release removes.

## 0.4.0 - 2026-08-09

### Added

- Work briefs can now seal an explicit operating-prompt policy together with the
  task goal, scope, non-goals, validation commands, tags, and behavior anchors.
  The policy contains an optional manifest source plus typed prompt selections
  with deterministic `bool|int|string` arguments. This lets an orchestrator such
  as `voku/agent-loop` approve the L2 recipe and its thresholds as part of the
  same revision that authorizes the implementation.
- Added the typed `OperatingPromptSelection` value object and JSON projection for
  operating-prompt selections. Prompt identifiers and arguments are normalized
  and validated before they enter the WorkBrief rather than being carried as
  unstructured orchestration metadata.

### Changed

- Revising operating-prompt policy creates a new candidate WorkBrief revision,
  archives the previous revision, and invalidates its approval exactly like a
  goal, scope, or validation change. Historical approvals therefore cannot
  silently authorize different prompt policy or weaker thresholds.
- `dev-main` now follows the `0.4.x-dev` release line.

### Fixed

- Validation evidence cannot claim `status=passed` with a non-zero exit code.
  Contradictory execution evidence is rejected instead of being persisted as a
  successful validation result.

## 0.3.0 - 2026-08-06

### Added

- `session start --ephemeral` marks a session as an experiment: created to try a
  command out, never approved, never meant to be finished. The flag is persisted
  as `ephemeral` in `session.json` and survives reload and status changes.
  `Session::$ephemeral` defaults to `false`, so a session written before the flag
  existed still counts as governed work - defaulting the other way would let old
  sessions quietly escape every repository gate.

### Fixed

- The binary resolved its autoloader by preferring the package's own `vendor/`
  directory. When one is present next to an installed copy - a path repository, a
  mirrored checkout, a stale local install - that autoloader wins and silently
  loads *its* dependencies instead of the project's. Found by a release-set smoke
  test that reported `Undefined property Session::$ephemeral` against an
  installed version that plainly had it. The outer autoloader is now tried first.

### Why

- A throwaway session created during a dogfood run failed the repository-wide
  `agent-loop verify` for every other session until it was explicitly dropped.
  The gate was correct; the model was missing a way to say "this was never
  governed work".

## [0.2.2] - 2026-08-02

### Added

- Work briefs may carry optional, repeatable behavior anchors (via
  `--behavior-anchor` on `brief create`/`brief revise`). Anchors preserve the
  concrete behavior that must remain true while agents plan, implement, and
  review a change. Briefs without anchors remain fully compatible.

## [0.2.1] - 2026-07-23

### Added

- Work briefs may carry optional, repeatable relevance `tags` (via `--tag` on
  `brief create`/`brief revise`), independent of `--scope` paths. Recall
  consumers such as `voku/agent-recall-compiler` can match facts against
  these tags even when a task's files share no path prefix with the fact's
  scope. Purely additive: briefs without tags decode and behave exactly as
  before.

## 0.2.0 - 2026-07-13

### Added

- Versioned, append-only validation evidence records bound to a work-brief
  revision, including command, result, exit code, timestamp, and optional
  duration.
- Explicit per-session learning decisions: `findings_recorded`,
  `no_durable_learning`, or `follow_up_required`.

## 0.1.0 - 2026-07-13

### Added

- Revisioned, session-local work briefs with explicit candidate, approved, and
  superseded states.
- `agent-session brief create`, `revise`, `approve`, and `show` commands.
- Approval metadata bound to the approved work-brief revision and an immutable
  history of superseded briefs and approvals.
