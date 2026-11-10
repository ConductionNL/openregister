<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\ActivityLinksController;

/**
 * Builds ActivityLinksController from its services as separate arguments, wrapped into its constructor bundle.
 */
final class ActivityLinksControllerFactory {
	/**
	 * The constructor arguments of ActivityLinksController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param ActivityFilterServiceInterface $filterService Constructor argument.
	 * @param ObjectOperationsInterface $objectService Constructor argument.
	 * @param TimelineVisibilityServiceInterface $visibility Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\ActivityFilterServiceInterface $filterService,
		\OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface $objectService,
		\OCA\OpenRegister\Notifications\Service\TimelineVisibilityServiceInterface $visibility,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\ActivityLinksControllerServices(
				filterService: $filterService,
				objectService: $objectService,
				visibility: $visibility,
			),
		];
	}//end args()

	/**
	 * Build ActivityLinksController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return ActivityLinksController
	 */
	public static function create(mixed ...$args): ActivityLinksController {
		return new ActivityLinksController(...self::args(...$args));
	}//end create()
}//end class
