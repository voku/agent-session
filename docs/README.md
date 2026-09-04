# agent-session documentation

Reference material for `voku/agent-session`, the working-memory layer of the
governed agentic-coding loop. Start with the [README](../README.md) for what the
package is and how to install it.

| Document | Covers |
| --- | --- |
| [cli.md](cli.md) | Standalone `agent-session` CLI commands, `--root`, and how the CLI relates to the governed `agent-loop` lifecycle |
| [php-api.md](php-api.md) | Typed host integration: `SessionStore`, one-open-Session-per-task, retiring working memory, handoff projection, validation-evidence selection, embedding the CLI |
| [../UPGRADING.md](../UPGRADING.md) | Breaking changes between releases, including the `session_plan/` → `.agent-loop/sessions/` move |
| [../AGENTS.md](../AGENTS.md) | Repository role, dependency direction, and the invariants a change has to preserve |

## Shipped assets

This package ships a maintainer skill under
[`resources/skills`](../resources/skills). Resolve that location through
`voku\AgentSession\PackageResources` rather than hard-coding the path.
