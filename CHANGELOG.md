# Changelog

All notable changes to this project are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
