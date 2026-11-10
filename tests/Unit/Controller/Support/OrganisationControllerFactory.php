<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\OrganisationController;

/**
 * Builds OrganisationController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class OrganisationControllerFactory {
	/**
	 * The constructor arguments of OrganisationController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param OrganisationService $organisationService Constructor argument.
	 * @param OrganisationMapper $organisationMapper Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 * @param TenantLifecycleService $tenantLifecycleService Constructor argument.
	 * @param TenantUsageMapper $tenantUsageMapper Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 * @param IGroupManager $groupManager Constructor argument.
	 * @param OrganisationUsageService $usageService Constructor argument.
	 * @param ?GrantChangeAuditor $grantAuditor Constructor argument.
	 * @param ?OrganisationLifecycleAuditor $lifecycleAuditor Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\OrganisationService $organisationService,
		\OCA\OpenRegister\Db\OrganisationMapper $organisationMapper,
		\Psr\Log\LoggerInterface $logger,
		\OCA\OpenRegister\Service\TenantLifecycleService $tenantLifecycleService,
		\OCA\OpenRegister\Db\TenantUsageMapper $tenantUsageMapper,
		\OCP\IUserSession $userSession,
		\OCP\IGroupManager $groupManager,
		\OCA\OpenRegister\Service\OrganisationUsageService $usageService,
		?\OCA\OpenRegister\Service\Rbac\GrantChangeAuditor $grantAuditor = null,
		?\OCA\OpenRegister\Service\Organisation\OrganisationLifecycleAuditor $lifecycleAuditor = null,
	): array {
		unset($appName);
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\OrganisationControllerServices(
				session: new \OCA\OpenRegister\Controller\Support\ControllerSession(
					userSession: $userSession,
					groupManager: $groupManager,
					logger: $logger ?? new \Psr\Log\NullLogger(),
				),
				mappers: new \OCA\OpenRegister\Controller\Support\OrganisationMappers(
					organisationMapper: $organisationMapper,
					tenantUsageMapper: $tenantUsageMapper,
				),
				domain: new \OCA\OpenRegister\Controller\Support\OrganisationDomain(
					organisationService: $organisationService,
					tenantLifecycle: $tenantLifecycleService,
					usageService: $usageService,
					grantAuditor: $grantAuditor,
				),
				auditing: new \OCA\OpenRegister\Controller\Support\OrganisationAuditing(
					lifecycleAuditor: $lifecycleAuditor,
				),
			),
		];
	}

	/**
	 * Build OrganisationController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return OrganisationController
	 */
	public static function create(mixed ...$args): OrganisationController {
		return new OrganisationController(...self::args(...$args));
	}
}
