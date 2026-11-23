<?php

/**
 * OpenRegister ObjectFindTarget
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
 * @spec exclude Value object of the ObjectService::find() facade; read behaviour owned by object-interactions.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Value;

/**
 * The published ObjectFindTarget, under the name openregister used before it was published.
 *
 * @deprecated Use \OCA\OpenRegister\Contract\ObjectFindTarget, the published contract (task 7.2).
 *             This name keeps every construction, instanceof and type hint working.
 *
 * @spec exclude Value object of the ObjectService::find() facade; read behaviour owned by object-interactions.
 */
final class ObjectFindTarget extends \OCA\OpenRegister\Contract\ObjectFindTarget {
}//end class
