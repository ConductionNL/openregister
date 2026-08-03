<?php

/**
 * OpenRegister UnknownMetadataFieldException
 *
 * This file contains the exception class raised when a `@self` filter names a
 * metadata field that no magic-table metadata column corresponds to.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Exception;

use Exception;
use Throwable;

/**
 * Exception thrown when a `@self` filter names an unknown metadata field.
 *
 * Metadata filters resolve to an underscore-prefixed magic-table column, so a
 * name with no matching column cannot be answered. Before this exception the
 * name was concatenated straight into the SQL, which surfaced either as an
 * opaque HTTP 500 from the database driver or — worse, on some column types —
 * as an empty result set indistinguishable from "nothing matched".
 *
 * Failing here names the offending field and lists what is filterable, so the
 * caller can correct the query instead of interpreting a silent zero.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-self-metadata-filters-support-comparison-operators
 */
class UnknownMetadataFieldException extends Exception
{
    /**
     * Constructor for UnknownMetadataFieldException.
     *
     * @param string         $field    The `@self` field name that could not be resolved.
     * @param string[]       $known    The metadata column names that are filterable.
     * @param int            $code     The error code (default: 400).
     * @param Throwable|null $previous The previous exception that caused this one.
     *
     * @return void
     */
    public function __construct(
        private readonly string $field,
        private readonly array $known=[],
        int $code=400,
        ?Throwable $previous=null
    ) {
        // Report the caller-facing names (no leading underscore), sorted, so the
        // message is a usable correction rather than an internal column dump.
        $names = array_map(
            static fn (string $column): string => ltrim($column, '_'),
            $known
        );
        sort($names);

        parent::__construct(
            message: sprintf(
                'Unknown "@self" metadata field "%s". Filterable metadata fields are: %s.',
                $field,
                implode(', ', $names)
            ),
            code: $code,
            previous: $previous
        );
    }//end __construct()

    /**
     * The `@self` field name that triggered this exception.
     *
     * @return string The field name.
     *
     * @spec openspec/specs/zoeken-filteren/spec.md#requirement-self-metadata-filters-support-comparison-operators
     */
    public function getField(): string
    {
        return $this->field;
    }//end getField()

    /**
     * The metadata column names that are filterable.
     *
     * @return string[] The known metadata column names.
     *
     * @spec openspec/specs/zoeken-filteren/spec.md#requirement-self-metadata-filters-support-comparison-operators
     */
    public function getKnown(): array
    {
        return $this->known;
    }//end getKnown()
}//end class
