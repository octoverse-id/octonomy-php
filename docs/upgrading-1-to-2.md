# Upgrading from 1.x to 2.x

The SDK ships as two lines of one package, `octoverse-id/octonomy-php`
([versioning.md](versioning.md)):

| Line | Branch | PHP | Guzzle |
| ---- | ------ | --- | ------ |
| 2.x | `main` | 8.2 and later | 7.8 or 8, or any PSR-18 client |
| 1.x | `support/php7.4` | 7.4 and later | 7.8 or later 7.x, or any PSR-18 client |

Composer picks the line by your PHP version: on PHP 7.4, 8.0 or 8.1 it can only resolve 1.x, and from
8.2 it resolves 2.x unless you constrain it otherwise. The 1.x line is generated from the 2.x source
by [Rector](https://getrector.com), so both have the same classes, methods and behavior against the
server, and code written for 1.x runs on 2.x except where this page says otherwise. No version of
either line has been released yet; these are the differences their first releases will have.

There is no deadline to move. 1.x is supported indefinitely, but after its `1.0.0` it takes security
fixes and major bug fixes only: new features, support for later changes to the Octonomy API included,
ship on 2.x alone. Nothing warns you at runtime either way
([versioning.md](versioning.md#release-lines-and-support)).

## What changes when you move to 2.x

### PHP 8.2 is the floor

2.x requires PHP 8.2. Upgrade PHP first; on an older PHP, Composer keeps you on 1.x.

### Models are read-only

On 2.x every response model (`Tag`, `Vocabulary`, `Assignment`, `HealthStatus`, ...) and every
request DTO is `readonly`: writing to a property after construction throws an `Error`
("Cannot modify readonly property"). On 1.x the same properties are writable, because PHP 7.4 has no
`readonly`, and the downgrade removes the keyword rather than emulating it.

So code like this works on 1.x and fails on 2.x:

```php
$tag = $client->tags()->get($id);
$tag->name = 'Renamed';                    // 1.x: changes your local copy only
                                           // 2.x: Error, Cannot modify readonly property
```

Writing to a model never changed anything on the server on either line: it only changed your copy.
To change a tag, send an update: `$client->tags()->update($id, new TagUpdate(...))`. To change a
value you keep locally, keep it in a variable of your own.

To find such writes before you move, see [below](#finding-writes-before-you-move).

### Named arguments become available

PHP 7.4 has no named arguments, so on 1.x you pass constructor and method arguments by position.
The [README](../README.md) and the [guide](guide.md) write `new TagCreate(name: 'Featured', ...)`,
which is PHP 8 syntax; the `examples/` the 1.x package ships are downgraded, so they show the
positional form. Positional calls keep working on 2.x, so nothing here breaks; named arguments are
simply open to you after the move.

### Guzzle 8 becomes allowed

1.x requires `guzzlehttp/guzzle` `^7.8`; 2.x accepts `^7.8 || ^8.0`. Nothing breaks if you stay on
Guzzle 7.

## Finding writes before you move

A write to a model is the one break that static analysis can find for you, and two tools find it while
you are still on 1.x.

**The SDK's Rector rule.** The package ships `Octoverse\Octonomy\Rector\FlagModelPropertyWriteRector`,
a rule for [Rector](https://getrector.com) 2. It is opt-in: nothing loads it unless a Rector
configuration of yours names it, so it never runs in your application. Install Rector as a dev
dependency if you do not have it:

```bash
composer require --dev rector/rector:^2.0
```

Give the rule a configuration of its own, so it runs apart from any refactoring you already do with
Rector, and point `withPaths()` at your code. Save it as `rector-octonomy.php` in your project root:

```php
<?php

use Octoverse\Octonomy\Rector\FlagModelPropertyWriteRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src'])
    ->withRules([FlagModelPropertyWriteRector::class]);
```

```bash
vendor/bin/rector process --config rector-octonomy.php --dry-run
```

The dry run shows each write as the comment the rule would put above it, and exits non-zero while it
finds a write it has not marked, so it can gate your CI. Without `--dry-run` it writes the comments
into your code:

```php
// TODO octonomy-php 2.x: a write to readonly Tag::$name throws there (docs/upgrading-1-to-2.md)
$tag->name = 'Renamed';
```

Rewrite each flagged statement as [Models are read-only](#models-are-read-only) says, then delete the
comment. The rule never flags a statement that already carries one, so once the comments are in your
code a dry run passes, and the comments themselves are the list of what is left.
`grep -rn 'TODO octonomy-php 2.x:' src` lists them, and `! grep -rq 'TODO octonomy-php 2.x:' src`
fails a CI step while any is left.

It finds an assignment of any kind, a reference, an increment or decrement, and an `unset()`, aimed at
a model property or reaching through one into the array it holds, an object in that array included
(`$tag->metadata['profile']->label = ...`, which 2.x refuses too), in an expression statement or an
`unset()` statement. It needs Rector to know the object is an SDK model, which Rector learns from a type
declaration, a `@var` or `@param` tag, or the return type of the SDK method that produced it. It finds
the same writes on 1.x, where the downgrade leaves a `@readonly` docblock on each property, as on 2.x,
where the property is `readonly`. It misses a write through a value Rector cannot type (an untyped
variable, or a variable property name such as `$tag->$name`), a property passed by reference to a
function or iterated by reference in a `foreach`, and a write inside a condition or a `return`.

**PHPStan.** PHPStan reads those `@readonly` docblocks too, and reports a write to one from level 3 up
(`property.readOnlyByPhpDocAssignOutOfClass`), including the writes in a condition, in a `return` and
through a by-reference argument that the rule misses; Psalm reports them as well. Neither finds a
`foreach` by reference over a model property, so search for those by hand.

## The Composer constraint

Move from `^1.0` to `^2.0`:

```bash
composer require octoverse-id/octonomy-php:^2.0
```

A library or application that must install on both old and new PHP can require
`"^1.0 || ^2.0"`, and Composer picks the line each platform can install.
