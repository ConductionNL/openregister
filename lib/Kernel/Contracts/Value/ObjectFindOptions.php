<?php

/**
 * ObjectFindOptions: where to look for one object and what to return with it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Value
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Value;

/**
 * The published ObjectFindOptions, under the name openregister used before it was published.
 *
 * @deprecated Use \OCA\OpenRegister\Contract\ObjectFindOptions, the published contract (task 7.2).
 *             This name keeps every construction, instanceof and type hint working.
 *
 * @spec exclude ADR-011 parameter object of the object read, not a capability of its own
 */
final class ObjectFindOptions extends \OCA\OpenRegister\Contract\ObjectFindOptions {
}//end class
