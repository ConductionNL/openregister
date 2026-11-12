<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\ObjectGrantsController;

/**
 * Builds ObjectGrantsController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class ObjectGrantsControllerFactory {
	/**
	 * The constructor arguments of ObjectGrantsController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param ObjectOperationsInterface $objectService Constructor argument.
	 * @param AccessReviewGateInterface $gate Constructor argument.
	 * @param ObjectGrantsReportInterface $report Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface $objectService,
		\OCA\OpenRegister\Service\Rbac\AccessReviewGateInterface $gate,
		\OCA\OpenRegister\Service\Rbac\ObjectGrantsReportInterface $report,
		\Psr\Log\LoggerInterface $logger,
	): array {
		return [
			$appName,
			$request,
			new \OCA\OpenRegister\Controller\Support\ObjectGrantsControllerServices(
				objectService: $objectService,
				gate: $gate,
				report: $report,
			),
			$logger,
		];
	}

	/**
	 * Build ObjectGrantsController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return ObjectGrantsController
	 */
	public static function create(mixed ...$args): ObjectGrantsController {
		return new ObjectGrantsController(...self::args(...$args));
	}
}
