# Roadmap

The foundation (transport, auth, exceptions, pagination) and every resource group are implemented:
vocabularies, tags, tag aliases, tag resolution, tag assignments, resource tags and audit logs (#23),
and the health probes (#24), which have a transport of their own. No resource is queued. The recipe
below is for an endpoint a later server release adds.

## How to add a resource (the recipe)

Copy `src/Resource/TagService.php` (+ its models and `tests/Resource/TagServiceTest.php`) as the
template, then:

1. Read the matching schema(s) in [`openapi-v2.yaml`](openapi-v2.yaml) (and
   [`openapi.yaml`](openapi.yaml) for v1), minding the known-divergence note at the top of each.
2. Add `Model/<Resource>.php` (readonly, `::fromArray($data, $apiVersion)` reading each field through
   the strict `Internal\Json` reader for its declared type, plus `Json::namespaceField()` if v2 adds
   the namespace pair to it), the `*Create`/`*Update` input DTOs
   (`toArray()` omitting nulls), and any `*ListParams` (`toQuery()`).
3. Add `Resource/<Resource>Service.php`, building every path with `Internal\Path::of()`, with methods
   that take inputs first and `?RequestOptions $options = null` last, delegating to
   `Transport::one()` (single resource), `many()` (list) or `none()` (204), passing the model's
   `fromArray(...)` to the first two. Give each list method a `walk*()` beside it that hands the same
   path, hydrator and query to `Http\Walker::walk()`, with the params' offset. There is no raw-body method, by design. A model nested in another
   is read with `Json::resource()` or `Json::resources()`, which report its fields at their path.
4. Wire an accessor onto `Client`.
5. Add `MockHandler`-based tests (assert method/path/headers/query/body; cover the error envelope)
   that replay captured fixtures, adding the resource's calls to `scripts/capture-fixtures.php` and
   re-running `make fixtures` if they are not captured yet. List each response model in
   `StrictHydrationRegressionTest::MODELS`, add a `## [Unreleased]` CHANGELOG entry, and update
   [`api.md`](api.md).
6. Give each new endpoint a row in `Testing\FixtureClient::ROUTES`, naming its plain capture, and the
   same row in `FixtureClientTest::endpoints()` and its call in `sdkCalls()`, so callers' tests can
   reach it without a server. Add it to the table in [`api.md`](api.md#testing-with-fixtureclient).
