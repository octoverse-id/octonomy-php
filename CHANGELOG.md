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
  by default, or `/api/v1` with `'api_version' => 'v1'`, which current servers still serve and a server
  older than 2.0.0 needs. It is set at construction only and never per request, and any other value
  throws `ConfigurationException` when the client is built (#15).
- `NamespaceScope`, an atomic merchant/sub-tenant namespace type + id pair passed per request as
  `RequestOptions(namespace: ...)` and sent as `X-Namespace-Type` / `X-Namespace-ID`. There is no
  client-wide namespace. A blank type or id throws `InvalidArgumentException` (#15).
- `includeGlobal` on `TagListParams` and `VocabularyListParams` (`include_global`, v2), appended as the
  last parameter so no existing one moves (#15).
- `get()`, `update()` and `delete()` on both services take `?string $applicationId`, sent as the
  `application_id` query parameter that a namespaced call needs, and `get()` takes `?bool
  $includeGlobal`. Both come before `$options`, so a call that passed options positionally now names
  them (`options:`) (#15).
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
- Error mapping for the v2 codes (#16): `FeatureDisabledException` for `namespaced_writes_disabled`
  (403) and `namespace_api_disabled` (503), so a deployment flag is no longer a `ForbiddenException`
  and an enveloped 503 is no longer a bare `ApiException`; `UnsupportedApiVersionException` for
  `namespace_not_supported` (400); `AmbiguousResolutionException` for `ambiguous_resolution` (400);
  `namespace_invalid` maps to `ValidationException` and `scope_immutable` to `ConflictException`.
  `ErrorCode` declares all 14 codes the server does, and `ApiException::classFor()` exposes the
  table. A 503 without the envelope stays a bare `ApiException`.
- `RequestContext` (api_version, method, path, query, namespace; no token, header or body) on every
  `ApiException` and `TransportException` as `$context`, and on a `MalformedResponseException` raised
  during a request (#16). `ApiException`'s and `TransportException`'s constructors take it as a
  required argument, and `MalformedResponseException`'s takes it in place of the endpoint string.
- Error messages written to one formula (#17): what happened, why when the SDK actually knows, the
  fix, and the values involved (the request, its namespace, the code, status, server message and
  request id). A deployment flag names the server setting to change; a 404 names the resource kind and
  id without suggesting where else it might be; a transport failure, an error without the envelope and
  an unknown code say that the SDK cannot tell why. `ApiException::$serverMessage` keeps the server's
  own message, unedited, which `getMessage()` now quotes rather than returns. A refused
  `Config::fromArray()` names the option keys it did not read.
- Redaction (#17): no exception carries the token, the `Authorization` header, the request body or
  `X-Actor-ID` in its message, its `RequestContext`, or the arguments of the SDK's frames in its trace,
  which `OctonomyException` removes on construction. Text a message quotes from the HTTP client or the
  server, a malformed field's value included, has the request's token, actor id and body (whole, and
  each value and nested key) replaced with `[redacted]`.
  `TransportException` covers anything the HTTP client throws and no longer chains it, since
  Guzzle's exception holds the request. The SDK encodes the body itself, once, and builds each request
  itself, sending it with an injected client's own default headers, query, body and `auth` cancelled
  (its transport options and middleware still apply), so what it sends is what it describes and
  redacts; a body that cannot be encoded throws `InvalidArgumentException` without quoting it. A non-JSON 2xx no longer chains
  the `JsonException`. A token, tenant
  id, actor id, user agent or namespace with a control character is refused up front, and again just
  before sending, without being quoted.
- `VocabularyService`: create, get, list, update, delete.
- `TagService`: create, get, list (full filter set), update, delete.
- `RequestOptions::withActor()`, readonly response models, and `*Create`/`*Update`/`*ListParams`
  input DTOs.
- Runnable `examples/quickstart.php`, and the vendored contract references `docs/openapi.yaml` (v1) and
  `docs/openapi-v2.yaml` (v2), both the server's own files at tag `v3.2.1`, each with a header listing
  where it diverges from what the server sends (#21).

[Unreleased]: https://github.com/octoverse-id/octonomy-php/commits/main
