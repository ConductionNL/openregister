<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\ContentReportController;

/**
 * Builds ContentReportController from its services as separate arguments, wrapped into its constructor bundle.
 */
final class ContentReportControllerFactory {
	/**
	 * The constructor arguments of ContentReportController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param ContentReportMapper $reports Constructor argument.
	 * @param ContentReportService $service Constructor argument.
	 * @param MagicObjectFinderInterface $objects Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Db\ContentReportMapper $reports,
		\OCA\OpenRegister\Service\Audit\ContentReportService $service,
		\OCA\OpenRegister\Db\MagicMapper\MagicObjectFinderInterface $objects,
		\OCP\IUserSession $userSession,
	): array {
		return [
			$appName,
			$request,
			new \OCA\OpenRegister\Controller\Support\ContentReportControllerServices(
				reports: $reports,
				service: $service,
				objects: $objects,
				userSession: $userSession,
			),
		];
	}//end args()

	/**
	 * Build ContentReportController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return ContentReportController
	 */
	public static function create(mixed ...$args): ContentReportController {
		return new ContentReportController(...self::args(...$args));
	}//end create()
}//end class
