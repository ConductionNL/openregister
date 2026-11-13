<?php

/**
 * OpenRegister UnknownMaskFormException
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use InvalidArgumentException;

/**
 * A file settings save named a mask form that does not exist, or a keep count below 1.
 *
 * Answered with 400 naming the entity type, so an administrator sees which entry to fix.
 *
 * @spec openspec/changes/anonymisation-placeholder-id-scope/specs/entity-relation-grondslagen/spec.md
 */
class UnknownMaskFormException extends InvalidArgumentException {
}//end class
