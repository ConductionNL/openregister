<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\SelectielijstController;

/**
 * Builds SelectielijstController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class SelectielijstControllerFactory {
	/**
	 * The constructor arguments of SelectielijstController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param SelectielijstImportServiceInterface $selectielijst Constructor argument.
	 * @param ArchivalSettingsReaderInterface $settingsHandler Constructor argument.
	 * @param ControllerSession $session Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\Archival\SelectielijstImportServiceInterface $selectielijst,
		\OCA\OpenRegister\Service\Settings\ArchivalSettingsReaderInterface $settingsHandler,
		\OCA\OpenRegister\Controller\Support\ControllerSession $session,
	): array {
		return [
			$appName,
			$request,
			new \OCA\OpenRegister\Controller\Support\SelectielijstControllerServices(
				selectielijst: $selectielijst,
				settingsHandler: $settingsHandler,
			),
			$session,
		];
	}

	/**
	 * Build SelectielijstController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return SelectielijstController
	 */
	public static function create(mixed ...$args): SelectielijstController {
		return new SelectielijstController(...self::args(...$args));
	}
}
