<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\SearchTrailController;

/**
 * Builds SearchTrailController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class SearchTrailControllerFactory {
	/**
	 * The constructor arguments of SearchTrailController.
	 *
	 * @param string $appName Ignored: the controller uses Application::APP_ID.
	 * @param IRequest $request Constructor argument.
	 * @param SearchTrailServiceInterface $searchTrailService Constructor argument.
	 * @param SearchTrailMapperInterface $searchTrailMapper Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 * @param IGroupManager $groupManager Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\SearchTrailServiceInterface $searchTrailService,
		\OCA\OpenRegister\Db\SearchTrailMapperInterface $searchTrailMapper,
		\OCP\IUserSession $userSession,
		\OCP\IGroupManager $groupManager,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\SearchTrailControllerServices(
				searchTrailService: $searchTrailService,
				searchTrailMapper: $searchTrailMapper,
				principal: new \OCA\OpenRegister\Controller\Support\SessionPrincipal(userSession: $userSession, groupManager: $groupManager),
			),
		];
	}

	/**
	 * Build SearchTrailController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return SearchTrailController
	 */
	public static function create(mixed ...$args): SearchTrailController {
		return new SearchTrailController(...self::args(...$args));
	}
}
