# Changelog

All notable changes to this project are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 0.1.0 - 2026-07-13

### Added

- Revisioned, session-local work briefs with explicit candidate, approved, and
  superseded states.
- `agent-session brief create`, `revise`, `approve`, and `show` commands.
- Approval metadata bound to the approved work-brief revision and an immutable
  history of superseded briefs and approvals.
