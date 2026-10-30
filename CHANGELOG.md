# Changelog

All notable changes to Storix are documented in this file.

## Unreleased

### Added
- E01 Composer package foundation for PHP 8.5 and Laravel 13.
- Laravel package auto-discovery and publishable configuration.
- E01 PHPUnit and Orchestra Testbench foundation with GitHub Actions CI.
- E02 typed storage request/result DTOs and object metadata.
- E02 driver capability model with validated chunk and concurrency limits.
- E02 provider-neutral storage driver contract.
- E02 stable error codes and typed package exceptions.
- E02 driver registry/manager, injectable Storix manager, and facade.
- Unit and feature tests for capabilities, driver resolution, and facade bindings.
- E03 reversible migrations for directories, files, transfers, and chunks.
- E03 UUID-backed Eloquent models, relationships, and scoped repository contracts/implementations.
- E03 normalized idempotency scope, blank-key validation, and transfer state machine with guarded transitions.
- E03 scoped child-directory lookups, case-insensitive directory uniqueness, and host-compatible tenant/owner identifier storage.
- E03 repository guards prevent direct state-machine bypasses and normalize idempotency keys on both transfer creation paths.
- E03 persistence schema, repository, and state-transition feature tests.

### Scope
- E02 defines the contracts and public driver-resolution API only.
- E03 adds durable metadata persistence; it does not implement storage byte I/O, a concrete driver,
  upload/download orchestration, chunk transmission, retry workers, or Telegram integration.
