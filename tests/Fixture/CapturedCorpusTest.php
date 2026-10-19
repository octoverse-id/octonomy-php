<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests\Fixture;

use Octoverse\Octonomy\ApiVersion;
use Octoverse\Octonomy\Exception\ApiException;
use Octoverse\Octonomy\Model\Tag;
use Octoverse\Octonomy\Model\Vocabulary;
use Octoverse\Octonomy\Tests\SdkTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * CapturedCorpusTest holds tests/fixtures/captured to what it claims to be: the
 * unedited output of scripts/capture-fixtures.php against the server the harness
 * pins, for both api_versions. It also replays every fixture, v1 and v2, through
 * the transport and the models, so no captured shape sits unread.
 */
final class CapturedCorpusTest extends SdkTestCase
{
    private const NAMESPACE_KEYS = ['namespace_type', 'namespace_id'];

    /**
     * The operations issue #9 requires in both api_versions: every resource
     * group's create, get, list, update and delete, the bulk and resolution
     * shapes, and an error envelope per mapped code. Pinned here rather than
     * read from the manifest, so a capture that silently stops recording one
     * fails instead of shrinking the corpus.
     */
    private const REQUIRED = [
        'audit-logs/list',
        'errors/authentication-required',
        'errors/conflict',
        'errors/forbidden',
        'errors/inactive-tag',
        'errors/not-found',
        'errors/tenant-mismatch',
        'errors/validation-error',
        'resources/audit-logs',
        'resources/replace',
        'resources/tags',
        'tag-aliases/create',
        'tag-aliases/delete',
        'tag-aliases/get',
        'tag-aliases/list',
        'tag-aliases/update',
        'tag-assignments/bulk-assign',
        'tag-assignments/bulk-remove',
        'tag-assignments/create',
        'tag-assignments/create-existing',
        'tag-assignments/delete',
        'tag-resolution/alias',
        'tag-resolution/tag',
        'tags/aliases',
        'tags/audit-logs',
        'tags/create',
        'tags/delete',
        'tags/get',
        'tags/list',
        'tags/list-empty',
        'tags/list-page',
        'tags/resources',
        'tags/update',
        'vocabularies/create',
        'vocabularies/delete',
        'vocabularies/get',
        'vocabularies/list',
        'vocabularies/update',
    ];

    /**
     * What only one version can show: v1 refuses namespace headers and an
     * application-bound grant without its application; v2 lists the global
     * partition and include_global beside a namespace.
     */
    private const REQUIRED_ONLY_IN = [
        'v1' => ['errors/application-mismatch', 'errors/namespace-not-supported'],
        'v2' => ['vocabularies/list-global', 'vocabularies/list-include-global'],
    ];

    /**
     * The status a conforming server answers each operation with, where it is
     * not 200. A fixture with another status was not captured from that call.
     */
    private const STATUS = [
        'create' => 201,
        'delete' => 204,
        'errors/authentication-required' => 403,
        'errors/conflict' => 409,
        'errors/forbidden' => 403,
        'errors/not-found' => 404,
    ];

    public function testCorpusHoldsExactlyTheRequiredOperations(): void
    {
        foreach (self::REQUIRED_ONLY_IN as $version => $only) {
            $expected = array_map(static fn(string $operation): string => "{$version}/{$operation}", [...self::REQUIRED, ...$only]);
            sort($expected);
            $actual = CapturedFixture::names($version);
            sort($actual);

            self::assertSame($expected, $actual, "the {$version} corpus does not hold exactly the required operations");

            foreach ($actual as $name) {
                $operation = substr($name, strlen("{$version}/"));
                $status = self::STATUS[$operation] ?? self::STATUS[basename($operation)] ?? (str_starts_with($operation, 'errors/') ? 400 : 200);
                self::assertSame($status, CapturedFixture::load($name)->status, "{$name} was not captured from the call its name says");
            }
        }
    }

    public function testManifestNamesTheServerTheHarnessPins(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/scripts/octonomy-harness.sh');
        self::assertIsString($script);
        if (preg_match('/^HARNESS_IMAGE="\$\{OCTONOMY_HARNESS_IMAGE:-([^}]+)\}"$/m', $script, $pin) !== 1) {
            self::fail('could not read the pinned image from scripts/octonomy-harness.sh');
        }

        /** @var array{version: string, image: string} $server */
        $server = CapturedFixture::manifest()['server'];
        self::assertSame(
            $pin[1],
            $server['image'],
            'the fixtures were captured from a different server than the harness pins: run `make fixtures` after moving the pin',
        );
        self::assertStringContainsString(":{$server['version']}@sha256:", $server['image']);
    }

    public function testEveryFixtureIsTheUneditedCaptureOutput(): void
    {
        /** @var array<string, string> $listed */
        $listed = CapturedFixture::manifest()['fixtures'];

        $onDisk = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(CapturedFixture::ROOT, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            $relative = substr($file->getPathname(), strlen(CapturedFixture::ROOT) + 1);
            if ($relative === 'manifest.json') {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            self::assertIsString($contents);
            // Hashed with LF endings, so a CRLF checkout does not read as an edit.
            $onDisk[$relative] = 'sha256:' . hash('sha256', str_replace("\r\n", "\n", $contents));
        }
        ksort($onDisk);

        self::assertSame(
            array_keys($listed),
            array_keys($onDisk),
            'tests/fixtures/captured holds a file the capture did not write, or lost one it did: never add a fixture by hand',
        );
        foreach ($listed as $file => $hash) {
            self::assertSame($hash, $onDisk[$file], "{$file} was edited after capture: re-run `make fixtures` instead");
        }
    }

    public function testV1CorpusCarriesNoNamespaceFields(): void
    {
        $names = CapturedFixture::names('v1');
        self::assertNotEmpty($names);

        foreach ($names as $name) {
            self::assertSame(
                [],
                array_values(array_intersect(self::keysAnywhere(CapturedFixture::load($name)->json()), self::NAMESPACE_KEYS)),
                "{$name}: /api/v1 must never emit the namespace pair",
            );
        }
    }

    public function testV2CorpusCarriesTheNamespacePairOnEveryRow(): void
    {
        $populated = 0;
        $global = 0;
        foreach (CapturedFixture::names('v2') as $name) {
            $captured = CapturedFixture::load($name);
            // An error's details name request fields, not rows.
            if ($captured->status >= 300) {
                continue;
            }
            foreach (self::rows($captured->json()) as $row) {
                foreach (self::NAMESPACE_KEYS as $key) {
                    self::assertArrayHasKey($key, $row, "{$name}: a v2 row without {$key}");
                }
                if ($row['namespace_type'] !== null && $row['namespace_id'] !== null) {
                    ++$populated;
                } elseif ($row['namespace_type'] === null && $row['namespace_id'] === null) {
                    ++$global;
                } else {
                    self::fail("{$name}: a v2 row with half a namespace pair");
                }
            }
        }

        self::assertGreaterThan(0, $populated, 'the v2 corpus must hold rows inside a namespace');
        self::assertGreaterThan(0, $global, 'the v2 corpus must hold global rows beside them');
    }

    /**
     * Every fixture, both versions, read back through the transport method its
     * shape calls for.
     */
    #[DataProvider('everyFixture')]
    public function testTransportReadsEveryCapturedResponse(string $name): void
    {
        $captured = CapturedFixture::load($name);
        $body = $captured->json();
        $transport = $this->transportWith([$captured], self::apiVersion($name));

        if ($captured->status >= 400) {
            try {
                $transport->one('GET', 'any', self::asDecoded());
                self::fail("{$name}: expected an ApiException");
            } catch (ApiException $e) {
                self::assertSame($captured->status, $e->statusCode);
                self::assertSame($body['error']['code'], $e->errorCode);
                self::assertSame($body['error']['message'], $e->getMessage());
                self::assertSame($body['error']['request_id'], $e->requestId);
            }

            return;
        }
        if ($captured->status === 204) {
            self::assertSame('', $captured->body);
            $transport->none('DELETE', 'any');
            self::assertSame('DELETE', $this->lastRequest()->getMethod());

            return;
        }
        if (array_key_exists('pagination', $body)) {
            $page = $transport->many('GET', 'any', self::asDecoded());
            self::assertSame($body['data'], $page->data);
            self::assertSame($body['pagination']['count'], $page->pagination->count);

            return;
        }
        self::assertSame($body['data'], $transport->one('GET', 'any', self::asDecoded()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function everyFixture(): iterable
    {
        foreach (CapturedFixture::names() as $name) {
            yield $name => [$name];
        }
    }

    /**
     * Every captured tag, from either version and wherever it is nested,
     * hydrates strictly as its own api_version, with every field intact.
     */
    public function testEveryCapturedTagHydrates(): void
    {
        $rows = self::modelRows('tags', static fn(string $operation, array $data): array => match (true) {
            in_array($operation, ['tags/create', 'tags/get', 'tags/update', 'tags/list', 'tags/list-page'], true) => self::oneOrMany($data),
            str_starts_with($operation, 'tag-resolution/') => [$data['tag']],
            $operation === 'resources/replace' => $data['tags'],
            $operation === 'resources/tags' => array_column($data, 'tag'),
            default => [],
        });
        self::assertGreaterThan(20, count($rows));

        foreach ($rows as [$name, $row]) {
            $tag = Tag::fromArray($row, self::apiVersion($name));
            self::assertSame($row['id'], $tag->id, $name);
            self::assertSame($row['tenant_id'], $tag->tenantId, $name);
            self::assertSame($row['application_id'], $tag->applicationId, $name);
            self::assertNamespacePair($name, $row, $tag->namespaceType, $tag->namespaceId);
            self::assertSame($row['name'], $tag->name, $name);
            self::assertSame($row['slug'], $tag->slug, $name);
            self::assertSame($row['type'], $tag->type, $name);
            self::assertSame($row['description'], $tag->description, $name);
            // Tag's two named exceptions to key presence (see Tag::fromArray):
            // the server omits these instead of sending null. Any other key a
            // row lacks has already failed hydration above.
            self::assertSame(array_key_exists('parent_id', $row) ? $row['parent_id'] : null, $tag->parentId, $name);
            self::assertSame(array_key_exists('vocabulary_id', $row) ? $row['vocabulary_id'] : null, $tag->vocabularyId, $name);
            self::assertSame($row['metadata'], $tag->metadata, $name);
            self::assertSame($row['is_active'], $tag->isActive, $name);
            self::assertSame($row['usage_count'], $tag->usageCount, $name);
            self::assertEquals(new \DateTimeImmutable($row['created_at']), $tag->createdAt, $name);
            self::assertEquals(new \DateTimeImmutable($row['updated_at']), $tag->updatedAt, $name);
        }
    }

    public function testEveryCapturedVocabularyHydrates(): void
    {
        $rows = self::modelRows('vocabularies', static fn(string $operation, array $data): array => str_starts_with($operation, 'vocabularies/')
            ? self::oneOrMany($data)
            : []);
        self::assertGreaterThan(10, count($rows));

        foreach ($rows as [$name, $row]) {
            $vocabulary = Vocabulary::fromArray($row, self::apiVersion($name));
            self::assertSame($row['id'], $vocabulary->id, $name);
            self::assertSame($row['tenant_id'], $vocabulary->tenantId, $name);
            self::assertSame($row['application_id'], $vocabulary->applicationId, $name);
            self::assertNamespacePair($name, $row, $vocabulary->namespaceType, $vocabulary->namespaceId);
            self::assertSame($row['name'], $vocabulary->name, $name);
            self::assertSame($row['slug'], $vocabulary->slug, $name);
            self::assertSame($row['description'], $vocabulary->description, $name);
            self::assertSame($row['metadata'], $vocabulary->metadata, $name);
            self::assertSame($row['is_active'], $vocabulary->isActive, $name);
            self::assertEquals(new \DateTimeImmutable($row['created_at']), $vocabulary->createdAt, $name);
            self::assertEquals(new \DateTimeImmutable($row['updated_at']), $vocabulary->updatedAt, $name);
        }
    }

    /**
     * A v2 row's namespace pair arrives as sent, null for a global row; a v1 row
     * carries no pair, and the model reads null.
     *
     * @param array<array-key, mixed> $row
     */
    private static function assertNamespacePair(string $name, array $row, ?string $type, ?string $id): void
    {
        if (str_starts_with($name, 'v2/')) {
            self::assertSame($row['namespace_type'], $type, $name);
            self::assertSame($row['namespace_id'], $id, $name);

            return;
        }
        self::assertNull($type, $name);
        self::assertNull($id, $name);
    }

    /**
     * The api_version a fixture was captured from, which leads its name.
     */
    private static function apiVersion(string $name): string
    {
        return str_starts_with($name, 'v2/') ? ApiVersion::V2 : ApiVersion::V1;
    }

    /**
     * The model rows $extract finds in each successful fixture, as [fixture
     * name, row] pairs. $extract gets the operation ('tags/get') and the
     * response's "data" member.
     *
     * @param \Closure(string, array<array-key, mixed>): array<array-key, mixed> $extract
     *
     * @return list<array{string, array<array-key, mixed>}>
     */
    private static function modelRows(string $label, \Closure $extract): array
    {
        $rows = [];
        foreach (CapturedFixture::names() as $name) {
            $captured = CapturedFixture::load($name);
            if ($captured->status >= 300 || $captured->body === '') {
                continue;
            }
            $operation = substr($name, strlen('v1/'));
            foreach ($extract($operation, $captured->data()) as $row) {
                self::assertIsArray($row, "{$name}: a {$label} row that is not an object");
                $rows[] = ["{$name} ({$label})", $row];
            }
        }

        return $rows;
    }

    /**
     * A single resource as a one-row list, or a list's rows as they are.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private static function oneOrMany(array $data): array
    {
        return array_is_list($data) ? $data : [$data];
    }

    /**
     * Every key at any depth of a decoded body.
     *
     * @param array<array-key, mixed> $value
     *
     * @return list<string>
     */
    private static function keysAnywhere(array $value): array
    {
        $keys = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $keys[] = $key;
            }
            if (is_array($item)) {
                $keys = [...$keys, ...self::keysAnywhere($item)];
            }
        }

        return $keys;
    }

    /**
     * Every resource row in a decoded body, at any depth: an object carrying an
     * id, an assignment_id or a resource_id. Summary objects (bulk results,
     * resolution) are not rows but are searched, and the free-form "changes" and
     * "metadata" objects are skipped, since they hold whatever was written.
     *
     * @param array<array-key, mixed> $value
     *
     * @return list<array<array-key, mixed>>
     */
    private static function rows(array $value): array
    {
        $rows = [];
        if (!array_is_list($value) && array_intersect(['id', 'assignment_id', 'resource_id'], array_keys($value)) !== []) {
            $rows[] = $value;
        }
        foreach ($value as $key => $item) {
            if (is_array($item) && $key !== 'changes' && $key !== 'metadata') {
                $rows = [...$rows, ...self::rows($item)];
            }
        }

        return $rows;
    }
}
