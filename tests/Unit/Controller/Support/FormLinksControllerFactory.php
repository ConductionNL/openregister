<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\FormLinksController;

/**
 * Builds FormLinksController from its services as separate arguments, wrapped into its constructor bundle.
 */
final class FormLinksControllerFactory {
	/**
	 * The constructor arguments of FormLinksController.
	 *
	 * @param string $appName Ignored: the controller uses Application::APP_ID.
	 * @param IRequest $request Constructor argument.
	 * @param FormLinkServiceInterface $formLinkService Constructor argument.
	 * @param ObjectOperationsInterface $objectService Constructor argument.
	 * @param IL10N $l10n Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\FormLinkServiceInterface $formLinkService,
		\OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface $objectService,
		\OCP\IL10N $l10n,
		\Psr\Log\LoggerInterface $logger,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\FormLinksControllerServices(
				formLinkService: $formLinkService,
				objectService: $objectService,
				l10n: $l10n,
				logger: $logger,
			),
		];
	}//end args()

	/**
	 * Build FormLinksController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return FormLinksController
	 */
	public static function create(mixed ...$args): FormLinksController {
		return new FormLinksController(...self::args(...$args));
	}//end create()
}//end class
