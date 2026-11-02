<?php

declare(strict_types=1);

namespace Octoverse\Octonomy;

use Octoverse\Octonomy\Http\Transport;
use Octoverse\Octonomy\Resource\AssignmentService;
use Octoverse\Octonomy\Resource\AuditLogService;
use Octoverse\Octonomy\Resource\ResourceService;
use Octoverse\Octonomy\Resource\TagAliasService;
use Octoverse\Octonomy\Resource\TagService;
use Octoverse\Octonomy\Resource\VocabularyService;

/**
 * Client is the entry point to the Octonomy API. Construct it with an options
 * array (or a Config) and reach resources through the service accessors.
 *
 * A Client calls one REST surface for its whole life: /api/v2 by default, or
 * /api/v1 with 'api_version' => 'v1' (ApiVersion::V1). The version is fixed at
 * construction; build another Client to call the other surface.
 *
 * ```php
 * $client = new Client([
 *     'base_url'  => 'https://octonomy.example.com',
 *     'token'     => 'svc_live_...',
 *     'tenant_id' => 'acme',
 * ]);
 * $tag = $client->tags()->create(new TagCreate(name: 'Featured', slug: 'featured', type: 'label'));
 * ```
 */
final class Client
{
    /**
     * The SDK release. Single source of truth for the version, checked against
     * CHANGELOG.md by `make version-check`, and used in the default User-Agent.
     */
    public const VERSION = '0.1.0';

    private readonly Transport $transport;
    private ?VocabularyService $vocabularies = null;
    private ?TagService $tags = null;
    private ?TagAliasService $aliases = null;
    private ?AssignmentService $assignments = null;
    private ?ResourceService $resources = null;
    private ?AuditLogService $auditLogs = null;

    /**
     * @param array<string, mixed>|Config $config an options array (see Config::fromArray) or a Config
     *
     * @throws Exception\ConfigurationException when the configuration is invalid, an unknown
     *                                          api_version included
     */
    public function __construct(array|Config $config)
    {
        $resolved = $config instanceof Config ? $config : Config::fromArray($config);
        $this->transport = new Transport($resolved, self::VERSION);
    }

    /**
     * The api_version this client calls, an ApiVersion constant, as fixed at
     * construction.
     */
    public function apiVersion(): string
    {
        return $this->transport->apiVersion();
    }

    /**
     * Vocabularies manages tenant-scoped tag groupings.
     */
    public function vocabularies(): VocabularyService
    {
        return $this->vocabularies ??= new VocabularyService($this->transport);
    }

    /**
     * Tags manages the core tagging units.
     */
    public function tags(): TagService
    {
        return $this->tags ??= new TagService($this->transport);
    }

    /**
     * Aliases manages alternate slugs that resolve to canonical tags.
     */
    public function aliases(): TagAliasService
    {
        return $this->aliases ??= new TagAliasService($this->transport);
    }

    /**
     * Assignments links tags to external resources and unlinks them.
     */
    public function assignments(): AssignmentService
    {
        return $this->assignments ??= new AssignmentService($this->transport);
    }

    /**
     * Resources reads and replaces the tags on one external resource, and
     * reads its audit log.
     */
    public function resources(): ResourceService
    {
        return $this->resources ??= new ResourceService($this->transport);
    }

    /**
     * AuditLogs reads the tenant's append-only mutation history.
     */
    public function auditLogs(): AuditLogService
    {
        return $this->auditLogs ??= new AuditLogService($this->transport);
    }
}
