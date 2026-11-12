<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\ObjectPermissionsController;

/**
 * Builds ObjectPermissionsController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class ObjectPermissionsControllerFactory {
	/**
	 * The constructor arguments of ObjectPermissionsController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param ObjectOperationsInterface $objectService Constructor argument.
	 * @param ObjectAccessReportInterface $report Constructor argument.
	 * @param AccessReviewGateInterface $gate Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface $objectService,
		\OCA\OpenRegister\Service\Rbac\ObjectAccessReportInterface $report,
		\OCA\OpenRegister\Service\Rbac\AccessReviewGateInterface $gate,
		\Psr\Log\LoggerInterface $logger,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\ObjectPermissionsControllerServices(
				objectService: $objectService,
				report: $report,
				gate: $gate,
				logger: $logger,
			),
		];
	}

	/**
	 * Build ObjectPermissionsController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return ObjectPermissionsController
	 */
	public static function create(mixed ...$args): ObjectPermissionsController {
		return new ObjectPermissionsController(...self::args(...$args));
	}
}
