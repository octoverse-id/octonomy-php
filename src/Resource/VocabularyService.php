<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Resource;

use Octoverse\Octonomy\Http\Transport;
use Octoverse\Octonomy\Internal\Path;
use Octoverse\Octonomy\Internal\ScopeQuery;
use Octoverse\Octonomy\Model\Vocabulary;
use Octoverse\Octonomy\Model\VocabularyCreate;
use Octoverse\Octonomy\Model\VocabularyListParams;
use Octoverse\Octonomy\Model\VocabularyUpdate;
use Octoverse\Octonomy\RequestOptions;
use Octoverse\Octonomy\ResultList;

/**
 * VocabularyService accesses the /vocabularies endpoints. Reach it via
 * Client::vocabularies().
 *
 * A call inside a namespace (RequestOptions::$namespace, api_version v2) must
 * name its application: in the body on create, in the params on list, and as
 * $applicationId on get, update and delete, which send it as the application_id
 * query parameter. The server refuses a namespaced call without one (403).
 */
final class VocabularyService
{
    public function __construct(private readonly Transport $transport) {}

    /**
     * Create a vocabulary (POST /vocabularies).
     */
    public function create(VocabularyCreate $input, ?RequestOptions $options = null): Vocabulary
    {
        return $this->transport->one(
            'POST',
            'vocabularies',
            Vocabulary::fromArray(...),
            [],
            $input->toArray(),
            $options,
        );
    }

    /**
     * Retrieve a vocabulary by id (GET /vocabularies/{id}). $applicationId
     * scopes the read to an application, and a namespaced read needs it.
     * $includeGlobal (v2 only) lets a namespaced read reach a global vocabulary,
     * which it otherwise answers with 404.
     */
    public function get(
        string $id,
        ?string $applicationId = null,
        ?bool $includeGlobal = null,
        ?RequestOptions $options = null,
    ): Vocabulary {
        return $this->transport->one(
            'GET',
            Path::of('vocabularies', $id),
            Vocabulary::fromArray(...),
            ScopeQuery::of($applicationId, $includeGlobal),
            null,
            $options,
        );
    }

    /**
     * List a page of vocabularies (GET /vocabularies).
     *
     * @return ResultList<Vocabulary>
     */
    public function list(?VocabularyListParams $params = null, ?RequestOptions $options = null): ResultList
    {
        return $this->transport->many(
            'GET',
            'vocabularies',
            Vocabulary::fromArray(...),
            $params?->toQuery() ?? [],
            null,
            $options,
        );
    }

    /**
     * Partially update a vocabulary (PATCH /vocabularies/{id}). $applicationId
     * is the application the request is scoped to, which a namespaced update
     * needs. It cannot move the vocabulary: its application is fixed at
     * creation, and the server answers a change to it with 409 scope_immutable.
     */
    public function update(
        string $id,
        VocabularyUpdate $input,
        ?string $applicationId = null,
        ?RequestOptions $options = null,
    ): Vocabulary {
        return $this->transport->one(
            'PATCH',
            Path::of('vocabularies', $id),
            Vocabulary::fromArray(...),
            ScopeQuery::of($applicationId),
            $input->toArray(),
            $options,
        );
    }

    /**
     * Deactivate a vocabulary (DELETE /vocabularies/{id}). Octonomy treats
     * deletion as deactivation; the record and its history are retained.
     * $applicationId scopes the request to an application, and a namespaced
     * delete needs it.
     */
    public function delete(string $id, ?string $applicationId = null, ?RequestOptions $options = null): void
    {
        $this->transport->none(
            'DELETE',
            Path::of('vocabularies', $id),
            ScopeQuery::of($applicationId),
            null,
            $options,
        );
    }
}
