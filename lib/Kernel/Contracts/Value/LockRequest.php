<?php

/**
 * LockRequest: what a caller asks for when it takes an object lock.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Kernel\Contracts\Value
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Value;

/**
 * The published LockRequest, under the name openregister used before it was published.
 *
 * @deprecated Use \OCA\OpenRegister\Contract\LockRequest, the published contract (task 7.2).
 *             This name keeps every construction, instanceof and type hint working.
 */
final class LockRequest extends \OCA\OpenRegister\Contract\LockRequest {
}//end class
