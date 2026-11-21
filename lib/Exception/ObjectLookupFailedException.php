<?php

/**
 * Looking up a stored object failed, so whether it exists is unknown.
 *
 * Distinct from DoesNotExistException: a caller that treats "not found" as
 * permission to go ahead (a save that then creates, an existence check that
 * then passes) must not get that answer when the lookup itself failed.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/specs/magic-table-reads/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use RuntimeException;

/**
 * A stored-object lookup failed for a reason other than the object being absent.
 *
 * @spec openspec/specs/magic-table-reads/spec.md
 */
class ObjectLookupFailedException extends RuntimeException {
}//end class
