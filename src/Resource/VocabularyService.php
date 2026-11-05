<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Resource;

use Octoverse\Octonomy\Http\Transport;
use Octoverse\Octonomy\Http\Walker;
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
     * Walk every vocabulary $params selects (GET /vocabularies), yielding each
     * Vocabulary keyed by its offset in the list. Vocabularies are sorted by
     * name, slug and id.
     *
     * IT SENDS ONE REQUEST PER PAGE, in sequence: it is the loop over list()
     * you did not have to write, not a bulk fetch. A page holds up to
     * $params->limit vocabularies, or 200, the server's maximum, when that is
     * null. Nothing is sent until the walk is iterated, and each page only once
     * the one before it is used up. $params->offset starts the walk part of
     * the way in.
     *
     * A failed page request throws WalkInterruptedException, whose
     * reachedOffset is where that page starts: walk again with the same params
     * and that offset to resume.
     *
     * It is not a snapshot. The server pages by offset and has no cursor, so a
     * vocabulary created, or deactivated, that sorts before the walk's position
     * while the walk runs moves every one after it by one: the walk then
     * yields one twice, or never yields one, and a resume drifts the same way.
     * Narrow the walk with filters nothing changes while it runs, de-duplicate
     * on id, or walk while nothing writes.
     *
     * @return \Generator<int, Vocabulary, mixed, void>
     */
    public function walk(?VocabularyListParams $params = null, ?RequestOptions $options = null): \Generator
    {
        return Walker::walk(
            $this->transport,
            'vocabularies',
            Vocabulary::fromArray(...),
            $params?->toQuery() ?? [],
            $params?->offset,
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
