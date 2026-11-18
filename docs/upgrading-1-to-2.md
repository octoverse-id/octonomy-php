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

To find such writes before upgrading, run a static analyser over your code while still on 1.x. The
downgrade leaves a `@readonly` docblock on every property it made writable. PHPStan reports an
assignment to one from level 3 up (`property.readOnlyByPhpDocAssignOutOfClass`), and Psalm reports
it too.

### Named arguments become available

PHP 7.4 has no named arguments, so on 1.x you pass constructor and method arguments by position.
The [README](../README.md) and the [guide](guide.md) write `new TagCreate(name: 'Featured', ...)`,
which is PHP 8 syntax; the `examples/` the 1.x package ships are downgraded, so they show the
positional form. Positional calls keep working on 2.x, so nothing here breaks; named arguments are
simply open to you after the move.

### Guzzle 8 becomes allowed

1.x requires `guzzlehttp/guzzle` `^7.8`; 2.x accepts `^7.8 || ^8.0`. Nothing breaks if you stay on
Guzzle 7.

## The Composer constraint

Move from `^1.0` to `^2.0`:

```bash
composer require octoverse-id/octonomy-php:^2.0
```

A library or application that must install on both old and new PHP can require
`"^1.0 || ^2.0"`, and Composer picks the line each platform can install.
