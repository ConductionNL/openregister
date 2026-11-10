<?php

/**
 * OpenRegister register access audit
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Service\AuthorizationAuditService;
use OCA\OpenRegister\Service\AuthorizationChange;

/**
 * Logs what an update changed in a register's authorization and roles.
 *
 * Built before the update is applied: it keeps the values the register held.
 *
 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-7
 */
final class RegisterAccessAudit {
	/**
	 * The authorization the register held.
	 *
	 * @var array|null
	 */
	private readonly ?array $authorization;

	/**
	 * The roles the register held.
	 *
	 * @var mixed
	 */
	private readonly mixed $roles;

	/**
	 * Keep the register's access settings as they are before the update.
	 *
	 * @param RegisterUpdate $update The update.
	 * @param Register       $before The register as stored.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-7
	 */
	public function __construct(
		private readonly RegisterUpdate $update,
		Register $before,
	) {
		$this->authorization = $before->getAuthorization();
		$this->roles         = ($before->getConfiguration()['roles'] ?? null);
	}//end __construct()

	/**
	 * Log the changes the update asked for.
	 *
	 * @param AuthorizationAuditService $service The audit service.
	 * @param Register                  $after   The register as saved.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-7
	 */
	public function log(AuthorizationAuditService $service, Register $after): void {
		$this->logAuthorization(service: $service, after: $after);
		$this->logRoles(service: $service, after: $after);
	}//end log()

	/**
	 * Log the authorization change, when the update asked for one.
	 *
	 * @param AuthorizationAuditService $service The audit service.
	 * @param Register                  $after   The register as saved.
	 *
	 * @return void
	 */
	private function logAuthorization(AuthorizationAuditService $service, Register $after): void {
		if (isset($this->update->data['authorization']) === false) {
			return;
		}

		$service->recordRegisterChange(
			change: new AuthorizationChange(
				entityId: $this->update->id,
				entityTitle: $after->getTitle() ?? '',
				before: $this->authorization,
				after: $after->getAuthorization()
			)
		);
	}//end logAuthorization()

	/**
	 * Log the role change, when the update asked for one.
	 *
	 * @param AuthorizationAuditService $service The audit service.
	 * @param Register                  $after   The register as saved.
	 *
	 * @return void
	 */
	private function logRoles(AuthorizationAuditService $service, Register $after): void {
		if (isset($this->update->data['configuration']['roles']) === false) {
			return;
		}

		$service->recordRoleChange(
			change: new AuthorizationChange(
				entityId: $this->update->id,
				entityTitle: $after->getTitle() ?? '',
				before: $this->roles,
				after: ($after->getConfiguration()['roles'] ?? null)
			)
		);
	}//end logRoles()
}//end class
