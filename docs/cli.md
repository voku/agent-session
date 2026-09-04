# CLI reference

The standalone CLI is installed as `vendor/bin/agent-session`.

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
agent-session handoff 2026-06-07-remove-session-access            # resume packet for a fresh agent
agent-session handoff 2026-06-07-remove-session-access --format json

# retention: working memory must be able to disappear
agent-session prune --keep-days 30 --status done,dropped --dry-run
```

Use `--root PATH` to point at a sessions directory other than
`<cwd>/.agent-loop/sessions`. See [UPGRADING.md](../UPGRADING.md) for the move
away from the older `session_plan/` default.

## Relationship to the governed lifecycle

For the governed lifecycle, enter through `agent-loop enter <task-id>` and
reconcile deterministic close-out through `agent-loop finish <task-id>`. Follow
the returned canonical next action instead of scripting `workflow plan`,
`approve`, `learn`, and `close` as a second phase machine. The standalone
Session CLI deliberately exposes only pruneable working-memory operations and
validation observations.

The `agent-loop session …` and `agent-loop workflow …` namespaces are
lower-level owner and orchestration surfaces that the lifecycle may return as a
canonical next action; they are not alternative governed lifecycle entry points.
