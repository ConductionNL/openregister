<?php
/**
 * ActionRightCheckInterface: whether a user holds a named action right, as a guarded act asks it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

use OCP\IUser;

/**
 * Refuses a user who does not hold a named right of Open Register's action matrix.
 *
 * An act that is granted narrowly (correcting a record, for one) asks before it
 * runs; the action matrix (Platform) answers and binds itself to this contract
 * in its own wiring.
 *
 * @spec openspec/specs/enhanced-audit-trail/spec.md
 */
interface ActionRightCheckInterface {

	/**
	 * Require that the user holds the named right. Administrators always do.
	 *
	 * @param IUser  $user   The authenticated user.
	 * @param string $action The dot-separated right, e.g. `object.correct`.
	 *
	 * @return void
	 *
	 * @throws \OCP\AppFramework\OCS\OCSForbiddenException When the user does not hold the right.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function requireAction(IUser $user, string $action): void;
}//end interface
