<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\FlowRunController;

/**
 * Builds FlowRunController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class FlowRunControllerFactory {
	/**
	 * The constructor arguments of FlowRunController.
	 *
	 * @param string $appName Ignored: the controller uses Application::APP_ID.
	 * @param IRequest $request Constructor argument.
	 * @param FlowRunMapper $mapper Constructor argument.
	 * @param FlowRunService $runner Constructor argument.
	 * @param FlowLocator $resolvers Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 * @param OrganisationService $organisationService Constructor argument.
	 * @param FlowRunnableGuard $guard Constructor argument.
	 * @param ?IGroupManager $groupManager Constructor argument.
	 * @param ?FlowCaller $flowOwnership Constructor argument.
	 * @param ?AuditFlowAttribution $auditTrails Constructor argument.
	 * @param ?FlowRunSignalService $signalService Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Db\FlowRunMapper $mapper,
		\OCA\OpenRegister\Service\Flow\FlowRunService $runner,
		\OCA\OpenRegister\Service\Flow\FlowLocator $resolvers,
		\OCP\IUserSession $userSession,
		\OCA\OpenRegister\Service\OrganisationService $organisationService,
		\OCA\OpenRegister\Service\Flow\FlowRunnableGuard $guard,
		?\OCP\IGroupManager $groupManager = null,
		?\OCA\OpenRegister\Service\Flow\FlowCaller $flowOwnership = null,
		?\OCA\OpenRegister\Db\AuditFlowAttribution $auditTrails = null,
		?\OCA\OpenRegister\Service\Flow\FlowRunSignalService $signalService = null,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\FlowRunControllerServices(
				runs: new \OCA\OpenRegister\Controller\Support\FlowRunRuns(
					mapper: $mapper,
					runner: $runner,
					resolvers: $resolvers,
					auditTrails: $auditTrails,
				),
				access: new \OCA\OpenRegister\Controller\Support\FlowRunAccess(
					organisationService: $organisationService,
					guard: $guard,
					flowOwnership: $flowOwnership,
					signalService: $signalService,
				),
				platform: new \OCA\OpenRegister\Controller\Support\FlowRunPlatform(
					userSession: $userSession,
					groupManager: $groupManager,
				),
			),
		];
	}

	/**
	 * Build FlowRunController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return FlowRunController
	 */
	public static function create(mixed ...$args): FlowRunController {
		return new FlowRunController(...self::args(...$args));
	}
}
