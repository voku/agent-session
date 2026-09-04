# PHP host integration

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

## One open Session per task

A task has at most one open **governed** working-memory Session. That rule
belongs to this package: `create()` and `rehydrate()` refuse to allocate
parallel open working memory for the same task, while hosts use the owner APIs
instead of re-deriving selection from `all()`:

```php
<?php

use voku\AgentSession\AmbiguousActiveSession;
use voku\AgentSession\SessionStore;

$sessions = new SessionStore();

$open = $sessions->openForTask($sessionsRoot, $taskId);      // raw view: every open Session
$active = $sessions->activeForTask($sessionsRoot, $taskId); // governed resume: exactly one, or null
```

`activeForTask()` throws `AmbiguousActiveSession` — carrying the task id and the
offending Session ids — when legacy or externally modified state contains more
than one open Session. A host that only renders state uses `openForTask()` and
counts, because "two are open" is something a human has to resolve, not
something the store may hide by picking a winner.

An **ephemeral** Session is outside that rule in both directions: it never
blocks a governed Session, and a governed Session never blocks it. An experiment
is created to try a command out, is never approved and is never meant to be
finished — so nobody closes it, and counting it would let a forgotten throwaway
block the real work for its task forever.

`activeForTask()` skips experiments for the same reason, so the resume lookup
counts exactly what the allocation rule counts. Permitting a state at `create()`
that `activeForTask()` then called corruption would be two definitions of
"active" inside one class. `openForTask()` remains the honest raw view — for
reporting, and for resolving a session id a human typed — because an experiment
is genuinely open; it is just not what a governed Run resumes.

## Retiring working memory

A Session that stops being open can record **why** while that pruneable Session
still exists:

```php
$sessions->close($session, SessionStatus::DROPPED, 'superseded by approved Contract revision 2');
```

`dropped` may be written both by a human abandoning a task and by a governed
owner whose newer approved Contract revision superseded the Run that Session
served. Those are different facts, so the Session records the reason instead of
trying to infer it later. The reason lives in `session.json` and disappears when
the Session is pruned. Any lifecycle provenance that must survive pruning remains
the responsibility of the durable owner, for example the governed Run history.

A closed Session **status** is terminal: it cannot be reopened or relabelled as
the other closed status, and repeating the identical close is a no-op. Use
`create()` for genuinely fresh working memory and `rehydrate()` for
caller-authorized historical identity. Both refuse to create parallel open
working memory for a task that already has an open governed Session.

## Resuming a session

A fresh agent picking up an existing Session does not need the whole
working-memory directory; it needs a compact projection of what the previous one
recorded:

```bash
agent-session handoff 2026-06-07-remove-session-access
```

```php
$handoff = (new SessionHandoffProjector())->project($session);

$handoff->nextAction;         // the recorded next concrete step, or null
$handoff->latestCheckpoint;
$handoff->decisions;          // titles; reasoning stays in decisions.md
$handoff->assumptions;        // recorded assumptions, without claiming current validity
$handoff->recordedFailures(); // historical failed observations, not a current-state verdict
$handoff->isResumable();      // false once the Session status is closed
```

The packet is **derived on read and never stored**. Working memory stays
pruneable, and the handoff does not become a second source of durable truth or a
new lifecycle authority.

Validation in the handoff is explicitly **history**. It carries each observation's
Contract revision and implementation snapshot (or `unbound`), but it does not
claim that an old PASS or failure describes the current implementation. Current
validation is selected separately by an owner that knows the current Contract
revision and implementation snapshot.

The projector also reports absence honestly. A section still holding its
scaffold placeholder, or a plan the agent rewrote without the expected headings,
is reported as empty rather than guessed at.

## Selecting validation evidence

`validation-evidence.jsonl` is append-only, so it keeps observations recorded
against earlier Contract revisions and earlier implementation content. Asking
whether a command is validated is therefore a question about *binding*, not
about presence:

```php
<?php

use voku\AgentSession\ValidationEvidenceStore;

$selection = (new ValidationEvidenceStore())->select(
    $session,
    $contractRevision,
    $implementationSnapshot, // required: sha256:<64 lowercase hex>
    $contractValidationCommands,
);

$selection->isFullyObserved(); // every obligation has an observation for this exact state
$selection->isPassing();       // ...and all of those current observations passed
$selection->stale();           // observations exist, but only for superseded state
$selection->missing;           // never observed at all
```

Exact-state selection requires the implementation snapshot. Snapshotless legacy
observations may still be stored and read, but they cannot satisfy a
snapshot-bound currentness question. A caller that cannot compute the current
implementation identity cannot safely downgrade the question to revision-only
and promote historical evidence to a current PASS.

`isFullyObserved()` deliberately says nothing about pass/fail: an honest
recorded failure is a complete answer about the current state. A close gate asks
`isPassing()`.

The three negative answers stay apart — `missing`, `supersededByImplementation`,
`supersededByRevision` — because "the code moved after this passed" and "this
was never run" call for different actions, and collapsing them is exactly what
lets a superseded PASS read as current.

## Embedding the CLI

`Cli` writes to `php://output` and `php://stderr`, and accepts explicit streams:

```php
$cli = new Cli(out: $ownStream, err: $ownErrorStream);
```

A host assembling a structured response can therefore capture or discard CLI
output with ordinary output buffering, instead of installing a stream filter to
silence a library it called in-process.

Use `create()` when a genuinely new Session is required. Use `rehydrate()` only
when a durable owner already supplies the exact Session id to restore after
working memory was pruned. Rehydration does not invent Task, Contract, Run, or
approval authority and must not be used to silently rebind a governed Run to a
different active Session.

That distinction is intentional:

- `create()` allocates fresh pruneable working context and refuses a parallel open Session;
- `rehydrate()` restores caller-authorized historical identity and refuses a parallel open Session;
- Session state remains pruneable either way;
- durable lifecycle identity remains owned outside this package.

Host code should treat conflicting or unreadable existing Session state as an
explicit failure rather than deleting or replacing it to make a resume succeed.
