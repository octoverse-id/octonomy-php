# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Client foundation targeting the Octonomy REST API: `Client` with
  options-array/`Config` construction and validation, a Guzzle-backed `Transport` that sets
  `Authorization`, `X-Tenant-ID`, optional `X-Actor-ID`, `Accept`, and `User-Agent`, and an
  injectable `http_client`. Guzzle is the only runtime dependency.
- `api_version` option (`Config::$apiVersion`, `Client::apiVersion()`): the client calls **`/api/v2`**
  by default, or `/api/v1` with `'api_version' => 'v1'` for an Octonomy server older than 2.0.0. It is
  set at construction only and never per request, and any other value throws `ConfigurationException`
  when the client is built (#15).
- `NamespaceScope`, an atomic merchant/sub-tenant namespace type + id pair passed per request as
  `RequestOptions(namespace: ...)` and sent as `X-Namespace-Type` / `X-Namespace-ID`. There is no
  client-wide namespace. A blank type or id throws `InvalidArgumentException` (#15).
- `includeGlobal` on `TagListParams` and `VocabularyListParams` (`include_global`, v2), appended as the
  last parameter so no existing one moves (#15).
- A client-side guard: a namespace or `include_global` on a v1 client throws `LogicException` before
  any request is sent, the one server rule the SDK checks locally (`AGENTS.md` exemption).
  `Tag::$usageCount` is documented as tenant-wide on v1 and limited to what the request can see on v2
  (#15).
- Response envelopes: single-resource methods unwrap the server's `{"data": {...}}`, and list methods
  decode `{data, pagination}` into a generic `ResultList<T>` and `Pagination` (#6).
- Strict hydration: every field a model declares for the response's api_version must be present as a
  key, nullable fields included, and have its declared JSON type. A missing key, a wrong type (`"5"`
  for an integer, a list where an object is declared), or an unparseable timestamp throws `MalformedResponseException` naming the
  endpoint, the api_version and the field (`$field`, e.g. `data.usage_count`) instead of hydrating a
  zero value or coercing one. Tag's `parent_id` and `vocabulary_id`, which the server omits when null,
  are the named exception (#14).
- `ApiVersion::V1` / `ApiVersion::V2`, and the v2 namespace pair on `Tag` and `Vocabulary`
  (`namespaceType`, `namespaceId`; null on v1, which sees only the global partition). `fromArray()`
  takes the api_version the data was read as (#14).
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
