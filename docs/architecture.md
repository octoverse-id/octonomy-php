# Architecture

`octonomy-php` is a thin, hand-written client for the Octonomy REST API (`/api/v2` by default,
`/api/v1` on request), built on Guzzle. The
design goal is that an agent (or human) can add a new resource by copying an existing service file and
changing the types and paths.

## Layers

| File / namespace | Responsibility |
| ---------------- | -------------- |
| `Client` | Entry point; `const VERSION`; validates config; exposes `vocabularies()` / `tags()` and `apiVersion()`. |
| `Config` | Validated configuration (base URL, token, tenant, actor, http client, timeout, user agent, api_version). |
| `Http\Transport` | `one()` / `many()` / `none()`: URL building under `/api/{version}`, auth/tenant/namespace headers, the v2-only guard, JSON, status → exception, the response-envelope check, and running the service's hydrator. No method returns a raw body. |
| `ApiVersion` | `V1` / `V2` string constants, checked by `Config` (no enum, for the PHP 7.4 line). The surface a client calls, and the field set a model hydrates. |
| `NamespaceScope` | The atomic namespace type + id pair of one v2 request. |
| `RequestOptions` | Per-call options: actor (`withActor`) and namespace. |
| `Exception\*` | `OctonomyException` base; `ApiException` (+ `NotFound`/`Conflict`/`Validation`/`Authentication`/`Forbidden`); `ConfigurationException`; `TransportException`; `MalformedResponseException`. |
| `Pagination`, `ResultList` | The `{data, pagination}` list envelope. |
| `Model\*` | Response DTOs (`Vocabulary`, `Tag`) and input DTOs (`*Create`, `*Update`, `*ListParams`). |
| `Resource\*Service` | One per resource: CRUD methods delegating to `Transport`. |
| `Internal\Json` | Strict readers for decoded JSON: present-or-raise, declared type or raise, no coercion; `@internal`. |

## Request lifecycle

1. A service method calls the `Transport` method that matches the endpoint's response shape.
   `one()` and `many()` take `($method, $path, $hydrate, $query, $body, $options)`, where `$hydrate` is
   the model's `fromArray(...)`; `none()` takes the same without `$hydrate`:
   - `one()` for a single resource (create/get/update) → the model hydrated from `{"data": {...}}`;
   - `many()` for a list → a `ResultList` of models + `Pagination` from `{"data": [...], "pagination": {...}}`;
   - `none()` for a 204 (delete/deactivate) → nothing; any body is ignored.
2. `Transport` refuses a v2-only feature (a namespace, `include_global`) on a v1 client before
   anything is sent. Then it builds `base_url + /api/{version}/ + path` from the version fixed at
   construction, attaches headers (the namespace pair only when the request names one), and
   JSON-encodes the body (`http_errors` is off so the SDK owns status handling).
3. On a 2xx, `one()`/`many()` decode the body and check the envelope; a 2xx that lacks it throws
   `MalformedResponseException` instead of hydrating an empty model. Then they call
   `$hydrate($object, $apiVersion)` on each object. Hydration is strict (see below), and a field it
   rejects comes back as a `MalformedResponseException` naming the endpoint, the api_version and the
   field's path in the body (`data.usage_count`, `data[2].slug`, `pagination.count`). A non-JSON 2xx
   throws `TransportException`. On a non-2xx, `ApiException::fromResponse()` decodes the
   `{error:{...}}` envelope leniently and returns the most specific subclass. Guzzle network failures
   are wrapped in `TransportException`.

```
Caller ─▶ Service::method ─▶ Transport::one / many / none ─▶ Guzzle ─▶ Octonomy /api/{v1|v2}
                                   │
                                   ├─ v1 + namespace or include_global → throw LogicException, nothing sent
                                   ├─ 2xx, envelope ok      → data object → Model::fromArray($object, $apiVersion)
                                   │                         → rows        → ResultList<Model>
                                   ├─ 2xx, envelope missing → throw MalformedResponseException
                                   ├─ 2xx, field rejected   → throw MalformedResponseException (endpoint, api_version, field)
                                   └─ !2xx                  → throw ApiException subclass (statusCode, errorCode, details, requestId)
```

Services never see a raw body. That is deliberate: the server wraps every single-resource response,
and a service that hydrated the body directly would get a zero-valued model with no error (issue #6).

## Strict hydration

A model's constructor signature is its field registry. `fromArray($data, $apiVersion)` reads each
parameter's key through an `Internal\Json` reader that matches the declared type, and every reader
requires the key to be present: nullability governs the value only, because the server always emits
its nullable fields (`octonomy/tags/serializers.py` upstream). A wrong JSON type raises; nothing is
coerced (`"5"` is not an `int`) or defaulted, and a timestamp must be RFC 3339 (`"yesterday"` raises).
`Transport` hands a model its object's members with nested objects still `\stdClass`
(`Json::members()`), so an object field such as `metadata` rejects a JSON list, which array
decoding would have made indistinguishable. `fromArray()` wraps its reads in `Json::readAs()`, so a
fault names the api_version even when a model is hydrated outside a request.
Two named rules sit on top (issue #14):

- **The namespace pair is per api_version.** v2 declares `namespace_type`/`namespace_id` on every
  namespaced resource and always sends both, null for a global row; the server strips them from v1
  (`octonomy/core/serializers.py`). So v2 requires the keys, v1 must not carry them, and on v1 the
  model reads null. `Json::namespaceField()` is the one place this lives.
- **Tag's `parent_id` and `vocabulary_id` may be absent.** The server omits them when null instead of
  sending null (each is a dotted source through a nullable relation, which DRF skips at `None`), in
  every release since 1.0.0. An absent key hydrates to null; a present one must still be a string or
  null (`Json::nullableStringOrAbsent()`).

## Conventions that keep it faithful

- **Contract reference:** `docs/openapi.yaml` is vendored from the server. Models mirror it
  field-for-field. The deliberate divergences are the two response envelopes the generated spec omits:
  the server wraps every single resource in `{data: {...}}` (`octonomy/core/responses.py` upstream)
  and every list in `{data, pagination}` (`octonomy/core/pagination.py`). The SDK follows the server,
  enforces both envelopes in `Transport`, and notes the divergence in code.
- **Immutability:** response models and input DTOs are `readonly`. Input DTOs send only the fields the
  caller set (PATCH-friendly) via `toArray()`.
- **No hidden behavior:** the client never retries, logs, or mutates global state. Retries, timeouts,
  and transport tuning belong to the injected Guzzle client.
- **One server rule, checked locally:** a v2-only feature on a v1 client throws before the request.
  `AGENTS.md` records this as the exemption from "do not encode server-side validation": the answer is
  knowable from the client's own configuration, and v1 would otherwise drop `include_global` silently.

## Multi-tenancy

Every request is scoped to one tenant via `X-Tenant-ID` (`tenant_id`, required). Tags and vocabularies
may be shared (`application_id === null`) or application-specific; assignments always carry an
`application_id`. On v2, a request may also name a merchant or sub-tenant namespace
(`NamespaceScope`, per request only). The SDK passes these through faithfully — the server enforces
isolation and namespace grants.

## Extending the client

To add a resource, follow `Resource/TagService.php`:

1. Read the matching schema(s) in `docs/openapi.yaml`.
2. Add `Model/<Resource>.php` (+ `*Create`/`*Update`/`*ListParams`) and `Resource/<Resource>Service.php`.
3. Wire the accessor onto `Client`.
4. Add `MockHandler`-based tests that replay captured fixtures, and a CHANGELOG entry.

See [roadmap.md](roadmap.md) for the queued resources.
