<?php

declare(strict_types=1);

namespace App;

use Octoverse\Octonomy\Client;
use Octoverse\Octonomy\Model\Tag;
use Octoverse\Octonomy\Model\TagCreate;

/**
 * Code a project wrote against the 1.x line. expected/Writes.php is this file after the rule: each
 * write to a model in run() is flagged, and untouched() comes out as it went in.
 */
final class Writes
{
    /** @var string */
    public $label = '';

    public function run(Client $client, Tag $tag): void
    {
        $fetched = $client->tags()->get('t1');
        $fetched->name = 'Renamed';
        $client->tags()->get('t2')->isActive = false;
        $tag->description .= ' (archived)';
        $tag->usageCount++;
        --$tag->usageCount;
        $tag->metadata['source'] = 'import';
        $tag->metadata['nested']['deep'] = true;
        unset($tag->parentId);
        unset($tag->metadata['source']);
        $alias = &$tag->slug;
        [$tag->name, $tag->slug] = ['Pair', 'pair'];
        list($tag->type) = ['label'];
        $label = ($tag->name = 'Inline') . '!';
        $create = new TagCreate('Featured', 'featured', 'label');
        $create->name = 'Other';
        foreach ($client->tags()->list()->data as $listed) {
            $listed->name = 'From a page';
        }
        $rename = function () use ($tag): void {
            $tag->name = 'In a closure';
        };
        /** @var Tag $described */
        $described = $this->untyped($client);
        $described->slug = 'typed-by-a-docblock';
        $tag->metadata['profile']->label = 'Through an object in the array';
        $client->tags()->list()->data[0]->name = 'Through a page';
        $client->tags()->list()->pagination->limit = 10;
        // A comment of the caller's own stays, below the flag.
        $tag->name = 'Commented';
    }

    public function untouched(Client $client, Tag $tag): void
    {
        $name = $tag->name;
        $metadata = $tag->metadata;
        $metadata['source'] = 'a copy of the array';
        $own = new \stdClass();
        $own->name = 'not an SDK object';
        $this->label = 'a property of our own';
        // Writes the rule cannot see: the analyser does not know the object is a Tag.
        $property = 'name';
        $tag->$property = 'by a variable name';
        $untyped = $this->untyped($client);
        $untyped->name = 'through an untyped value';
    }

    /**
     * @return mixed
     */
    private function untyped(Client $client)
    {
        return $client->tags()->get('t3');
    }
}
