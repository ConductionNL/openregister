<?php

/**
 * Raised when a schema's database object source carries a query that is not a single read.
 *
 * Its own type rather than a bare InvalidArgumentException so Schema::hydrate()
 * re-throws it instead of saving the configuration as null: a dropped object
 * source would leave the schema reading as an ordinary, empty, writable one.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/dbal-query-schema-guarded-and-previewed/specs/dbal-virtual-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use InvalidArgumentException;

/**
 * A refused `x-openregister-object-source.config.query`.
 *
 * @spec openspec/changes/dbal-query-schema-guarded-and-previewed/specs/dbal-virtual-registers/spec.md
 */
class ObjectSourceQueryRefusedException extends InvalidArgumentException {

}//end class
