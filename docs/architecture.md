# Architecture

`octonomy-php` is a thin, hand-written client for the Octonomy REST **v1** API, built on Guzzle. The
design goal is that an agent (or human) can add a new resource by copying an existing service file and
changing the types and paths.

## Layers

| File / namespace | Responsibility |
| ---------------- | -------------- |
| `Client` | Entry point; `const VERSION`; validates config; exposes `vocabularies()` / `tags()`. |
| `Config` | Validated configuration (base URL, token, tenant, actor, http client, timeout, user agent). |
| `Http\Transport` | `one()` / `many()` / `none()`: URL building under `/api/v1`, auth/tenant headers, JSON, status → exception, and the response-envelope check. No method returns a raw body. |
| `Http\Envelope` | A validated list response (`rows` + `Pagination`) returned by `Transport::many()`; `@internal`. |
| `RequestOptions` | Per-call options (`withActor`). |
| `Exception\*` | `OctonomyException` base; `ApiException` (+ `NotFound`/`Conflict`/`Validation`/`Authentication`/`Forbidden`); `ConfigurationException`; `TransportException`; `MalformedResponseException`. |
| `Pagination`, `ResultList` | The `{data, pagination}` list envelope. |
| `Model\*` | Response DTOs (`Vocabulary`, `Tag`) and input DTOs (`*Create`, `*Update`, `*ListParams`). |
| `Resource\*Service` | One per resource: CRUD methods delegating to `Transport`. |
| `Internal\Json` | Defensive helpers for reading decoded JSON; `@internal`. |

## Request lifecycle

1. A service method calls the `Transport` method that matches the endpoint's response shape, each
   taking `($method, $path, $query, $body, $options)`:
   - `one()` for a single resource (create/get/update) → the object unwrapped from `{"data": {...}}`;
   - `many()` for a list → an `Envelope` of rows + `Pagination` from `{"data": [...], "pagination": {...}}`;
   - `none()` for a 204 (delete/deactivate) → nothing; any body is ignored.
2. `Transport` builds `base_url + /api/v1/ + path`, attaches headers, and JSON-encodes the body
   (`http_errors` is off so the SDK owns status handling).
3. On a 2xx, `one()`/`many()` decode the body and check the envelope; a 2xx that lacks it throws
   `MalformedResponseException` instead of hydrating an empty model. A non-JSON 2xx throws
   `TransportException`. On a non-2xx, `ApiException::fromResponse()` decodes the `{error:{...}}`
   envelope and returns the most specific subclass. Guzzle network failures are wrapped in
   `TransportException`.

```
Caller ─▶ Service::method ─▶ Transport::one / many / none ─▶ Guzzle ─▶ Octonomy /api/v1
                                   │
                                   ├─ 2xx, envelope ok      → data object → Model::fromArray
                                   │                         → Envelope    → ResultList<Model>
                                   ├─ 2xx, envelope missing → throw MalformedResponseException
                                   └─ !2xx                  → throw ApiException subclass (statusCode, errorCode, details, requestId)
```

Services never see a raw body. That is deliberate: the server wraps every single-resource response,
and a service that hydrated the body directly would get a zero-valued model with no error (issue #6).

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

## Multi-tenancy

Every request is scoped to one tenant via `X-Tenant-ID` (`tenant_id`, required). Tags and vocabularies
may be shared (`application_id === null`) or application-specific; assignments always carry an
`application_id`. The SDK passes these through faithfully — the server enforces isolation.

## Extending the client

To add a resource, follow `Resource/TagService.php`:

1. Read the matching schema(s) in `docs/openapi.yaml`.
2. Add `Model/<Resource>.php` (+ `*Create`/`*Update`/`*ListParams`) and `Resource/<Resource>Service.php`.
3. Wire the accessor onto `Client`.
4. Add `MockHandler`-based tests that replay captured fixtures, and a CHANGELOG entry.

See [roadmap.md](roadmap.md) for the queued resources.
