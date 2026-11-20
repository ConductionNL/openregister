<?php
/**
 * RegisterAccessChangeLogInterface: records a change to who may reach a register.
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

use OCA\OpenRegister\Service\AuthorizationChange;

/**
 * Records a register's authorization or role change after a register write.
 *
 * The register write paths (Core) tell; the authorization audit (Compliance)
 * records and is bound to this contract.
 *
 * @spec openspec/specs/rbac-scopes/spec.md#requirement-register-level-authorization-cascade
 */
interface RegisterAccessChangeLogInterface {

	/**
	 * Record a change to a register's authorization.
	 *
	 * @param AuthorizationChange $change The register and its old and new authorization.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md#requirement-register-level-authorization-cascade
	 */
	public function recordRegisterChange(AuthorizationChange $change): void;

	/**
	 * Record a change to a register's role definitions.
	 *
	 * @param AuthorizationChange $change The register and its old and new role definitions.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md#requirement-register-level-authorization-cascade
	 */
	public function recordRoleChange(AuthorizationChange $change): void;
}//end interface
