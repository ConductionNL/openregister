<?php
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The `objectConfiguration.handling` modes, in one place.
 *
 * @category  Db
 * @package   OCA\OpenRegister\Db
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * The `objectConfiguration.handling` modes for an object-typed property.
 *
 * Two of them mean "the target lives as its OWN object; store only a UUID reference
 * to it": `related-object` and `related-schema`. They are storage-identical — the
 * documentation defines `related-schema` as "stores object as a separate entity and
 * references it by UUID/ID only", which is exactly what `related-object` does.
 *
 * They drifted apart anyway: the write path only ever checked `related-object`, so a
 * property configured as `related-schema` — the mode the docs describe and the schema
 * editor offers — got a `json` COLUMN while the save path wrote a bare UUID STRING
 * into it. PostgreSQL rejected every such save with
 *
 *     SQLSTATE[22P02]: invalid input syntax for type json
 *     DETAIL: Token "b25f5f9c" is invalid.
 *
 * surfaced to the user as a bare "You do not have permission to perform this action".
 * Making a property a relation was impossible unless you happened to pick the one
 * undocumented handling value the code recognised.
 *
 * This class exists so that question is answered in ONE place. Add a new relating
 * mode here, not by grepping for `=== 'related-object'` across four services.
 *
 * @spec exclude value object enumerating objectConfiguration.handling modes
 */
final class ObjectHandling
{
    /**
     * Reference an existing object by UUID.
     */
    public const RELATED_OBJECT = 'related-object';

    /**
     * Reference an object of a given schema by UUID. Storage-identical to
     * {@see self::RELATED_OBJECT}; this is the mode the docs and the schema editor use.
     */
    public const RELATED_SCHEMA = 'related-schema';

    /**
     * Embed the object inline in the parent.
     */
    public const NESTED_OBJECT = 'nested-object';

    /**
     * Store the object separately but embed it in API responses.
     */
    public const NESTED_SCHEMA = 'nested-schema';

    /**
     * Reference the object by URI.
     */
    public const URI = 'uri';

    /**
     * Whether this handling stores only a UUID reference to a separately-stored object.
     *
     * A property whose handling relates gets a VARCHAR(255) UUID column, NOT a json
     * column — the value written is a bare UUID string, which is not valid JSON.
     *
     * @param string|null $handling The `objectConfiguration.handling` value.
     *
     * @return bool True when the property stores a UUID reference.
     *
     * @spec exclude pure predicate over the handling enum (no state, no I/O)
     */
    public static function relates(?string $handling): bool
    {
        return in_array($handling, [self::RELATED_OBJECT, self::RELATED_SCHEMA], true);
    }//end relates()
}//end class
