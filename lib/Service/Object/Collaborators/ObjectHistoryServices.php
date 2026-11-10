<?php

/**
 * OpenRegister ObjectService history services: locks, audit trail, revert and lifecycle moves
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\Collaborators
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\Collaborators;

use OCA\OpenRegister\Service\Lifecycle\AutoTransitionPass;
use OCA\OpenRegister\Service\Object\AuditHandler;
use OCA\OpenRegister\Service\Object\LockHandler;
use OCA\OpenRegister\Service\Object\RevertHandler;

/**
 * ObjectService history services: locks, audit trail, revert and lifecycle moves.
 *
 * @spec openspec/specs/objects-crud/spec.md
 */
final class ObjectHistoryServices {
	/**
	 * Bundle the services.
	 *
	 * @param LockHandler             $lockHandler     Handler for object locking.
	 * @param AuditHandler            $auditHandler    Handler for audit trail operations.
	 * @param RevertHandler           $revertHandler   Handler for revert operations.
	 * @param AutoTransitionPass|null $autoTransitions Request-scoped pass applying automatic lifecycle moves.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function __construct(
		public readonly LockHandler $lockHandler,
		public readonly AuditHandler $auditHandler,
		public readonly RevertHandler $revertHandler,
		public readonly ?AutoTransitionPass $autoTransitions = null,
	) {
	}//end __construct()
}//end class
