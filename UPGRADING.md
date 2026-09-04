# Upgrading

## Sessions root moved to `.agent-loop/sessions/`

Older releases defaulted to `<cwd>/session_plan`. Current releases default to
`<cwd>/.agent-loop/sessions` and deliberately do **not** auto-discover, copy,
symlink, or dual-write the old directory.

Move existing working-memory state explicitly if you want to adopt the new
default:

```text
session_plan/ -> .agent-loop/sessions/
```

Or keep using the old location by passing `--root session_plan` explicitly on
every invocation:

```bash
agent-session list --root session_plan
```

There is no migration command, and none is planned. Working memory is meant to
be prunable, so an unmigrated `session_plan/` directory is a safe thing to
delete once nothing in it is still needed.
