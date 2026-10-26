<?php

declare(strict_types=1);

/**
 * Capture the response fixtures the unit suite replays, from a real Octonomy.
 *
 * Usage (normally through `make fixtures`, which boots a fresh harness first and
 * removes it afterwards):
 *
 *   php scripts/capture-fixtures.php [output-dir]
 *
 * It reads the credentials scripts/octonomy-harness.sh writes, drives every
 * resource group on /api/v1 and /api/v2, and writes one JSON file per exchange to
 * tests/fixtures/captured/<api_version>/<group>/<operation>.json plus a
 * manifest.json naming the server image and each file's hash.
 *
 * WHY THIS EXISTS. Every unit test once replayed a body written by hand from the
 * OpenAPI spec, which omits both response envelopes, so the suite agreed with the
 * code instead of the server and stayed green through a bug that broke six of ten
 * methods (#6). Response bodies now come from here and nowhere else.
 *
 * Raw HTTP through PHP streams, never the SDK and never Guzzle: like the harness
 * seeding with curl, a defect in the code under test must not be able to shape the
 * fixtures meant to catch it. Every request asserts the status a conforming
 * server returns, so a changed contract stops the capture instead of being
 * recorded as the new truth.
 *
 * It WRITES (creates, updates, deactivates), so it must run against a harness
 * that is torn down afterwards: the integration suite reads exact row counts.
 *
 * NORMALIZATION. A fresh database mints new ids and timestamps on every run, which
 * would turn a refresh into a diff of every line. Volatile values are replaced
 * with stable stand-ins of the same format, so a refresh shows only what the
 * server changed:
 *
 *   - each UUID becomes 00000000-0000-4000-8000-<n>, numbered by first appearance
 *   - each request id (req_<32 hex>) becomes req_<n>, numbered the same way
 *   - each ISO 8601 timestamp becomes 2026-01-01T00:00:00 plus <rank> seconds,
 *     ranked chronologically, keeping its fraction length and zone suffix
 *   - the harness base URL becomes http://127.0.0.1:8000, its default
 *
 * Everything else, including key order, null-versus-absent keys and {} versus
 * [], is what the server sent. The numbering is deterministic because every list
 * read here has a total order with no ties (the server breaks ties by id, which
 * is random, so a read that ties would reorder between runs).
 */

final class CaptureFailed extends \RuntimeException {}

final class FixtureCapture
{
    /**
     * The identity fixtures must be captured with. Another tenant or application
     * name would be a diff on every line that says nothing about the server.
     */
    private const IDENTITY = [
        'OCTONOMY_TEST_TENANT_ID' => 'harness-tenant',
        'OCTONOMY_TEST_APPLICATION_ID' => 'harness-app',
        'OCTONOMY_TEST_NAMESPACE_TYPE' => 'merchant',
        'OCTONOMY_TEST_NAMESPACE_A_ID' => 'harness-merchant-a',
        'OCTONOMY_TEST_NAMESPACE_B_ID' => 'harness-merchant-b',
    ];

    private const CANONICAL_BASE_URL = 'http://127.0.0.1:8000';

    private const UUID = '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/';

    /** Stand-ins are already stable; a literal like the not-found probe keeps its value. */
    private const UUID_STAND_IN_PREFIX = '00000000-0000-4000-8000-';

    private const REQUEST_ID = '/req_[0-9a-f]{32}/';

    private const TIMESTAMP = '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(\.\d+)?(Z|[+-]\d{2}:\d{2})$/';

    private const TIMESTAMP_BASE = '2026-01-01T00:00:00';

    /** An id no row has, for the 404 probe. */
    private const MISSING_ID = '00000000-0000-4000-8000-000000000000';

    /** A bearer token the server has never issued. */
    private const UNKNOWN_TOKEN = 'octo_not_a_real_token';

    /** @var list<array{name: string, request: array<string, mixed>, response: array<string, mixed>}> */
    private array $exchanges = [];

    private string $version = 'v1';

    /** The namespace requests go to by default: null is the global partition. */
    private ?string $namespace = null;

    /**
     * @param array<string, string> $env
     */
    private function __construct(private readonly array $env) {}

    /**
     * @param list<string> $argv
     */
    public static function main(array $argv): int
    {
        $output = $argv[1] ?? dirname(__DIR__) . '/tests/fixtures/captured';
        try {
            $capture = new self(self::environment());
            $capture->captureV1();
            $capture->captureV2();
            $capture->write($output);
        } catch (CaptureFailed $e) {
            fwrite(STDERR, 'capture-fixtures: ' . $e->getMessage() . "\n");

            return 1;
        }

        return 0;
    }

    /**
     * Read and check the harness credentials.
     *
     * @return array<string, string>
     */
    private static function environment(): array
    {
        $env = [];
        $names = [
            'OCTONOMY_TEST_BASE_URL',
            'OCTONOMY_TEST_TOKEN',
            'OCTONOMY_TEST_NAMESPACE_A_TOKEN',
            'OCTONOMY_TEST_SERVER_IMAGE',
            ...array_keys(self::IDENTITY),
        ];
        foreach ($names as $name) {
            $value = getenv($name);
            if ($value === false || $value === '') {
                throw new CaptureFailed("{$name} is empty: run `make fixtures`, or source the env file `make harness-up` writes");
            }
            $env[$name] = $value;
        }
        foreach (self::IDENTITY as $name => $expected) {
            if ($env[$name] !== $expected) {
                throw new CaptureFailed("{$name} is '{$env[$name]}', but fixtures are captured as '{$expected}' so a refresh diff shows only server changes");
            }
        }
        // A fixture set must name exactly one server build, and a tag can be re-pushed.
        if (preg_match('/^[^@\s]+:(\d+\.\d+\.\d+)@sha256:[0-9a-f]{64}$/', $env['OCTONOMY_TEST_SERVER_IMAGE']) !== 1) {
            throw new CaptureFailed("OCTONOMY_TEST_SERVER_IMAGE must be <image>:<version>@sha256:<digest>, got '{$env['OCTONOMY_TEST_SERVER_IMAGE']}'");
        }

        return $env;
    }

    // --- Scenarios ---------------------------------------------------------------

    /**
     * /api/v1 sees only the global partition, so every write lands there.
     */
    private function captureV1(): void
    {
        $this->version = 'v1';
        $this->namespace = null;

        $this->captureFlow(['tag' => 'red', 'type' => 'color', 'alias' => 'scarlet', 'other' => 'green']);

        // Forbidden, not merely filtered: an exact merchant grant reaches its own
        // partition and no other, and v1 can only ask for global.
        $this->call('errors/forbidden', 403, 'GET', 'tags', ['application_id' => $this->app()], null, ['credential' => 'merchant-a']);
        // The same grant without an application cannot stand in for a tenant-wide one.
        $this->call('errors/application-mismatch', 400, 'GET', 'tags', [], null, ['credential' => 'merchant-a']);
        $this->call('errors/namespace-not-supported', 400, 'GET', 'tags', [], null, ['namespace' => $this->env['OCTONOMY_TEST_NAMESPACE_A_ID']]);
    }

    /**
     * /api/v2 writes go to merchant A, so its rows carry a populated namespace pair;
     * the global and include_global reads put null pairs beside them.
     */
    private function captureV2(): void
    {
        $this->version = 'v2';
        $this->namespace = $this->env['OCTONOMY_TEST_NAMESPACE_A_ID'];

        $this->call('vocabularies/list-global', 200, 'GET', 'vocabularies', [], null, ['namespace' => null]);
        // Before the flow creates merchant A's "Labels": the global one v1 created
        // would tie with it on (name, slug), and the server breaks that tie by a
        // random id.
        $this->call('vocabularies/list-include-global', 200, 'GET', 'vocabularies', ['include_global' => 'true']);

        $created = $this->captureFlow(['tag' => 'sale', 'type' => 'label', 'alias' => 'promo', 'other' => 'red']);

        $this->call('errors/forbidden', 403, 'GET', 'tags', [], null, [
            'credential' => 'merchant-a',
            'namespace' => $this->env['OCTONOMY_TEST_NAMESPACE_B_ID'],
        ]);

        // The PATCH bodies below run last of all, each new one after the ones
        // before it, so every id, request id and timestamp captured earlier
        // keeps its stand-in.
        //
        // An explicit null clears a nullable field (#20): Featured loses its
        // description, parent and vocabulary, and Labels its description, and
        // the response shows each field after the clear.
        $this->call('tags/update-clear', 200, 'PATCH', "tags/{$created['featured']}", [], [
            'description' => null,
            'parent_id' => null,
            'vocabulary_id' => null,
        ]);
        $this->call('vocabularies/update-clear', 200, 'PATCH', "vocabularies/{$created['labels']}", [], [
            'description' => null,
        ]);
        // An empty JSON object (#46): a PATCH of {} changes nothing, and
        // "metadata": {} empties the stored object, which Featured and Labels
        // both still carry here.
        $this->call('tags/update-nothing', 200, 'PATCH', "tags/{$created['featured']}", [], new \stdClass());
        $this->call('tags/update-empty-metadata', 200, 'PATCH', "tags/{$created['featured']}", [], [
            'metadata' => new \stdClass(),
        ]);
        $this->call('vocabularies/update-empty-metadata', 200, 'PATCH', "vocabularies/{$created['labels']}", [], [
            'metadata' => new \stdClass(),
        ]);
    }

    /**
     * Every resource group's create, get, list, update and delete, the bulk and
     * resolution shapes, and the error envelopes, in the current version and
     * partition.
     *
     * @param array{tag: string, type: string, alias: string, other: string} $seeded
     *        tag/type: a seeded tag with an alias; alias: that alias's slug;
     *        other: a second seeded tag in the same partition
     *
     * @return array{featured: string, labels: string} the ids of the tag and the
     *         vocabulary it created with every nullable field set
     */
    private function captureFlow(array $seeded): array
    {
        $app = $this->app();

        // Vocabularies.
        $this->call('vocabularies/list', 200, 'GET', 'vocabularies');
        $labels = $this->id($this->call('vocabularies/create', 201, 'POST', 'vocabularies', [], [
            'application_id' => $app,
            'name' => 'Labels',
            'slug' => 'labels',
            'description' => 'Merchandising labels',
            'metadata' => ['owner' => 'catalog'],
        ]));
        $this->call('vocabularies/get', 200, 'GET', "vocabularies/{$labels}");
        $this->call('vocabularies/update', 200, 'PATCH', "vocabularies/{$labels}", [], [
            'description' => 'Labels the merchandising team curates',
        ]);
        $retired = $this->id($this->call(null, 201, 'POST', 'vocabularies', [], [
            'application_id' => $app,
            'name' => 'Retired',
            'slug' => 'retired',
        ]));
        $this->call('vocabularies/delete', 204, 'DELETE', "vocabularies/{$retired}");

        // Tags. Featured sets every nullable field, so one row shows them all
        // populated; the seeded rows show them null (or, for parent_id and
        // vocabulary_id, absent).
        $tags = $this->call('tags/list', 200, 'GET', 'tags');
        $this->call('tags/list-empty', 200, 'GET', 'tags', ['slug' => 'no-such-tag']);
        $promotions = $this->id($this->call(null, 201, 'POST', 'tags', [], [
            'application_id' => $app,
            'name' => 'Promotions',
            'slug' => 'promotions',
            'type' => 'label',
            'vocabulary_id' => $labels,
        ]));
        $featured = $this->id($this->call('tags/create', 201, 'POST', 'tags', [], [
            'application_id' => $app,
            'name' => 'Featured',
            'slug' => 'featured',
            'type' => 'label',
            'description' => 'Hand-picked items',
            'parent_id' => $promotions,
            'vocabulary_id' => $labels,
            'metadata' => ['color' => 'red'],
        ]));
        $this->call('tags/list-page', 200, 'GET', 'tags', ['limit' => '1', 'offset' => '1']);
        $tag = $this->rowId($tags, $seeded['tag']);
        $other = $this->rowId($tags, $seeded['other']);

        // Assignments: a new one is 201, the same one again is 200.
        $assignment = [
            'application_id' => $app,
            'tag_id' => $featured,
            'resource_type' => 'product',
            'resource_id' => 'sku-003',
            'assigned_by' => 'octonomy-php-capture',
        ];
        $this->call('tag-assignments/create', 201, 'POST', 'tag-assignments', [], $assignment);
        $this->call('tag-assignments/create-existing', 200, 'POST', 'tag-assignments', [], $assignment);

        // Read and update Featured once it is in use, so usage_count is not 0.
        $this->call('tags/get', 200, 'GET', "tags/{$featured}");
        $this->call('tags/update', 200, 'PATCH', "tags/{$featured}", [], [
            'description' => 'Hand-picked by the merchandising team',
            'metadata' => ['color' => 'red', 'rank' => 1],
        ]);
        $this->call('tags/aliases', 200, 'GET', "tags/{$tag}/aliases");
        $this->call('tags/resources', 200, 'GET', "tags/{$tag}/resources");
        $this->call('tags/audit-logs', 200, 'GET', "tags/{$featured}/audit-logs");

        // Bulk: Featured is already on sku-003 (existing), the other tag and the
        // alias's tag are not (created).
        $this->call('tag-assignments/bulk-assign', 200, 'POST', 'tag-assignments/bulk-assign', [], [
            'application_id' => $app,
            'resource_type' => 'product',
            'resource_id' => 'sku-003',
            'tag_ids' => [$featured, $other],
            'alias_slugs' => [$seeded['alias']],
            'assigned_by' => 'octonomy-php-capture',
        ]);
        $this->call('tag-assignments/bulk-remove', 200, 'POST', 'tag-assignments/bulk-remove', [], [
            'application_id' => $app,
            'resource_type' => 'product',
            'resource_id' => 'sku-003',
            'tag_ids' => [$other],
        ]);
        $this->call('tag-assignments/delete', 204, 'DELETE', 'tag-assignments', [], [
            'application_id' => $app,
            'tag_id' => $featured,
            'resource_type' => 'product',
            'resource_id' => 'sku-003',
        ]);

        // Resources. sku-004 gets the other tag first, so the captured
        // replacement both creates and removes.
        $this->call('resources/tags', 200, 'GET', 'resources/product/sku-001/tags', ['application_id' => $app]);
        $replace = ['application_id' => $app, 'tag_ids' => [$other], 'assigned_by' => 'octonomy-php-capture'];
        $this->call(null, 200, 'POST', 'resources/product/sku-004/tags', [], $replace);
        $this->call('resources/replace', 200, 'POST', 'resources/product/sku-004/tags', [], ['tag_ids' => [$featured]] + $replace);
        $this->call('resources/audit-logs', 200, 'GET', 'resources/product/sku-004/audit-logs');

        // Aliases.
        $this->call('tag-aliases/list', 200, 'GET', 'tag-aliases');
        $staffPick = $this->id($this->call('tag-aliases/create', 201, 'POST', 'tag-aliases', [], [
            'application_id' => $app,
            'tag_id' => $featured,
            'name' => 'Staff Pick',
            'slug' => 'staff-pick',
            'metadata' => ['source' => 'editorial'],
        ]));
        $this->call('tag-aliases/get', 200, 'GET', "tag-aliases/{$staffPick}");
        $this->call('tag-aliases/update', 200, 'PATCH', "tag-aliases/{$staffPick}", [], ['name' => 'Staff Picks']);
        $this->call('tag-aliases/delete', 204, 'DELETE', "tag-aliases/{$staffPick}");

        // Resolution, by a tag's own slug and by an alias.
        $this->call('tag-resolution/tag', 200, 'GET', 'tag-resolution', ['slug' => $seeded['tag'], 'type' => $seeded['type']]);
        $this->call('tag-resolution/alias', 200, 'GET', 'tag-resolution', ['slug' => $seeded['alias']]);

        // Deletion is deactivation: the row stays, and can no longer be assigned.
        $discontinued = $this->id($this->call(null, 201, 'POST', 'tags', [], [
            'application_id' => $app,
            'name' => 'Discontinued',
            'slug' => 'discontinued',
            'type' => 'label',
        ]));
        $this->call('tags/delete', 204, 'DELETE', "tags/{$discontinued}");
        $this->call('errors/inactive-tag', 400, 'POST', 'tag-assignments', [], ['tag_id' => $discontinued] + $assignment);

        $this->call('audit-logs/list', 200, 'GET', 'audit-logs', ['limit' => '3']);

        // Error envelopes, one per code the SDK maps to an exception class.
        $this->call('errors/not-found', 404, 'GET', 'tags/' . self::MISSING_ID);
        $this->call('errors/conflict', 409, 'POST', 'tags', [], [
            'application_id' => $app,
            'name' => 'Featured',
            'slug' => 'featured',
            'type' => 'label',
        ]);
        $this->call('errors/validation-error', 400, 'POST', 'tags', [], [
            'application_id' => $app,
            'name' => '',
            'slug' => 'Not A Slug',
            'type' => 'label',
        ]);
        // 403, not 401: the server sends no WWW-Authenticate challenge, and DRF
        // downgrades an unauthenticated request without one to 403.
        $this->call('errors/authentication-required', 403, 'GET', 'tags', [], null, ['credential' => 'unknown']);
        $this->call('errors/tenant-mismatch', 400, 'GET', 'tags', [], null, ['tenant' => 'harness-other-tenant']);

        return ['featured' => $featured, 'labels' => $labels];
    }

    // --- HTTP ----------------------------------------------------------------------

    /**
     * Send one request, require the status a conforming server returns, and
     * return the decoded body (objects stay \stdClass, so {} and [] survive).
     *
     * With a $name the exchange becomes the fixture <version>/<name>. Without one
     * it is a setup step: its response is used but not kept.
     *
     * The server requires every namespaced request to name its application, so a
     * namespaced v2 request whose body does not carry application_id gets it in
     * the query.
     *
     * @param array<string, string> $query
     * @param array<string, mixed>|\stdClass|null $body \stdClass for an empty object, which an
     *                                                   empty array would encode as []
     * @param array{credential?: 'wildcard'|'merchant-a'|'unknown', tenant?: string, namespace?: string|null} $as
     */
    private function call(
        ?string $name,
        int $expect,
        string $method,
        string $path,
        array $query = [],
        array|\stdClass|null $body = null,
        array $as = [],
    ): mixed {
        $credential = $as['credential'] ?? 'wildcard';
        $namespace = array_key_exists('namespace', $as) ? $as['namespace'] : $this->namespace;
        if ($this->version === 'v2' && $namespace !== null && !(is_array($body) && isset($body['application_id'])) && !isset($query['application_id'])) {
            $query = ['application_id' => $this->app()] + $query;
        }

        $headers = ['X-Tenant-ID' => $as['tenant'] ?? $this->env['OCTONOMY_TEST_TENANT_ID']];
        if ($namespace !== null) {
            $headers['X-Namespace-Type'] = $this->env['OCTONOMY_TEST_NAMESPACE_TYPE'];
            $headers['X-Namespace-ID'] = $namespace;
        }
        $token = match ($credential) {
            'wildcard' => $this->env['OCTONOMY_TEST_TOKEN'],
            'merchant-a' => $this->env['OCTONOMY_TEST_NAMESPACE_A_TOKEN'],
            'unknown' => self::UNKNOWN_TOKEN,
        };

        $target = "/api/{$this->version}/{$path}";
        $url = rtrim($this->env['OCTONOMY_TEST_BASE_URL'], '/') . $target . ($query === [] ? '' : '?' . http_build_query($query));
        $json = $body === null ? null : self::encode($body);
        [$status, $responseHeaders, $raw] = $this->send($method, $url, ['Authorization' => "Bearer {$token}"] + $headers, $json);

        $label = "{$method} {$target}" . ($name === null ? '' : " ({$this->version}/{$name})");
        if ($status !== $expect) {
            throw new CaptureFailed("{$label} returned HTTP {$status}, want {$expect}: {$raw}");
        }
        $decoded = null;
        if ($raw !== '') {
            try {
                $decoded = json_decode($raw, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            } catch (\JsonException $e) {
                throw new CaptureFailed("{$label} returned a body that is not JSON ({$e->getMessage()}): {$raw}");
            }
        }

        if ($name !== null) {
            $kept = [];
            foreach (['Content-Type', 'X-Request-ID'] as $header) {
                if (isset($responseHeaders[strtolower($header)])) {
                    $kept[$header] = $responseHeaders[strtolower($header)];
                }
            }
            $this->exchanges[] = [
                'name' => "{$this->version}/{$name}",
                'request' => [
                    'method' => $method,
                    'path' => $target,
                    'query' => (object) $query,
                    // Which token sent it, never the token itself.
                    'credential' => $credential,
                    'headers' => (object) $headers,
                    'body' => $json === null ? null : json_decode($json, false, 512, JSON_THROW_ON_ERROR),
                ],
                'response' => [
                    'status' => $status,
                    'headers' => (object) $kept,
                    'body' => $decoded,
                ],
            ];
        }

        return $decoded;
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{int, array<string, string>, string} status, lower-cased headers, raw body
     */
    private function send(string $method, string $url, array $headers, ?string $json): array
    {
        if ($json !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        $lines = [];
        foreach ($headers as $header => $value) {
            $lines[] = "{$header}: {$value}";
        }
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $lines),
            'content' => $json ?? '',
            // Read the body of a 4xx instead of failing: the error envelopes are fixtures too.
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 30,
        ]]);

        $stream = @fopen($url, 'r', false, $context);
        if ($stream === false) {
            throw new CaptureFailed("{$method} {$url} got no response; is the harness up?");
        }
        $raw = stream_get_contents($stream);
        $meta = stream_get_meta_data($stream);
        fclose($stream);

        /** @var list<string> $lines */
        $lines = $meta['wrapper_data'] ?? [];
        $status = 0;
        $responseHeaders = [];
        foreach ($lines as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match) === 1) {
                $status = (int) $match[1];
                $responseHeaders = [];
            } elseif (str_contains($line, ':')) {
                [$header, $value] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($header))] = trim($value);
            }
        }

        return [$status, $responseHeaders, $raw === false ? '' : $raw];
    }

    private function app(): string
    {
        return $this->env['OCTONOMY_TEST_APPLICATION_ID'];
    }

    /**
     * The id of the single resource a response wraps.
     */
    private function id(mixed $body): string
    {
        $id = $body instanceof \stdClass && $body->data instanceof \stdClass ? ($body->data->id ?? null) : null;
        if (!is_string($id)) {
            throw new CaptureFailed('expected a {"data":{"id":...}} body, got ' . self::encode($body));
        }

        return $id;
    }

    /**
     * The id of the row with this slug in a list response.
     */
    private function rowId(mixed $body, string $slug): string
    {
        $rows = $body instanceof \stdClass && is_array($body->data ?? null) ? $body->data : [];
        foreach ($rows as $row) {
            if ($row instanceof \stdClass && ($row->slug ?? null) === $slug && is_string($row->id ?? null)) {
                return $row->id;
            }
        }
        throw new CaptureFailed("the seeded corpus has no {$slug} row in {$this->version}: " . self::encode($body));
    }

    // --- Normalization and output ----------------------------------------------------

    private function write(string $output): void
    {
        $exchanges = $this->normalize($this->exchanges);

        $encoded = [];
        foreach ($exchanges as $exchange) {
            $file = "{$exchange['name']}.json";
            if (isset($encoded[$file])) {
                throw new CaptureFailed("two exchanges are both named {$exchange['name']}");
            }
            $encoded[$file] = self::encode(['request' => $exchange['request'], 'response' => $exchange['response']], true) . "\n";
        }
        ksort($encoded);

        // The tokens are never written, but a server that echoed one back would
        // put it in a body; refuse rather than commit a credential.
        foreach ($encoded as $file => $contents) {
            foreach (['OCTONOMY_TEST_TOKEN', 'OCTONOMY_TEST_NAMESPACE_A_TOKEN'] as $secret) {
                if (str_contains($contents, $this->env[$secret])) {
                    throw new CaptureFailed("{$file} contains the value of {$secret}; nothing was written");
                }
            }
        }

        $image = $this->env['OCTONOMY_TEST_SERVER_IMAGE'];
        preg_match('/:(\d+\.\d+\.\d+)@/', $image, $version);
        $manifest = [
            'server' => ['version' => $version[1] ?? '', 'image' => $image],
            'generator' => 'scripts/capture-fixtures.php',
            'fixtures' => array_map(static fn(string $contents): string => 'sha256:' . hash('sha256', $contents), $encoded),
        ];

        // Build the new corpus as a sibling directory, then swap whole
        // directories, so the corpus is never part one capture and part another.
        // The swap is two renames, not one atomic step (PHP has no renameat2
        // exchange): an interrupt between them leaves the old corpus intact at
        // .previous, and the next run puts it back before doing anything else.
        $output = rtrim($output, '/');
        $staging = "{$output}.staging";
        $previous = "{$output}.previous";
        if (is_dir($previous)) {
            if (is_dir($output)) {
                self::remove($previous);
            } elseif (!rename($previous, $output)) {
                throw new CaptureFailed("an earlier capture was interrupted mid-swap and {$previous} could not be moved back to {$output}");
            }
        }
        self::remove($staging);
        foreach ($encoded + ['manifest.json' => self::encode($manifest, true) . "\n"] as $file => $contents) {
            $path = "{$staging}/{$file}";
            if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0o777, true)) {
                throw new CaptureFailed('could not create ' . dirname($path));
            }
            if (file_put_contents($path, $contents) === false) {
                throw new CaptureFailed("could not write {$path}");
            }
        }
        if (is_dir($output) && !rename($output, $previous)) {
            self::remove($staging);
            throw new CaptureFailed("could not move the current corpus aside to {$previous}; it is unchanged");
        }
        if (!rename($staging, $output)) {
            if (is_dir($previous)) {
                rename($previous, $output);
            }
            throw new CaptureFailed("could not move the new corpus into {$output}; the previous one was put back");
        }
        self::remove($previous);

        fwrite(STDERR, sprintf("capture-fixtures: wrote %d fixtures from %s to %s\n", count($encoded), $image, $output));
    }

    /**
     * Replace the volatile values described in the file header.
     *
     * @param list<array{name: string, request: array<string, mixed>, response: array<string, mixed>}> $exchanges
     *
     * @return list<array{name: string, request: mixed, response: mixed}>
     */
    private function normalize(array $exchanges): array
    {
        $uuids = [];
        $requestIds = [];
        $instants = [];
        foreach ($exchanges as $exchange) {
            self::eachString([$exchange['request'], $exchange['response']], static function (string $value) use (&$uuids, &$requestIds, &$instants): void {
                if (preg_match(self::TIMESTAMP, $value) === 1) {
                    $instants[self::instant($value)] = true;

                    return;
                }
                preg_match_all(self::UUID, $value, $found);
                foreach ($found[0] as $uuid) {
                    if (!str_starts_with($uuid, self::UUID_STAND_IN_PREFIX) && !isset($uuids[$uuid])) {
                        $uuids[$uuid] = self::UUID_STAND_IN_PREFIX . sprintf('%012x', count($uuids) + 1);
                    }
                }
                preg_match_all(self::REQUEST_ID, $value, $found);
                foreach ($found[0] as $requestId) {
                    $requestIds[$requestId] ??= sprintf('req_%032x', count($requestIds) + 1);
                }
            });
        }

        // Ranked chronologically, not by first appearance, so a later event keeps
        // a later stand-in and created_at <= updated_at survives.
        ksort($instants, SORT_STRING);
        $rank = array_flip(array_keys($instants));
        $base = new \DateTimeImmutable(self::TIMESTAMP_BASE, new \DateTimeZone('UTC'));
        $baseUrl = rtrim($this->env['OCTONOMY_TEST_BASE_URL'], '/');

        $replace = static function (string $value) use ($uuids, $requestIds, $rank, $base, $baseUrl): string {
            if (preg_match(self::TIMESTAMP, $value, $parts) === 1) {
                $standIn = $base->modify('+' . $rank[self::instant($value)] . ' seconds')->format('Y-m-d\TH:i:s');
                $fraction = $parts[2] === '' ? '' : '.' . str_repeat('0', strlen($parts[2]) - 1);

                return $standIn . $fraction . $parts[3];
            }
            $value = strtr($value, $uuids);
            $value = strtr($value, $requestIds);

            return str_replace($baseUrl, self::CANONICAL_BASE_URL, $value);
        };

        $normalized = [];
        foreach ($exchanges as $exchange) {
            $normalized[] = [
                'name' => $exchange['name'],
                'request' => self::mapStrings($exchange['request'], $replace),
                'response' => self::mapStrings($exchange['response'], $replace),
            ];
        }

        return $normalized;
    }

    /**
     * A timestamp as a sortable UTC instant with microseconds. Every timestamp
     * the server emits is UTC; any other offset would need its stand-in shifted,
     * so it is refused instead.
     */
    private static function instant(string $timestamp): string
    {
        if (!str_ends_with($timestamp, 'Z') && !str_ends_with($timestamp, '+00:00')) {
            throw new CaptureFailed("timestamp {$timestamp} is not UTC");
        }

        return (new \DateTimeImmutable($timestamp))->format('Y-m-d\TH:i:s.u');
    }

    /**
     * Call $visit on every string value, depth first in key order.
     *
     * @param callable(string): void $visit
     */
    private static function eachString(mixed $value, callable $visit): void
    {
        if (is_string($value)) {
            $visit($value);
        } elseif (is_array($value) || $value instanceof \stdClass) {
            foreach ((array) $value as $item) {
                self::eachString($item, $visit);
            }
        }
    }

    /**
     * Rebuild $value with every string passed through $map, keeping objects as
     * objects and lists as lists.
     *
     * @param callable(string): string $map
     */
    private static function mapStrings(mixed $value, callable $map): mixed
    {
        if (is_string($value)) {
            return $map($value);
        }
        if ($value instanceof \stdClass) {
            $out = new \stdClass();
            foreach (get_object_vars($value) as $key => $item) {
                $out->{$key} = self::mapStrings($item, $map);
            }

            return $out;
        }
        if (is_array($value)) {
            return array_map(static fn(mixed $item): mixed => self::mapStrings($item, $map), $value);
        }

        return $value;
    }

    private static function encode(mixed $value, bool $pretty = false): string
    {
        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

        return json_encode($value, $pretty ? $flags | JSON_PRETTY_PRINT : $flags);
    }

    private static function remove(string $path): void
    {
        if (is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $entries = scandir($path);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::remove("{$path}/{$entry}");
            }
        }
        rmdir($path);
    }
}

exit(FixtureCapture::main($argv));
