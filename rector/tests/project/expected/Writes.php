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
        // TODO octonomy-php 2.x: a write to readonly Tag::$name throws there (docs/upgrading-1-to-2.md)
        $fetched->name = 'Renamed';
        // TODO octonomy-php 2.x: a write to readonly Tag::$isActive throws there (docs/upgrading-1-to-2.md)
        $client->tags()->get('t2')->isActive = false;
        // TODO octonomy-php 2.x: a write to readonly Tag::$description throws there (docs/upgrading-1-to-2.md)
        $tag->description .= ' (archived)';
        // TODO octonomy-php 2.x: a write to readonly Tag::$usageCount throws there (docs/upgrading-1-to-2.md)
        $tag->usageCount++;
        // TODO octonomy-php 2.x: a write to readonly Tag::$usageCount throws there (docs/upgrading-1-to-2.md)
        --$tag->usageCount;
        // TODO octonomy-php 2.x: a write to readonly Tag::$metadata throws there (docs/upgrading-1-to-2.md)
        $tag->metadata['source'] = 'import';
        // TODO octonomy-php 2.x: a write to readonly Tag::$metadata throws there (docs/upgrading-1-to-2.md)
        $tag->metadata['nested']['deep'] = true;
        // TODO octonomy-php 2.x: a write to readonly Tag::$parentId throws there (docs/upgrading-1-to-2.md)
        unset($tag->parentId);
        // TODO octonomy-php 2.x: a write to readonly Tag::$metadata throws there (docs/upgrading-1-to-2.md)
        unset($tag->metadata['source']);
        // TODO octonomy-php 2.x: a write to readonly Tag::$slug throws there (docs/upgrading-1-to-2.md)
        $alias = &$tag->slug;
        // TODO octonomy-php 2.x: writes to readonly Tag::$name, Tag::$slug throw there (docs/upgrading-1-to-2.md)
        [$tag->name, $tag->slug] = ['Pair', 'pair'];
        // TODO octonomy-php 2.x: a write to readonly Tag::$type throws there (docs/upgrading-1-to-2.md)
        list($tag->type) = ['label'];
        // TODO octonomy-php 2.x: a write to readonly Tag::$name throws there (docs/upgrading-1-to-2.md)
        $label = ($tag->name = 'Inline') . '!';
        $create = new TagCreate('Featured', 'featured', 'label');
        // TODO octonomy-php 2.x: a write to readonly TagCreate::$name throws there (docs/upgrading-1-to-2.md)
        $create->name = 'Other';
        foreach ($client->tags()->list()->data as $listed) {
            // TODO octonomy-php 2.x: a write to readonly Tag::$name throws there (docs/upgrading-1-to-2.md)
            $listed->name = 'From a page';
        }
        $rename = function () use ($tag): void {
            // TODO octonomy-php 2.x: a write to readonly Tag::$name throws there (docs/upgrading-1-to-2.md)
            $tag->name = 'In a closure';
        };
        /** @var Tag $described */
        $described = $this->untyped($client);
        // TODO octonomy-php 2.x: a write to readonly Tag::$slug throws there (docs/upgrading-1-to-2.md)
        $described->slug = 'typed-by-a-docblock';
        // TODO octonomy-php 2.x: a write to readonly Tag::$metadata throws there (docs/upgrading-1-to-2.md)
        $tag->metadata['profile']->label = 'Through an object in the array';
        // TODO octonomy-php 2.x: writes to readonly Tag::$name, ResultList::$data throw there (docs/upgrading-1-to-2.md)
        $client->tags()->list()->data[0]->name = 'Through a page';
        // TODO octonomy-php 2.x: a write to readonly Pagination::$limit throws there (docs/upgrading-1-to-2.md)
        $client->tags()->list()->pagination->limit = 10;
        // TODO octonomy-php 2.x: a write to readonly Tag::$name throws there (docs/upgrading-1-to-2.md)
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
