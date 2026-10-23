<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Exception;

use Octoverse\Octonomy\Internal\Redaction;
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
     * @param string|null         $got        the value from the body the message quotes after $problem, if
     *                                        any; kept apart so it can be redacted once the request is known
     */
    public function __construct(
        private readonly string $problem,
        public readonly ?string $field = null,
        public readonly ?RequestContext $context = null,
        private readonly ?string $apiVersion = null,
        ?\Throwable $previous = null,
        private readonly ?string $got = null,
    ) {
        $version = $context === null ? $apiVersion : $context->apiVersion;
        $problem .= $got === null ? '' : ", got \"{$got}\"";
        parent::__construct(sprintf(
            'octonomy: malformed response%s%s: %s',
            $context === null ? '' : " from {$context->endpoint()}",
            $version === null ? '' : " (api_version {$version})",
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
        return new self($this->problem, $this->field, null, $apiVersion, $this, $this->got);
    }

    /**
     * The same fault, placed in the response it came from. A model reports a
     * field by its key alone; Transport, which knows the request and where in
     * the body the model's object sat ($at, such as "data" or "data[2]"),
     * calls this to name both.
     *
     * A value the message quotes from the body is redacted here, in this
     * report and in the ones it chains, since a server can echo the request
     * into a field ($redaction holds what the request carried).
     *
     * @internal
     */
    public function locate(RequestContext $context, string $at, ?Redaction $redaction = null): self
    {
        $redaction ??= Redaction::none();

        return new self(
            $this->problem,
            $this->field === null ? $at : "{$at}.{$this->field}",
            $context,
            null,
            $this->redacted($redaction),
            $this->got === null ? null : $redaction->text($this->got),
        );
    }

    /**
     * This report and the ones it chains with their quoted values redacted, or
     * this report itself when there is nothing to redact.
     */
    private function redacted(Redaction $redaction): self
    {
        $previous = $this->getPrevious();
        $chained = $previous instanceof self ? $previous->redacted($redaction) : $previous;
        $got = $this->got === null ? null : $redaction->text($this->got);
        if ($got === $this->got && $chained === $previous) {
            return $this;
        }

        return new self($this->problem, $this->field, $this->context, $this->apiVersion, $chained, $got);
    }
}
