<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Resource;

use Octoverse\Octonomy\Http\Transport;
use Octoverse\Octonomy\Model\Vocabulary;
use Octoverse\Octonomy\Model\VocabularyCreate;
use Octoverse\Octonomy\Model\VocabularyListParams;
use Octoverse\Octonomy\Model\VocabularyUpdate;
use Octoverse\Octonomy\RequestOptions;
use Octoverse\Octonomy\ResultList;

/**
 * VocabularyService accesses the /vocabularies endpoints. Reach it via
 * Client::vocabularies().
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
     * Retrieve a vocabulary by id (GET /vocabularies/{id}).
     */
    public function get(string $id, ?RequestOptions $options = null): Vocabulary
    {
        return $this->transport->one(
            'GET',
            'vocabularies/' . rawurlencode($id),
            Vocabulary::fromArray(...),
            [],
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
     * Partially update a vocabulary (PATCH /vocabularies/{id}).
     */
    public function update(string $id, VocabularyUpdate $input, ?RequestOptions $options = null): Vocabulary
    {
        return $this->transport->one(
            'PATCH',
            'vocabularies/' . rawurlencode($id),
            Vocabulary::fromArray(...),
            [],
            $input->toArray(),
            $options,
        );
    }

    /**
     * Deactivate a vocabulary (DELETE /vocabularies/{id}). Octonomy treats
     * deletion as deactivation; the record and its history are retained.
     */
    public function delete(string $id, ?RequestOptions $options = null): void
    {
        $this->transport->none('DELETE', 'vocabularies/' . rawurlencode($id), [], null, $options);
    }
}
