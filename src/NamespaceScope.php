<?php

declare(strict_types=1);

namespace Octoverse\Octonomy;

use Octoverse\Octonomy\Internal\ErrorMessage;
use Octoverse\Octonomy\Internal\Redaction;

/**
 * NamespaceScope places one request in a merchant or sub-tenant namespace, sent
 * as the X-Namespace-Type / X-Namespace-ID header pair. Pass it per request
 * through RequestOptions; there is deliberately no client-wide default, because
 * the server enforces namespace grants and fails closed, so a namespace the
 * caller did not name on the call is one the SDK must never send. Omit it for
 * the global (tenant-shared) partition.
 *
 * Namespaces exist on api_version v2 only. A v1 client refuses a request that
 * carries one, before sending it.
 *
 * The type and id travel as one atomic pair: neither may be blank, since half a
 * pair names no namespace, and neither may hold a control character, which a
 * header cannot carry. Both are opaque, caller-canonical strings that the
 * server does not case-fold, so "Merchant" and "merchant" are different types.
 *
 * Named NamespaceScope rather than Namespace because `namespace` is a reserved
 * word in PHP and cannot name a class.
 *
 * ```php
 * $tags = $client->tags()->list(
 *     new TagListParams(applicationId: 'commerce'),
 *     new RequestOptions(namespace: new NamespaceScope('merchant', 'm-42')),
 * );
 * ```
 */
final class NamespaceScope
{
    /**
     * @throws \InvalidArgumentException when the type or the id is blank or holds a control character
     */
    public function __construct(
        public readonly string $type,
        public readonly string $id,
    ) {
        if (trim($type) === '') {
            throw new \InvalidArgumentException(
                'octonomy: a namespace type is required; omit the namespace for the global (tenant-shared) partition',
            );
        }
        if (trim($id) === '') {
            throw new \InvalidArgumentException(
                sprintf('octonomy: namespace type "%s" has no id; a namespace is a type and an id together', $type),
            );
        }
        // Refused here rather than by Guzzle, whose error is thrown from frames
        // that hold the Authorization header and the body (Redaction).
        foreach (['type' => [$type, 'X-Namespace-Type'], 'id' => [$id, 'X-Namespace-ID']] as $part => [$value, $header]) {
            if (!Redaction::isHeaderSafe($value)) {
                throw new \InvalidArgumentException(ErrorMessage::unsendable("the namespace {$part}", $header));
            }
        }
    }
}
