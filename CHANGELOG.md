# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Client foundation targeting the Octonomy REST **v1** API (`/api/v1`): `Client` with
  options-array/`Config` construction and validation, a Guzzle-backed `Transport` that sets
  `Authorization`, `X-Tenant-ID`, optional `X-Actor-ID`, `Accept`, and `User-Agent`, and an
  injectable `http_client`. Guzzle is the only runtime dependency.
- Response envelopes: single-resource methods unwrap the server's `{"data": {...}}`, and list methods
  decode `{data, pagination}` into a generic `ResultList<T>` and `Pagination` (#6).
- Typed exception hierarchy: `ApiException` (with `statusCode`, `errorCode`, `details`, `requestId`)
  and `NotFoundException`, `ConflictException`, `ValidationException`, `AuthenticationException`,
  `ForbiddenException`, decoded from the `{error:{code,message,details,request_id}}` envelope; plus
  `ConfigurationException`, `TransportException`, and `MalformedResponseException`, which is thrown
  when a 2xx body lacks its envelope instead of hydrating an empty model (#6).
- `VocabularyService`: create, get, list, update, delete.
- `TagService`: create, get, list (full filter set), update, delete.
- `RequestOptions::withActor()`, readonly response models, and `*Create`/`*Update`/`*ListParams`
  input DTOs.
- Runnable `examples/quickstart.php` and a vendored `docs/openapi.yaml` contract reference (server
  `1.0.0`).

[Unreleased]: https://github.com/octoverse-id/octonomy-php/commits/main
