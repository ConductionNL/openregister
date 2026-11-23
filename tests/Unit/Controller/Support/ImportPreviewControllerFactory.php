<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\ImportPreviewController;

/**
 * Builds ImportPreviewController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class ImportPreviewControllerFactory {
	/**
	 * The constructor arguments of ImportPreviewController.
	 *
	 * @param string $appName Ignored: the controller uses Application::APP_ID.
	 * @param IRequest $request Constructor argument.
	 * @param ImportPreviewService $service Constructor argument.
	 * @param ImportPreviewMapper $previewMapper Constructor argument.
	 * @param ImportPreviewRowMapper $rowMapper Constructor argument.
	 * @param SourceRowReader $reader Constructor argument.
	 * @param RegisterMapper $registerMapper Constructor argument.
	 * @param IJobList $jobList Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 * @param IGroupManager $groupManager Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\Import\ImportPreviewService $service,
		\OCA\OpenRegister\Db\ImportPreviewMapper $previewMapper,
		\OCA\OpenRegister\Db\ImportPreviewRowMapper $rowMapper,
		\OCA\OpenRegister\Service\Import\SourceRowReader $reader,
		\OCA\OpenRegister\Db\RegisterMapper $registerMapper,
		\OCP\BackgroundJob\IJobList $jobList,
		\OCP\IUserSession $userSession,
		\OCP\IGroupManager $groupManager,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\ImportPreviewControllerServices(
				mappers: new \OCA\OpenRegister\Controller\Support\ImportPreviewMappers(
					previewMapper: $previewMapper,
					rowMapper: $rowMapper,
					registerMapper: $registerMapper,
				),
				domain: new \OCA\OpenRegister\Controller\Support\ImportPreviewDomain(
					service: $service,
					reader: $reader,
				),
				platform: new \OCA\OpenRegister\Controller\Support\ImportPreviewPlatform(
					jobList: $jobList,
					principal: new \OCA\OpenRegister\Controller\Support\SessionPrincipal(userSession: $userSession, groupManager: $groupManager),
				),
			),
		];
	}

	/**
	 * Build ImportPreviewController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return ImportPreviewController
	 */
	public static function create(mixed ...$args): ImportPreviewController {
		return new ImportPreviewController(...self::args(...$args));
	}
}
