# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-06-09

Initial release of the Octonomy PHP SDK. Targets the stable Octonomy REST **v1** API
(server release `1.0.0`, served under `/api/v1`). Guzzle is the only runtime dependency.

### Added
- Client foundation: `Client` with options-array/`Config` construction and validation, a
  Guzzle-backed `Transport` that sets `Authorization`, `X-Tenant-ID`, optional `X-Actor-ID`,
  `Accept`, and `User-Agent`, and an injectable `http_client`.
- Typed exception hierarchy: `ApiException` (with `statusCode`, `errorCode`, `details`, `requestId`)
  and `NotFoundException`, `ConflictException`, `ValidationException`, `AuthenticationException`,
  `ForbiddenException`, plus `ConfigurationException` and `TransportException`, decoded from the
  `{error:{code,message,details,request_id}}` envelope.
- Pagination: generic `ResultList<T>` and `Pagination` decoding the `{data, pagination}` envelope.
- `VocabularyService`: create, get, list, update, delete.
- `TagService`: create, get, list (full filter set), update, delete.
- `RequestOptions::withActor()`, readonly response models, and `*Create`/`*Update`/`*ListParams`
  input DTOs.
- Runnable `examples/quickstart.php` and a vendored `docs/openapi.yaml` contract reference.

[Unreleased]: https://github.com/octoverse-id/octonomy-php/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/octoverse-id/octonomy-php/releases/tag/v0.1.0
