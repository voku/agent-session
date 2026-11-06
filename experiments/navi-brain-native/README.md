# Pinned Navi-Brain native-memory probe

This is an isolated exploratory integration probe for a public upstream repository,
not a runtime dependency of agent-session or a test of its SessionStore API.

- Upstream: hparadiz/Navi-Brain
- Exact revision: 39880c39e0d5af2bff5c3ba5f91c0b94aecbbff8
- Tested component: C17 tokmem daemon and durable token-memory store
- Host: disposable GitHub Actions Ubuntu 24.04 runner
- Inputs: synthetic names and sentences only
- Authorization: contents read, no secrets, private files or model provider
- Artifact: navi-brain-native.json (also printed in job log)

## Reproduce

The workflow clones the exact public revision, compiles the C binary using
SQLite/pthreads, then executes the Python probe with the binary's path.
The probe initializes the native store using upstream's tokmem init command.
Plain directories are not valid native stores.

## Hard assertions

1. Creation preserves exact bytes and produces distinct IDs.
2. Repeating each create with the same operation key preserves its record ID.
3. Reusing an operation key for changed semantics fails closed.
4. A new daemon process reloads exact records.
5. Replaying a receipt across process restart creates no new record.
6. Clean native-store verification succeeds.
7. Corrupting a binary memory file in an isolated offline store copy fails
   native checksum/integrity verification.

## Retrieval measurement

The corpus contains 16 fictional project facts, including 12 similar
deployment projects. There are 20 cues: 16 exact project names and four
multiword cues from the facts. Each query returns at most three records,
less than the number stored.

The artifact reports target rank, recall@3 and MRR@3. It also invokes
ACTIVATE, the upstream's dedicated token-budgeted context assembly path, on
the same 20 cues in a second pass. ACTIVATE has a 64-native-token budget;
we record exact-target inclusion and the selected synthetic context, so a
trivial all-records response cannot be mistaken for selective retrieval.
These measurements are observational, not a hard pass gate: failures are evidence.

In the initial pinned CI run, RECALL_RECORDS achieved 65% recall@3 and
MRR@3 of 0.625. ACTIVATE returned only the target memory for each of the
20 cues, scoring 100% inclusion with 56-61 output bytes per result.
These are not contradictory API results: the paths use different ranking
strategies, and neither result demonstrates downstream model accuracy.

The three evidence-availability conditions are:

- No memory: zero historical facts are available.
- Raw transcript: all 16 facts would be supplied verbatim.
- Native recall: at most three records are returned for each cue.

These are NOT three executed agent conditions. No model answers a question,
no evaluator scores correctness, and no matched token-budget comparison is
performed. A retrieved fact is not proof an agent uses it correctly.

The causal agent-level experiment remains outstanding: same model, prompt,
tools, task, token budgets and independent scorer for each condition, with
held-out cases, sham retrieval, misleading memories and corrections.

## Scope and packaging

This does not prove the PHP executive, async REMEMBER handoff, Rust supervisor,
or privacy-sensitive integrations. The experiments directory is excluded
from release archives using .gitattributes. Inspect exact-head CI and artifacts
before considering merge; no merge requested.
