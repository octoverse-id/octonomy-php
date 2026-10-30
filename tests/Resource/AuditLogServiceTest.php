<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests\Resource;

use Octoverse\Octonomy\ApiVersion;
use Octoverse\Octonomy\Client;
use Octoverse\Octonomy\Exception\ConflictException;
use Octoverse\Octonomy\Exception\ForbiddenException;
use Octoverse\Octonomy\Exception\NotFoundException;
use Octoverse\Octonomy\Exception\ValidationException;
use Octoverse\Octonomy\Model\AuditLog;
use Octoverse\Octonomy\Model\AuditLogListParams;
use Octoverse\Octonomy\NamespaceScope;
use Octoverse\Octonomy\RequestOptions;
use Octoverse\Octonomy\ResultList;
use Octoverse\Octonomy\Tests\SdkTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The audit log: AuditLogService::list(), and the entries about one tag
 * (TagService::listAuditLogs()) or one resource
 * (ResourceService::listAuditLogs()), on the default surface, api_version v2.
 * The v2 responses were captured inside merchant A's namespace, so the calls
 * that replay them name that namespace too.
 *
 * The error envelopes were captured from the tag endpoints. The SDK maps an
 * error by its status and code alone, so they exercise these calls the same
 * way.
 */
final class AuditLogServiceTest extends SdkTestCase
{
    public function testListSendsAllParams(): void
    {
        $client = $this->clientWith([self::captured('v2/audit-logs/list')]);

        $page = $client->auditLogs()->list(new AuditLogListParams(
            applicationId: 'harness-app',
            action: 'tag.updated',
            entityType: 'tag',
            entityId: 'tag_1',
            tagId: 'tag_1',
            resourceType: 'product',
            resourceId: 'sku-001',
            actorId: 'svc-catalog',
            operationId: 'op_1',
            limit: 3,
            offset: 0,
            includeGlobal: true,
        ), self::merchantA());

        $request = $this->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame('/api/v2/audit-logs', $request->getUri()->getPath());
        self::assertSame([
            'application_id' => 'harness-app',
            'action' => 'tag.updated',
            'entity_type' => 'tag',
            'entity_id' => 'tag_1',
            'tag_id' => 'tag_1',
            'resource_type' => 'product',
            'resource_id' => 'sku-001',
            'actor_id' => 'svc-catalog',
            'operation_id' => 'op_1',
            'limit' => '3',
            'offset' => '0',
            'include_global' => 'true',
        ], $this->lastQuery());

        $body = self::captured('v2/audit-logs/list')->json();
        self::assertCount(count($body['data']), $page->data);
        self::assertSame($body['pagination']['count'], $page->pagination->count);
        self::assertSame($body['pagination']['next'], $page->pagination->next);
    }

    public function testListWithoutParams(): void
    {
        $client = $this->clientWith([self::captured('v2/audit-logs/list')]);

        $client->auditLogs()->list();

        self::assertSame('', $this->lastRequest()->getUri()->getQuery());
    }

    /**
     * Every field arrives, and changes keeps the shape of each snapshot: the
     * top level an array, each object below it a \stdClass.
     */
    public function testAnEntryHydratesEveryField(): void
    {
        $client = $this->clientWith([self::captured('v2/audit-logs/list')]);
        $row = self::capturedData('v2/audit-logs/list')[1];

        $log = $client->auditLogs()->list(new AuditLogListParams(applicationId: 'harness-app', limit: 3), self::merchantA())->data[1];

        self::assertInstanceOf(AuditLog::class, $log);
        self::assertSame($row['id'], $log->id);
        self::assertSame($row['tenant_id'], $log->tenantId);
        self::assertSame('harness-app', $log->applicationId);
        self::assertSame('merchant', $log->namespaceType);
        self::assertSame('harness-merchant-a', $log->namespaceId);
        self::assertSame('tag.created', $log->action);
        self::assertSame('tag', $log->entityType);
        self::assertSame($row['entity_id'], $log->entityId);
        self::assertSame($row['tag_id'], $log->tagId);
        self::assertNull($log->resourceType);
        self::assertNull($log->resourceId);
        self::assertSame($row['actor_id'], $log->actorId);
        self::assertSame($row['request_id'], $log->requestId);
        self::assertSame($row['operation_id'], $log->operationId);
        self::assertSame([], $log->metadata);
        self::assertEquals(new \DateTimeImmutable($row['created_at']), $log->createdAt);

        $changes = $log->changes;
        self::assertNotNull($changes);
        self::assertSame(['after'], array_keys($changes));
        self::assertInstanceOf(\stdClass::class, $changes['after']);
        self::assertSame('discontinued', $changes['after']->slug);
        // An empty object in a snapshot stays an object, not a list.
        self::assertInstanceOf(\stdClass::class, $changes['after']->metadata);
        self::assertSame([], get_object_vars($changes['after']->metadata));
        self::assertNull($changes['after']->parent_id);
    }

    /**
     * @param \Closure(Client): ResultList<AuditLog> $call
     * @param array<string, string>                  $query
     */
    #[DataProvider('scopedLists')]
    public function testTheScopedListsSendTheirPathAndParams(string $fixture, \Closure $call, string $path, array $query): void
    {
        $client = $this->clientWith([self::captured($fixture)]);
        $rows = self::capturedData($fixture);

        $page = $call($client);

        $request = $this->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame($path, $request->getUri()->getPath());
        self::assertSame($query, $this->lastQuery());
        self::assertSame(array_column($rows, 'id'), array_map(static fn(AuditLog $l): string => $l->id, $page->data));
        self::assertSame(array_column($rows, 'action'), array_map(static fn(AuditLog $l): string => $l->action, $page->data));
    }

    /**
     * @return iterable<string, array{string, \Closure(Client): ResultList<AuditLog>, string, array<string, string>}>
     */
    public static function scopedLists(): iterable
    {
        $params = new AuditLogListParams(applicationId: 'harness-app', action: 'assignment.created', actorId: 'svc', limit: 10);
        $query = ['application_id' => 'harness-app', 'action' => 'assignment.created', 'actor_id' => 'svc', 'limit' => '10'];

        yield 'a tag\'s entries' => [
            'v2/tags/audit-logs',
            static fn(Client $c): ResultList => $c->tags()->listAuditLogs('tag_1', $params, self::merchantA()),
            '/api/v2/tags/tag_1/audit-logs',
            $query,
        ];
        yield 'a resource\'s entries' => [
            'v2/resources/audit-logs',
            static fn(Client $c): ResultList => $c->resources()->listAuditLogs('product', 'sku-004', $params, self::merchantA()),
            '/api/v2/resources/product/sku-004/audit-logs',
            $query,
        ];
        yield 'a tag\'s entries, without params' => [
            'v2/tags/audit-logs',
            static fn(Client $c): ResultList => $c->tags()->listAuditLogs('tag_1'),
            '/api/v2/tags/tag_1/audit-logs',
            [],
        ];
        yield 'a resource\'s entries, without params' => [
            'v2/resources/audit-logs',
            static fn(Client $c): ResultList => $c->resources()->listAuditLogs('product', 'sku-004'),
            '/api/v2/resources/product/sku-004/audit-logs',
            [],
        ];
    }

    /**
     * Without the audit:read scope the server answers 403.
     */
    public function testAForbiddenListThrows(): void
    {
        $client = $this->clientWith([self::captured('v2/errors/forbidden')]);

        $this->expectException(ForbiddenException::class);
        $client->auditLogs()->list(new AuditLogListParams(applicationId: 'harness-app'), self::merchantA());
    }

    /**
     * @param \Closure(Client): mixed $call
     * @param class-string<\Throwable> $expected
     */
    #[DataProvider('errorMappings')]
    public function testErrorsMapToTheirExceptions(string $fixture, \Closure $call, string $expected): void
    {
        $client = $this->clientWith([self::captured($fixture)]);

        $this->expectException($expected);
        $call($client);
    }

    /**
     * @return iterable<string, array{string, \Closure(Client): mixed, class-string<\Throwable>}>
     */
    public static function errorMappings(): iterable
    {
        $list = static fn(Client $c): mixed => $c->auditLogs()->list(new AuditLogListParams(applicationId: 'harness-app'), self::merchantA());
        $tag = static fn(Client $c): mixed => $c->tags()->listAuditLogs('tag_1', options: self::merchantA());
        $resource = static fn(Client $c): mixed => $c->resources()->listAuditLogs('product', 'sku-004', options: self::merchantA());

        foreach (['list' => $list, 'tag' => $tag, 'resource' => $resource] as $name => $call) {
            yield "{$name} 404" => ['v2/errors/not-found', $call, NotFoundException::class];
            yield "{$name} 409" => ['v2/errors/conflict', $call, ConflictException::class];
            yield "{$name} 400" => ['v2/errors/validation-error', $call, ValidationException::class];
        }
    }

    public function testV1ReadsEntriesWithoutANamespace(): void
    {
        $client = $this->clientWith([self::captured('v1/audit-logs/list')], ['api_version' => ApiVersion::V1]);

        $page = $client->auditLogs()->list(new AuditLogListParams(limit: 3));

        self::assertSame('/api/v1/audit-logs', $this->lastRequest()->getUri()->getPath());
        self::assertNotEmpty($page->data);
        foreach ($page->data as $log) {
            self::assertNull($log->namespaceType);
            self::assertNull($log->namespaceId);
        }
    }

    public function testIncludeGlobalIsRefusedOnV1(): void
    {
        $client = $this->clientWith([], ['api_version' => ApiVersion::V1]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('octonomy: include_global requires api_version v2');

        $client->tags()->listAuditLogs('tag_1', new AuditLogListParams(includeGlobal: false));
    }

    /**
     * The namespace the v2 responses were captured in.
     */
    private static function merchantA(): RequestOptions
    {
        return new RequestOptions(namespace: new NamespaceScope('merchant', 'harness-merchant-a'));
    }
}
