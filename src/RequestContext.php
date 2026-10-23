<?php

declare(strict_types=1);

namespace Octoverse\Octonomy;

/**
 * RequestContext describes the request an exception came from: the api_version
 * it called, its method, its path, its query parameters and the namespace it
 * was sent in. Every ApiException and TransportException carries one, and so
 * does a MalformedResponseException raised while reading a response.
 *
 * The SDK never logs, so the exception is the only record of a failed call.
 * Once namespaces exist a bare 404 has three causes that look the same from
 * the status alone: a wrong id, the right id in another namespace, or the
 * right id outside what the request could see because include_global was
 * false. The context records where the read looked (the id in its path, its
 * namespace, its application_id) and whether it asked for include_global, so
 * a log line written long after the fact still says so. It cannot say which
 * cause applied: the server deliberately does not reveal whether a row exists
 * in a scope the request could not see.
 *
 * It holds nothing secret and nothing the caller sent as content: no token,
 * no tenant id, no actor id, no other header and no request body. The
 * namespace is the one header-borne value it keeps, because it names where
 * the request looked rather than who sent it. The query is kept because
 * application_id and include_global decide what a read can see, and no query
 * parameter carries a credential.
 *
 * ```php
 * try {
 *     $client->tags()->get($id, 'commerce', options: $options);
 * } catch (NotFoundException $e) {
 *     $logger->warning($e->getMessage(), [
 *         'endpoint' => $e->context->endpoint(),
 *         'query' => $e->context->query,
 *         'namespace' => $e->context->namespace?->id,
 *     ]);
 * }
 * ```
 */
final class RequestContext
{
    /**
     * @param string                $apiVersion the api_version the request called, an ApiVersion constant
     * @param string                $method     the HTTP method, such as 'GET'
     * @param string                $path       the path as sent, under the api_version prefix, such as
     *                                          '/api/v2/tags/7d1c...'; ids are already URL-encoded
     * @param array<string, string> $query      the query parameters as sent, empty when there were none
     * @param NamespaceScope|null   $namespace  the namespace the request was sent in, or null for the
     *                                          global (tenant-shared) partition
     */
    public function __construct(
        public readonly string $apiVersion,
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly ?NamespaceScope $namespace = null,
    ) {}

    /**
     * The request as an exception message names it: the method and the path,
     * such as "GET /api/v2/tags/7d1c...". The query and namespace are left to
     * their own fields.
     */
    public function endpoint(): string
    {
        return $this->method . ' ' . $this->path;
    }
}
