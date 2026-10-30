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

### Scope
- E02 defines the contracts and public driver-resolution API only.
- No concrete storage driver, persistence layer, upload engine, chunk orchestration,
  retry worker, or Telegram integration is implemented by E02.
