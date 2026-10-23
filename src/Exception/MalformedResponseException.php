<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Exception;

use Octoverse\Octonomy\RequestContext;

/**
 * MalformedResponseException means Octonomy answered with a 2xx whose JSON body
 * breaks the contract the endpoint guarantees. Either the envelope is missing
 * (no {"data": {...}} object, or no {"data": [...], "pagination": {...}} pair),
 * or a resource in it is: a field declared for that api_version is absent, a
 * field the api_version does not declare is present, or a value has the wrong
 * JSON type. The SDK raises it instead of hydrating a zero value or coercing
 * one, so contract drift fails loudly at the call site.
 *
 * The message names the endpoint, the api_version and what is wrong. When a
 * field is at fault, $field holds its path in the body, such as
 * "data.usage_count" or "data[2].created_at". When the body came from a
 * request, $context describes that request; it is null only for a model
 * hydrated outside one.
 */
final class MalformedResponseException extends OctonomyException
{
    /**
     * @param string              $problem    what is wrong; follows the field path in the message when $field
     *                                        is set ('is missing'), and stands alone otherwise
     * @param string|null         $field      the path of the field at fault, or null when the envelope is
     * @param RequestContext|null $context    the request that received the body; null when a model is
     *                                        hydrated outside a request
     * @param string|null         $apiVersion the api_version the body was read as, when there is no $context
     *                                        to say so
     */
    public function __construct(
        private readonly string $problem,
        public readonly ?string $field = null,
        public readonly ?RequestContext $context = null,
        ?string $apiVersion = null,
        ?\Throwable $previous = null,
    ) {
        $apiVersion = $context === null ? $apiVersion : $context->apiVersion;
        parent::__construct(sprintf(
            'octonomy: malformed response%s%s: %s',
            $context === null ? '' : " from {$context->endpoint()}",
            $apiVersion === null ? '' : " (api_version {$apiVersion})",
            $field === null ? $problem : "field \"{$field}\" {$problem}",
        ), 0, $previous);
    }

    /**
     * The same fault, marked with the api_version the model read the body as.
     * Json::readAs() calls this, since a field reader does not know the version.
     *
     * @internal
     */
    public function readAs(string $apiVersion): self
    {
        return new self($this->problem, $this->field, null, $apiVersion, $this);
    }

    /**
     * The same fault, placed in the response it came from. A model reports a
     * field by its key alone; Transport, which knows the request and where in
     * the body the model's object sat ($at, such as "data" or "data[2]"),
     * calls this to name both.
     *
     * @internal
     */
    public function locate(RequestContext $context, string $at): self
    {
        return new self(
            $this->problem,
            $this->field === null ? $at : "{$at}.{$this->field}",
            $context,
            null,
            $this,
        );
    }
}
