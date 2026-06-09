# Architecture

`octonomy-php` is a thin, hand-written client for the Octonomy REST **v1** API, built on Guzzle. The
design goal is that an agent (or human) can add a new resource by copying an existing service file and
changing the types and paths.

## Layers

| File / namespace | Responsibility |
| ---------------- | -------------- |
| `Client` | Entry point; `const VERSION`; validates config; exposes `vocabularies()` / `tags()`. |
| `Config` | Validated configuration (base URL, token, tenant, actor, http client, timeout, user agent). |
| `Http\Transport` | The single `request()` method: URL building under `/api/v1`, auth/tenant headers, JSON, status → exception. |
| `RequestOptions` | Per-call options (`withActor`). |
| `Exception\*` | `OctonomyException` base; `ApiException` (+ `NotFound`/`Conflict`/`Validation`/`Authentication`/`Forbidden`); `ConfigurationException`; `TransportException`. |
| `Pagination`, `ResultList` | The `{data, pagination}` list envelope. |
| `Model\*` | Response DTOs (`Vocabulary`, `Tag`) and input DTOs (`*Create`, `*Update`, `*ListParams`). |
| `Resource\*Service` | One per resource: CRUD methods delegating to `Transport`. |
| `Internal\Json` | Defensive helpers for reading decoded JSON; `@internal`. |

## Request lifecycle

1. A service method (e.g. `TagService::create`) calls `Transport::request($method, $path, $query, $body, $options)`.
2. `Transport` builds `base_url + /api/v1/ + path`, attaches headers, and JSON-encodes the body
   (`http_errors` is off so the SDK owns status handling).
3. On a 2xx it decodes the body to an array, which the service hydrates into a model / `ResultList`.
   On a non-2xx it calls `ApiException::fromResponse()`, which decodes the `{error:{...}}` envelope and
   returns the most specific subclass. Guzzle network failures are wrapped in `TransportException`.

```
Caller ─▶ Service::method ─▶ Transport::request ─▶ Guzzle ─▶ Octonomy /api/v1
                                   │
                                   ├─ 2xx → array → Model::fromArray / ResultList
                                   └─ !2xx → throw ApiException subclass (statusCode, errorCode, details, requestId)
```

## Conventions that keep it faithful

- **Contract reference:** `docs/openapi.yaml` is vendored from the server. Models mirror it
  field-for-field. The one deliberate divergence is the list envelope: the generated spec shows a bare
  array, but the server wraps lists in `{data, pagination}` (see `octonomy/core/pagination.py`
  upstream). The SDK follows the server; the divergence is noted in code.
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
4. Add `MockHandler`-based tests and a CHANGELOG entry.

See [roadmap.md](roadmap.md) for the queued resources.
