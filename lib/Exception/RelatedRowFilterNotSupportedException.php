<?php

/**
 * OpenRegister RelatedRowFilterNotSupportedException
 *
 * Raised when a `_related` or `_referenced` filter reaches a raw-SQL route that
 * cannot honour it (the UNION search and UNION facet builders).
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/query-referenced-schema-rows/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use InvalidArgumentException;

/**
 * A related-row filter on a route that would drop it.
 *
 * Dropping it answers the unfiltered set to a deliberately narrow question, so
 * the route refuses; the middleware turns the refusal into an HTTP 400.
 *
 * @spec openspec/changes/query-referenced-schema-rows/specs/zoeken-filteren/spec.md
 */
class RelatedRowFilterNotSupportedException extends InvalidArgumentException {
}//end class
