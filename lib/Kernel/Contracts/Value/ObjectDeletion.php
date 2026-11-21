<?php

/**
 * OpenRegister ObjectDeletion
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Kernel\Contracts\Value
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Value;

/**
 * The published ObjectDeletion, under the name openregister used before it was published.
 *
 * @deprecated Use \OCA\OpenRegister\Contract\ObjectDeletion, the published contract (task 7.2).
 *             This name keeps every construction, instanceof and type hint working.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class ObjectDeletion extends \OCA\OpenRegister\Contract\ObjectDeletion {
}//end class
