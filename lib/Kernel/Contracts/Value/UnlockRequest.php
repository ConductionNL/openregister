<?php

/**
 * UnlockRequest: what a caller asks for when it releases an object lock.
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
 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-is-released-through-its-own-endpoint-and-a-release-says-whether-there-was-one
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Value;

/**
 * The published UnlockRequest, under the name openregister used before it was published.
 *
 * @deprecated Use \OCA\OpenRegister\Contract\UnlockRequest, the published contract (task 7.2).
 *             This name keeps every construction, instanceof and type hint working.
 *
 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-is-released-through-its-own-endpoint-and-a-release-says-whether-there-was-one
 */
final class UnlockRequest extends \OCA\OpenRegister\Contract\UnlockRequest {
}//end class
