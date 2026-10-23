<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests;

use Octoverse\Octonomy\ApiVersion;
use Octoverse\Octonomy\Exception\ConflictException;
use Octoverse\Octonomy\Exception\NotFoundException;
use Octoverse\Octonomy\Exception\TransportException;
use Octoverse\Octonomy\Model\TagCreate;
use Octoverse\Octonomy\NamespaceScope;
use Octoverse\Octonomy\RequestContext;
use Octoverse\Octonomy\RequestOptions;

/**
 * Covers RequestContext: what it records about a failed request, and what it
 * must never record. Transport's own tests cover that every exception path
 * carries one.
 */
final class RequestContextTest extends SdkTestCase
{
    /**
     * A namespaced 404 has three causes (wrong id, another namespace,
     * include_global off) the response does not tell apart. The context records
     * where the read looked and whether it asked for include_global, though not
     * which cause applied: the server never says whether the id exists elsewhere.
     */
    public function testANamespacedNotFoundRecordsWhatDecidedWhatTheReadCouldSee(): void
    {
        $namespace = new NamespaceScope('merchant', 'm-42');
        $client = $this->clientWith([self::captured('v2/errors/not-found')]);

        try {
            $client->tags()->get('tag/1', 'commerce', false, new RequestOptions(namespace: $namespace));
            self::fail('expected a NotFoundException');
        } catch (NotFoundException $e) {
            self::assertSame(ApiVersion::V2, $e->context->apiVersion);
            self::assertSame('GET', $e->context->method);
            self::assertSame('/api/v2/tags/tag%2F1', $e->context->path);
            self::assertSame(['application_id' => 'commerce', 'include_global' => 'false'], $e->context->query);
            // A copy, so it records what was sent whatever happens to the caller's.
            self::assertEquals($namespace, $e->context->namespace);
        }
    }

    /**
     * A write sends a token, a tenant, an actor and a body. The context keeps
     * none of them, so an exception can be logged as it is.
     */
    public function testItHoldsNoCredentialHeaderOrBodyContent(): void
    {
        $client = $this->clientWith([self::captured('v2/errors/conflict')], [
            'token' => 'octo_tokenprefix_tokensecret',
            'tenant_id' => 'tenant-kept-out',
            'actor_id' => 'actor-from-config',
        ]);
        $input = new TagCreate(name: 'Name From The Body', slug: 'slug-from-the-body', type: 'label', applicationId: 'harness-app');

        try {
            $client->tags()->create($input, new RequestOptions(
                actorId: 'actor-from-the-call',
                namespace: new NamespaceScope('merchant', 'harness-merchant-a'),
            ));
            self::fail('expected a ConflictException');
        } catch (ConflictException $e) {
            $context = $e->context;
        }

        // Each of these did go on the wire, so their absence below is not vacuous.
        $request = $this->lastRequest();
        self::assertSame('Bearer octo_tokenprefix_tokensecret', $request->getHeaderLine('Authorization'));
        self::assertSame('tenant-kept-out', $request->getHeaderLine('X-Tenant-ID'));
        self::assertSame('actor-from-the-call', $request->getHeaderLine('X-Actor-ID'));
        self::assertSame('Name From The Body', $this->lastJsonBody()['name']);

        $recorded = var_export($context, true) . print_r($context, true) . json_encode(get_object_vars($context), JSON_THROW_ON_ERROR);
        foreach (['tokensecret', 'octo_tokenprefix', 'Bearer', 'Authorization', 'tenant-kept-out', 'X-Tenant-ID', 'actor-from', 'X-Actor-ID', 'Name From The Body', 'slug-from-the-body', 'harness-app'] as $secret) {
            self::assertStringNotContainsString($secret, $recorded);
        }
        self::assertEquals(
            new RequestContext(ApiVersion::V2, 'POST', '/api/v2/tags', [], new NamespaceScope('merchant', 'harness-merchant-a')),
            $context,
        );
    }

    /**
     * The fields are the whole contract, so adding one is a decision made here:
     * a request header, the token or the body must never become one.
     */
    public function testItsFieldsAreTheFiveThatDescribeWhereTheRequestWent(): void
    {
        $fields = array_map(
            static fn(\ReflectionProperty $property): string => $property->getName(),
            (new \ReflectionClass(RequestContext::class))->getProperties(),
        );

        self::assertSame(['apiVersion', 'method', 'path', 'query', 'namespace'], $fields);
    }

    public function testEndpointNamesTheMethodAndPath(): void
    {
        $context = new RequestContext(ApiVersion::V1, 'DELETE', '/api/v1/tags/tag_1');

        self::assertSame('DELETE /api/v1/tags/tag_1', $context->endpoint());
        self::assertSame([], $context->query);
        self::assertNull($context->namespace);
    }

    /**
     * No response at all, so the context is the only description of the call.
     * Guzzle's exception is quoted in the message rather than chained: it holds
     * the request it failed to send, Authorization header included.
     */
    public function testANetworkFailureCarriesTheContext(): void
    {
        $client = $this->clientThatCannotConnect();

        try {
            $client->tags()->get('tag_1', 'commerce');
            self::fail('expected a TransportException');
        } catch (TransportException $e) {
            self::assertEquals(new RequestContext(ApiVersion::V2, 'GET', '/api/v2/tags/tag_1', ['application_id' => 'commerce']), $e->context);
            self::assertNull($e->getPrevious());
            self::assertStringContainsString('\\ConnectException: "Connection refused".', $e->getMessage());
            // Refused while sending, not before: the guard let the request through.
            self::assertSame($e->context->path, $this->lastRequest()->getUri()->getPath());
        }
    }
}
