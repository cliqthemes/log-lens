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
  path. An existing SQLite database predating the migration system is bridged
  automatically on first connect.
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

### Notes for anyone already running Log Lens from source

- **Issue fingerprints change.** The culprit stack frame used to be the first
  frame containing `/app/`, which is the framework's own directory on the most
  common Laravel container layout — so the culprit was routinely reported as a
  framework internal. It is now the first frame outside `/vendor/` and
  `/node_modules/`. New occurrences of an affected error open a new issue rather
  than appending to the old one; nothing is lost, and a reindex regroups the
  existing occurrences under the corrected fingerprints.
- **Horizon console logs now parse.** A `horizon*.log` holding what
  `horizon`/`queue:work` print to the console previously ingested to nothing.
  Re-import those files to pick up their history.
- **Postgres and MySQL installs should be checked for cross-application row
  bleed before upgrading.** The path-derived tenant fallback could collapse
  several applications into one schema or database. SQLite installs are
  unaffected — file-per-application was always isolated.
