# Changelog

All notable changes to Log Lens are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Both published packages — `cliqthemes/log-lens-core` (the standalone engine)
and `cliqthemes/log-lens` (the Laravel adapter) — are versioned together and
share this file.

## Unreleased

## 0.1.0 — 2026-08-22

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
