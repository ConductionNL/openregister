<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\PermissionsAuditController;

/**
 * Builds PermissionsAuditController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class PermissionsAuditControllerFactory {
	/**
	 * The constructor arguments of PermissionsAuditController.
	 *
	 * @param string $appName Ignored: the controller uses Application::APP_ID.
	 * @param IRequest $request Constructor argument.
	 * @param PermissionCatalogue $catalogue Constructor argument.
	 * @param DenyEnforcementMode $enforcement Constructor argument.
	 * @param DenyResolver $denyResolver Constructor argument.
	 * @param PermissionReportScope $scope Constructor argument.
	 * @param ScopeAudit $audit Constructor argument.
	 * @param AdminGate $adminGate Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\Rbac\PermissionCatalogue $catalogue,
		\OCA\OpenRegister\Service\Rbac\DenyEnforcementMode $enforcement,
		\OCA\OpenRegister\Service\Rbac\DenyResolver $denyResolver,
		\OCA\OpenRegister\Service\Rbac\PermissionReportScope $scope,
		\OCA\OpenRegister\Service\Rbac\ScopeAudit $audit = new ScopeAudit(),
		\OCA\OpenRegister\Service\Rbac\AdminGate $adminGate = new AdminGate(),
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\PermissionsAuditControllerServices(
				rules: new \OCA\OpenRegister\Controller\Support\PermissionsAuditRules(
					catalogue: $catalogue,
					enforcement: $enforcement,
					denyResolver: $denyResolver,
					scope: $scope,
				),
				scope: new \OCA\OpenRegister\Controller\Support\PermissionsAuditScope(
					audit: $audit,
					adminGate: $adminGate,
				),
			),
		];
	}

	/**
	 * Build PermissionsAuditController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return PermissionsAuditController
	 */
	public static function create(mixed ...$args): PermissionsAuditController {
		return new PermissionsAuditController(...self::args(...$args));
	}
}
