# Changelog

All notable changes to Log Lens are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Both published packages — `cliqthemes/log-lens-core` (the standalone engine)
and `cliqthemes/log-lens` (the Laravel adapter) — are versioned together and
share this file.

## 0.3.0 — 2026-09-08

### Added

- **Resumable Linear sync**: a persisted, resumable cursor splits a
  workspace pull into bounded batches across calls instead of one
  all-at-once sync — a backfill phase (first full pass over the filter)
  followed by an incremental phase (only issues updated since). See
  [Backfilling and staying in sync](docs/features/linear-integration.md#backfilling-and-staying-in-sync).
- **Linear write-back comment default**: a `status_writeback_comment`
  setting (Settings → Linear → "Comment by default") seeds whether the
  per-status-change "Also comment on Linear" checkbox starts checked,
  without forcing it either way. See
  [Status write-back](docs/features/linear-integration.md#status-write-back).
- **Bulk push current status to Linear** (`POST ?api=linear-push`): push
  the current Log Lens status of any number of linear-sourced issues to
  Linear right now, regardless of whether write-back was on when that
  status was actually set. See
  [Bulk push current status](docs/features/linear-integration.md#bulk-push-current-status).
- **MCP server** (`bin/mcp-server.php`): a [Model Context
  Protocol](https://modelcontextprotocol.io) server over JSON-RPC 2.0/stdio
  exposing `issues_{list,get,update_status}`, `applications_list`, and
  `linear_{sync,test}` as tools for an MCP-capable agent (Claude Code,
  Claude Desktop, etc.) — the same API every HTTP client uses, no parallel
  logic. See [MCP server](docs/features/mcp-server.md).

## 0.2.0 — 2026-08-26

### Added

- **`log-lens:install`**, an interactive setup wizard for the Laravel adapter:
  publishes the assets/config, detects `storage/logs` and offers to import it,
  offers a connector for ongoing sync (printing the schedule line to paste
  in), offers to turn on the zero-key error reporter, and can generate an
  agent token. Safe to re-run. `vendor:publish` still works by hand.
- **Agent tokens** (`LOG_LENS_AGENT_TOKEN` / `log-lens.agent_token`) — a
  sessionless credential for the Laravel adapter, separate from the
  standalone package's API token. Sent as `X-Log-Lens-Agent-Token` or a
  `Bearer` header, it unlocks the JSON API only (never the dashboard shell),
  is checked before any registered auth callback, and is meant for the
  bundled issue-fixing skill or a script — not a person's browser session.
  See [Laravel access control](docs/features/laravel-access-control.md#method-4---an-agent-token-for-sessionless-callers).
- The Claude/Codex agent skill now detects a Laravel-embedded install first
  (looking for `vendor/cliqthemes/log-lens` + `artisan`) and authenticates
  against it with an agent token, instead of assuming a standalone install.

### Changed

- The Laravel adapter's README and access-control docs no longer describe
  authorization as "matching opcodesio/log-viewer" — Log Lens's Laravel
  auth model is documented on its own terms.


First public release.

### Added

- **Local-first log dashboard and error tracker.** Streams large log files,
  groups recurring events into fingerprinted issues, keeps the raw byte range
  behind every occurrence, and never sends log data off the machine running it.
- **Five parsers**: Laravel, Laravel-framed Horizon, Horizon/`queue:work`
  console output, nginx access logs, and a generic console fallback. Hosts can
  register their own through `parsing.register`.
- **Issue workflow**: `open`, `in_progress`, `fixed`, `wont_fix`, `reoccurred`,
  with an immutable status history that records the actor, plus tags, modules,
  and assignment.
- **Per-application isolation.** One application is selected at a time and owns
  its own database and directories — never an aggregated view across
  applications.
- **SQLite by default, Postgres and MySQL opt-in** via `LOG_LENS_DB_DRIVER`.
  SQLite is file-per-application, Postgres schema-per-application, MySQL
  database-per-application.
- **Discrete migrations** with up/down, compiled per engine, plus a rollback
  path.
- **Connectors** (local directory, SSH) with byte-offset checkpoints,
  incremental pulls, rotation and rename reconciliation, a detached background
  worker, a scheduled drain, and a stale-run reaper.
- **Plugins**, opt-in per application: Linear (two-way, with webhooks),
  Alerting, HTTP ingest, Release tracking with source maps, and Access-log
  analytics. A disabled plugin's routes return `404` and its UI is hidden.
- **First-hand error capture**: `POST ?api=ingest`, a Laravel auto-reporter, a
  single-file PHP client, and a browser snippet.
- **JSON API** for everything the dashboard does, with an optional API token, a
  cross-origin guard, and role-based authorization (owner, editor, viewer).
- **Laravel adapter** for Laravel 10, 11, 12, and 13 — mounts the dashboard and
  API inside a host app, gated by the host's own auth, with `log-lens:import`,
  `log-lens:sync`, and `log-lens:linear-sync` commands.
- **Agent skills** (`skills/log-lens-issues-*`) for triaging and fixing issues
  through the API.
