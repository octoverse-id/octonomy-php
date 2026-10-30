<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests\Resource;

use Octoverse\Octonomy\ErrorCode;
use Octoverse\Octonomy\Exception\ConflictException;
use Octoverse\Octonomy\Exception\MalformedResponseException;
use Octoverse\Octonomy\Exception\NotFoundException;
use Octoverse\Octonomy\Exception\ValidationException;
use Octoverse\Octonomy\Model\Assignment;
use Octoverse\Octonomy\Model\AssignmentCreate;
use Octoverse\Octonomy\Model\AssignmentRemove;
use Octoverse\Octonomy\Model\BulkAssign;
use Octoverse\Octonomy\Model\BulkRemove;
use Octoverse\Octonomy\NamespaceScope;
use Octoverse\Octonomy\RequestOptions;
use Octoverse\Octonomy\Tests\Fixture\SyntheticNegative;
use Octoverse\Octonomy\Tests\SdkTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * AssignmentService on the default surface, api_version v2. The v2 responses
 * were captured inside merchant A's namespace, so the calls that replay them
 * name that namespace too.
 *
 * inactive_tag was captured from an assignment; the other error envelopes
 * from the tag endpoints. The SDK maps an error by its status and code alone,
 * so they exercise the assignment calls the same way.
 */
final class AssignmentServiceTest extends SdkTestCase
{
    public function testCreateForATag(): void
    {
        $client = $this->clientWith([self::captured('v2/tag-assignments/create')]);
        $row = self::capturedData('v2/tag-assignments/create');

        $assignment = $client->assignments()->create(
            AssignmentCreate::forTag('harness-app', 'product', 'sku-003', $row['tag_id'], 'octonomy-php-capture'),
            self::merchantA(),
        );

        $request = $this->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/api/v2/tag-assignments', $request->getUri()->getPath());
        self::assertSame('', $request->getUri()->getQuery());
        self::assertSame('harness-merchant-a', $request->getHeaderLine('X-Namespace-ID'));
        self::assertSame([
            'application_id' => 'harness-app',
            'tag_id' => $row['tag_id'],
            'resource_type' => 'product',
            'resource_id' => 'sku-003',
            'assigned_by' => 'octonomy-php-capture',
        ], $this->lastJsonBody());

        self::assertInstanceOf(Assignment::class, $assignment);
        self::assertSame($row['id'], $assignment->id);
        self::assertSame($row['tenant_id'], $assignment->tenantId);
        self::assertSame('harness-app', $assignment->applicationId);
        self::assertSame('merchant', $assignment->namespaceType);
        self::assertSame('harness-merchant-a', $assignment->namespaceId);
        self::assertSame($row['tag_id'], $assignment->tagId);
        self::assertSame('product', $assignment->resourceType);
        self::assertSame('sku-003', $assignment->resourceId);
        self::assertSame('octonomy-php-capture', $assignment->assignedBy);
        self::assertEquals(new \DateTimeImmutable($row['assigned_at']), $assignment->assignedAt);
    }

    /**
     * Assigning again is not an error: the server answers 200 with the
     * existing assignment, and create() returns it as it returns a new one.
     */
    public function testCreatingAnExistingAssignmentReturnsIt(): void
    {
        $client = $this->clientWith([self::captured('v2/tag-assignments/create'), self::captured('v2/tag-assignments/create-existing')]);
        self::assertSame(201, self::captured('v2/tag-assignments/create')->status);
        self::assertSame(200, self::captured('v2/tag-assignments/create-existing')->status);
        $input = AssignmentCreate::forTag('harness-app', 'product', 'sku-003', 'tag_1', 'octonomy-php-capture');

        $first = $client->assignments()->create($input, self::merchantA());
        $again = $client->assignments()->create($input, self::merchantA());

        self::assertEquals($first, $again);
    }

    /**
     * Each named constructor sends its own identifier, and only that one.
     *
     * @param array<string, string> $body
     */
    #[DataProvider('createBodies')]
    public function testEachWayToNameTheTagSendsItAlone(AssignmentCreate $input, array $body): void
    {
        $client = $this->clientWith([self::captured('v2/tag-assignments/create')]);

        $client->assignments()->create($input);

        self::assertSame($body, $this->lastJsonBody());
    }

    /**
     * @return iterable<string, array{AssignmentCreate, array<string, string>}>
     */
    public static function createBodies(): iterable
    {
        $resource = ['resource_type' => 'product', 'resource_id' => 'sku-1'];
        yield 'by tag id' => [
            AssignmentCreate::forTag('cms', 'product', 'sku-1', 'tag_1'),
            ['application_id' => 'cms', 'tag_id' => 'tag_1'] + $resource,
        ];
        yield 'by alias id' => [
            AssignmentCreate::forAliasId('cms', 'product', 'sku-1', 'alias_1'),
            ['application_id' => 'cms', 'alias_id' => 'alias_1'] + $resource,
        ];
        yield 'by alias slug' => [
            AssignmentCreate::forAliasSlug('cms', 'product', 'sku-1', 'promo'),
            ['application_id' => 'cms', 'alias_slug' => 'promo'] + $resource,
        ];
        yield 'with who assigned it' => [
            AssignmentCreate::forAliasSlug('cms', 'product', 'sku-1', 'promo', 'svc-catalog'),
            ['application_id' => 'cms', 'alias_slug' => 'promo'] + $resource + ['assigned_by' => 'svc-catalog'],
        ];
    }

    public function testNamedConstructorsKeepTheirValues(): void
    {
        $byTag = AssignmentCreate::forTag('cms', 'product', 'sku-1', 'tag_1', 'svc');
        self::assertSame(['cms', 'product', 'sku-1', 'tag_1', null, null, 'svc'], [
            $byTag->applicationId,
            $byTag->resourceType,
            $byTag->resourceId,
            $byTag->tagId,
            $byTag->aliasId,
            $byTag->aliasSlug,
            $byTag->assignedBy,
        ]);
        $byAlias = AssignmentCreate::forAliasId('cms', 'product', 'sku-1', 'alias_1');
        self::assertSame([null, 'alias_1', null, null], [$byAlias->tagId, $byAlias->aliasId, $byAlias->aliasSlug, $byAlias->assignedBy]);
        $bySlug = AssignmentCreate::forAliasSlug('cms', 'product', 'sku-1', 'promo');
        self::assertSame([null, null, 'promo'], [$bySlug->tagId, $bySlug->aliasId, $bySlug->aliasSlug]);
    }

    /**
     * Deleting a tag deactivates it, and the server refuses to assign it.
     */
    public function testAssigningAnInactiveTagThrows(): void
    {
        $client = $this->clientWith([self::captured('v2/errors/inactive-tag')]);

        try {
            $client->assignments()->create(AssignmentCreate::forTag('harness-app', 'product', 'sku-003', 'tag_1'), self::merchantA());
            self::fail('expected a ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(400, $e->statusCode);
            self::assertSame(ErrorCode::INACTIVE_TAG, $e->errorCode);
            self::assertArrayHasKey('tag_id', $e->details);
            self::assertStringStartsWith('octonomy: the tag is inactive (inactive_tag, HTTP 400).', $e->getMessage());
        }
    }

    public function testCreateNotFoundThrows(): void
    {
        $client = $this->clientWith([self::captured('v2/errors/not-found')]);

        $this->expectException(NotFoundException::class);
        $client->assignments()->create(AssignmentCreate::forTag('harness-app', 'product', 'sku-003', 'tag_1'), self::merchantA());
    }

    public function testCreateConflictThrows(): void
    {
        $client = $this->clientWith([self::captured('v2/errors/conflict')]);

        $this->expectException(ConflictException::class);
        $client->assignments()->create(AssignmentCreate::forTag('harness-app', 'product', 'sku-003', 'tag_1'), self::merchantA());
    }

    /**
     * DELETE /tag-assignments names the assignment in its body; the server
     * answers 204.
     */
    public function testRemove(): void
    {
        $client = $this->clientWith([self::captured('v2/tag-assignments/delete')]);

        $client->assignments()->remove(new AssignmentRemove('harness-app', 'product', 'sku-003', 'tag_1'), self::merchantA());

        $request = $this->lastRequest();
        self::assertSame('DELETE', $request->getMethod());
        self::assertSame('/api/v2/tag-assignments', $request->getUri()->getPath());
        self::assertSame('', $request->getUri()->getQuery());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame([
            'application_id' => 'harness-app',
            'tag_id' => 'tag_1',
            'resource_type' => 'product',
            'resource_id' => 'sku-003',
        ], $this->lastJsonBody());
    }

    public function testRemoveValidationErrorThrows(): void
    {
        $client = $this->clientWith([self::captured('v2/errors/validation-error')]);

        $this->expectException(ValidationException::class);
        $client->assignments()->remove(new AssignmentRemove('harness-app', 'product', 'sku-003', 'not-a-uuid'), self::merchantA());
    }

    public function testBulkAssign(): void
    {
        $client = $this->clientWith([self::captured('v2/tag-assignments/bulk-assign')]);
        $data = self::capturedData('v2/tag-assignments/bulk-assign');

        $result = $client->assignments()->bulkAssign(new BulkAssign(
            applicationId: 'harness-app',
            resourceType: 'product',
            resourceId: 'sku-003',
            tagIds: ['tag_1', 'tag_2'],
            aliasSlugs: ['promo'],
            assignedBy: 'octonomy-php-capture',
        ), self::merchantA());

        $request = $this->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/api/v2/tag-assignments/bulk-assign', $request->getUri()->getPath());
        self::assertSame([
            'application_id' => 'harness-app',
            'resource_type' => 'product',
            'resource_id' => 'sku-003',
            'tag_ids' => ['tag_1', 'tag_2'],
            'alias_slugs' => ['promo'],
            'assigned_by' => 'octonomy-php-capture',
        ], $this->lastJsonBody());

        // The summary object the server sends, not the spec's bare array.
        self::assertSame($data['created'], $result->created);
        self::assertSame($data['existing'], $result->existing);
        self::assertSame($data['skipped'], $result->skipped);
        self::assertGreaterThan(0, $result->created);
        self::assertGreaterThan(0, $result->existing);
        self::assertCount(count($data['assignments']), $result->assignments);
        self::assertContainsOnlyInstancesOf(Assignment::class, $result->assignments);
        self::assertSame(
            array_column($data['assignments'], 'tag_id'),
            array_map(static fn(Assignment $a): string => $a->tagId, $result->assignments),
        );
    }

    /**
     * A list is sent as a JSON list whatever its keys: one left with gaps by
     * array_filter() or unset() would otherwise encode as an object.
     */
    public function testBulkListsAreSentAsListsWhateverTheirKeys(): void
    {
        $client = $this->clientWith([self::captured('v2/tag-assignments/bulk-assign'), self::captured('v2/tag-assignments/bulk-remove')]);

        $client->assignments()->bulkAssign(new BulkAssign(
            'harness-app',
            'product',
            'sku-003',
            tagIds: array_filter(['', 'tag_1', '', 'tag_2']),
            aliasSlugs: [3 => 'promo'],
        ));
        self::assertSame(
            '{"application_id":"harness-app","resource_type":"product","resource_id":"sku-003",'
            . '"tag_ids":["tag_1","tag_2"],"alias_slugs":["promo"]}',
            $this->lastRawBody(),
        );

        $client->assignments()->bulkRemove(new BulkRemove('harness-app', 'product', 'sku-003', ['a' => 'tag_1']));
        self::assertSame(
            '{"application_id":"harness-app","resource_type":"product","resource_id":"sku-003","tag_ids":["tag_1"]}',
            $this->lastRawBody(),
        );
    }

    /**
     * Only what is set goes out; an empty list is sent as the empty list it is.
     */
    public function testBulkAssignSendsOnlyWhatIsSet(): void
    {
        $client = $this->clientWith([self::captured('v2/tag-assignments/bulk-assign')]);

        $client->assignments()->bulkAssign(new BulkAssign('harness-app', 'product', 'sku-003', aliasSlugs: []));

        self::assertSame(
            '{"application_id":"harness-app","resource_type":"product","resource_id":"sku-003","alias_slugs":[]}',
            $this->lastRawBody(),
        );
    }

    public function testBulkAssignValidationErrorThrows(): void
    {
        $client = $this->clientWith([self::captured('v2/errors/validation-error')]);

        $this->expectException(ValidationException::class);
        $client->assignments()->bulkAssign(new BulkAssign('harness-app', 'product', 'sku-003'), self::merchantA());
    }

    public function testBulkAssignNotFoundThrows(): void
    {
        $client = $this->clientWith([self::captured('v2/errors/not-found')]);

        $this->expectException(NotFoundException::class);
        $client->assignments()->bulkAssign(new BulkAssign('harness-app', 'product', 'sku-003', ['tag_1']), self::merchantA());
    }

    public function testBulkAssignConflictThrows(): void
    {
        $client = $this->clientWith([self::captured('v2/errors/conflict')]);

        $this->expectException(ConflictException::class);
        $client->assignments()->bulkAssign(new BulkAssign('harness-app', 'product', 'sku-003', ['tag_1']), self::merchantA());
    }

    /**
     * An assignment in the result that breaks its field set is reported at
     * its place in the list.
     */
    public function testABulkAssignmentFaultIsReportedAtItsPlace(): void
    {
        $client = $this->clientWith([self::syntheticNegativeFrom('v2/tag-assignments/bulk-assign', SyntheticNegative::MISSING_FIELD, static function (array $body): array {
            unset($body['data']['assignments'][1]['tag_id']);

            return $body;
        })]);

        $this->expectException(MalformedResponseException::class);
        $this->expectExceptionMessage(
            'octonomy: malformed response from POST /api/v2/tag-assignments/bulk-assign (api_version v2):'
            . ' field "data.assignments[1].tag_id" is missing',
        );

        $client->assignments()->bulkAssign(new BulkAssign('harness-app', 'product', 'sku-003', ['tag_1']), self::merchantA());
    }

    /**
     * @param \Closure(array<array-key, mixed>): array<array-key, mixed> $break
     */
    #[DataProvider('malformedBulkResults')]
    public function testAMalformedBulkAssignmentListIsRefused(\Closure $break, string $expected): void
    {
        $client = $this->clientWith([self::syntheticNegativeFrom('v2/tag-assignments/bulk-assign', SyntheticNegative::WRONG_TYPE, $break)]);

        $this->expectException(MalformedResponseException::class);
        $this->expectExceptionMessage($expected);

        $client->assignments()->bulkAssign(new BulkAssign('harness-app', 'product', 'sku-003', ['tag_1']), self::merchantA());
    }

    /**
     * @return iterable<string, array{\Closure(array<array-key, mixed>): array<array-key, mixed>, string}>
     */
    public static function malformedBulkResults(): iterable
    {
        yield 'an object where the list is' => [static function (array $body): array {
            $body['data']['assignments'] = ['first' => $body['data']['assignments'][0]];

            return $body;
        }, 'field "data.assignments" must be a list, got an object'];
        yield 'an item that is not an object' => [static function (array $body): array {
            $body['data']['assignments'][2] = 'sku-003';

            return $body;
        }, 'field "data.assignments[2]" must be an object, got a string'];
        yield 'a count that is not an integer' => [static function (array $body): array {
            $body['data']['created'] = '2';

            return $body;
        }, 'field "data.created" must be an integer, got a string'];
    }

    public function testBulkRemove(): void
    {
        $client = $this->clientWith([self::captured('v2/tag-assignments/bulk-remove')]);

        $result = $client->assignments()->bulkRemove(new BulkRemove('harness-app', 'product', 'sku-003', ['tag_2']), self::merchantA());

        $request = $this->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/api/v2/tag-assignments/bulk-remove', $request->getUri()->getPath());
        self::assertSame([
            'application_id' => 'harness-app',
            'resource_type' => 'product',
            'resource_id' => 'sku-003',
            'tag_ids' => ['tag_2'],
        ], $this->lastJsonBody());
        self::assertSame(self::capturedData('v2/tag-assignments/bulk-remove')['removed'], $result->removed);
        self::assertSame(1, $result->removed);
    }

    public function testBulkRemoveValidationErrorThrows(): void
    {
        $client = $this->clientWith([self::captured('v2/errors/validation-error')]);

        $this->expectException(ValidationException::class);
        $client->assignments()->bulkRemove(new BulkRemove('harness-app', 'product', 'sku-003', []), self::merchantA());
    }

    /**
     * The namespace the v2 responses were captured in.
     */
    private static function merchantA(): RequestOptions
    {
        return new RequestOptions(namespace: new NamespaceScope('merchant', 'harness-merchant-a'));
    }
}
