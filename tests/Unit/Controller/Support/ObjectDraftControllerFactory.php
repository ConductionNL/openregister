<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\ObjectDraftController;

/**
 * Builds ObjectDraftController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class ObjectDraftControllerFactory {
	/**
	 * The constructor arguments of ObjectDraftController.
	 *
	 * @param string $appName Ignored: the controller uses Application::APP_ID.
	 * @param IRequest $request Constructor argument.
	 * @param ObjectService $objects Constructor argument.
	 * @param RegisterMapper $registers Constructor argument.
	 * @param SchemaMapper $schemas Constructor argument.
	 * @param DraftActivation $activation Constructor argument.
	 * @param IUserSession $session Constructor argument.
	 * @param IL10N $l10n Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 * @param IGroupManager $groupManager Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\ObjectService $objects,
		\OCA\OpenRegister\Db\RegisterMapper $registers,
		\OCA\OpenRegister\Db\SchemaMapper $schemas,
		\OCA\OpenRegister\Service\Object\DraftActivation $activation,
		\OCP\IUserSession $session,
		\OCP\IL10N $l10n,
		\Psr\Log\LoggerInterface $logger,
		\OCP\IGroupManager $groupManager,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\ObjectDraftControllerServices(
				domain: new \OCA\OpenRegister\Controller\Support\ObjectDraftDomain(
					registers: $registers,
					schemas: $schemas,
					objects: $objects,
					activation: $activation,
				),
				platform: new \OCA\OpenRegister\Controller\Support\ObjectDraftPlatform(
					session: new \OCA\OpenRegister\Controller\Support\ControllerSession(
						userSession: $session,
						groupManager: $groupManager,
						logger: $logger,
					),
					l10n: $l10n,
				),
			),
		];
	}

	/**
	 * Build ObjectDraftController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return ObjectDraftController
	 */
	public static function create(mixed ...$args): ObjectDraftController {
		return new ObjectDraftController(...self::args(...$args));
	}
}
