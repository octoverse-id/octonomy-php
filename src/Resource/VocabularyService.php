<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Resource;

use Octoverse\Octonomy\Http\Transport;
use Octoverse\Octonomy\Internal\Json;
use Octoverse\Octonomy\Model\Vocabulary;
use Octoverse\Octonomy\Model\VocabularyCreate;
use Octoverse\Octonomy\Model\VocabularyListParams;
use Octoverse\Octonomy\Model\VocabularyUpdate;
use Octoverse\Octonomy\Pagination;
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
        return Vocabulary::fromArray(
            $this->transport->request('POST', 'vocabularies', [], $input->toArray(), $options),
        );
    }

    /**
     * Retrieve a vocabulary by id (GET /vocabularies/{id}).
     */
    public function get(string $id, ?RequestOptions $options = null): Vocabulary
    {
        return Vocabulary::fromArray(
            $this->transport->request('GET', 'vocabularies/' . rawurlencode($id), [], null, $options),
        );
    }

    /**
     * List a page of vocabularies (GET /vocabularies).
     *
     * @return ResultList<Vocabulary>
     */
    public function list(?VocabularyListParams $params = null, ?RequestOptions $options = null): ResultList
    {
        $body = $this->transport->request('GET', 'vocabularies', $params?->toQuery() ?? [], null, $options);

        $data = [];
        foreach (Json::objectList($body, 'data') as $row) {
            $data[] = Vocabulary::fromArray($row);
        }

        return new ResultList($data, Pagination::fromArray(Json::object($body, 'pagination')));
    }

    /**
     * Partially update a vocabulary (PATCH /vocabularies/{id}).
     */
    public function update(string $id, VocabularyUpdate $input, ?RequestOptions $options = null): Vocabulary
    {
        return Vocabulary::fromArray(
            $this->transport->request('PATCH', 'vocabularies/' . rawurlencode($id), [], $input->toArray(), $options),
        );
    }

    /**
     * Deactivate a vocabulary (DELETE /vocabularies/{id}). Octonomy treats
     * deletion as deactivation; the record and its history are retained.
     */
    public function delete(string $id, ?RequestOptions $options = null): void
    {
        $this->transport->request('DELETE', 'vocabularies/' . rawurlencode($id), [], null, $options);
    }
}
