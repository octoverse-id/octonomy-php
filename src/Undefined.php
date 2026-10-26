<?php

declare(strict_types=1);

namespace Octoverse\Octonomy;

/**
 * Undefined::VALUE is the default of every string field on an Update DTO, and
 * means "leave this field alone". It gives a PATCH field its third state:
 *
 *   description: Undefined::VALUE  ->  the key is omitted, the field is unchanged
 *   description: null              ->  {"description": null}, which clears it
 *   description: 'text'            ->  {"description": "text"}
 *
 * Before it, null had to mean "leave it alone", so the SDK could not send the
 * null that clears a field: TagUpdate(name: 'Autumn', description: null) sent
 * {"name": "Autumn"}, and the server answered 200 with the description
 * unchanged (#20).
 *
 * A string constant, not an enum, because the 1.x line is downgraded to PHP 7.4
 * and Rector cannot carry an enum there (issue #5): it rewrote an enum case to
 * the string 'value', which a default PATCH then sent as every field's value.
 * Compare with === or !==, never instanceof.
 *
 * The value starts with a NUL byte, which no string field can hold on the
 * server: Octonomy stores them in PostgreSQL text columns, and PostgreSQL
 * refuses NUL in text. So it cannot collide with a value a caller could
 * actually store; passing it explicitly means "omit", the same as passing
 * nothing.
 */
final class Undefined
{
    public const VALUE = "\0octonomy:undefined";

    private function __construct() {}
}
