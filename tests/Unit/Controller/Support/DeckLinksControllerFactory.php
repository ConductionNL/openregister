<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\DeckLinksController;

/**
 * Builds DeckLinksController from its services as separate arguments, wrapped into its constructor bundle.
 */
final class DeckLinksControllerFactory {
	/**
	 * The constructor arguments of DeckLinksController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param DeckLinkServiceInterface $deckLinkService Constructor argument.
	 * @param ObjectOperationsInterface $objectService Constructor argument.
	 * @param AppSettingsInterface $settingsService Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\DeckLinkServiceInterface $deckLinkService,
		\OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface $objectService,
		\OCA\OpenRegister\Kernel\Contracts\AppSettingsInterface $settingsService,
	): array {
		return [
			$appName,
			$request,
			new \OCA\OpenRegister\Controller\Support\DeckLinksControllerServices(
				deckLinkService: $deckLinkService,
				objectService: $objectService,
				settingsService: $settingsService,
			),
		];
	}//end args()

	/**
	 * Build DeckLinksController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return DeckLinksController
	 */
	public static function create(mixed ...$args): DeckLinksController {
		return new DeckLinksController(...self::args(...$args));
	}//end create()
}//end class
