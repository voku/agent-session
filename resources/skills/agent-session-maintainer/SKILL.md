---
name: agent-session-maintainer
description: Maintain voku/agent-session package source, tests, docs, and CLI behavior for pruneable working memory.
metadata:
  author: Lars Moelleken <lars@moelleken.org>
---

# Agent Session Maintainer

Use this skill when changing the `voku/agent-session` package itself.
Read [AGENTS.md](../../../AGENTS.md) for the canonical dependency boundaries,
Session invariants, validation gate, and release rules. Do not duplicate them
in this skill.

## Package-specific checks

- For allocation, claim, close, reopen, or exact-id rehydration, inspect
  `SessionStore` and `tests/SessionStoreTest.php`; do not infer lifecycle
  semantics from a generic "closed means terminal" assumption.
- When editing `SessionScaffold` headings/placeholders, check
  `SessionHandoffProjector` and its tests: handoff reads only the package's
  recognized output shapes.
- When changing validation evidence, preserve Contract revision and
  implementation snapshot binding; historical PASS is not current proof.
- Resolve shipped assets via `PackageResources`, not hard-coded package paths.
- Verify the affected behavior with focused tests and the repository's
  declared `composer ci` gate.
