<?php

/**
 * One execution of an object-read step: the invocation, the owner whose rights
 * every read runs under, and the register and schema it reads from.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\IUser;

/**
 * The state every per-item read of one object-read step shares.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
final class ObjectReadRun {
	/**
	 * Describe the run.
	 *
	 * @param NodeInvocation $step     The items, the step configuration and the run metadata.
	 * @param IUser          $owner    The run owner; every read runs as this user.
	 * @param Register       $register The resolved register.
	 * @param Schema         $schema   The resolved schema.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function __construct(
		public readonly NodeInvocation $step,
		public readonly IUser $owner,
		public readonly Register $register,
		public readonly Schema $schema,
	) {
	}//end __construct()
}//end class
