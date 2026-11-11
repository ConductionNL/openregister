<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\ObjectActionsController;

/**
 * Builds ObjectActionsController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class ObjectActionsControllerFactory {
	/**
	 * The constructor arguments of ObjectActionsController.
	 *
	 * @param IRequest $request Constructor argument.
	 * @param ObjectService $objects Constructor argument.
	 * @param MacroActionResolver $macros Constructor argument.
	 * @param ObjectActionDefinitions $definitions Constructor argument.
	 * @param ObjectActionList $list Constructor argument.
	 * @param ObjectActionRunner $runner Constructor argument.
	 * @param ObjectActionHistory $history Constructor argument.
	 * @param ObjectActionBulk $bulk Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\ObjectService $objects,
		\OCA\OpenRegister\Service\Flow\MacroActionResolver $macros,
		\OCA\OpenRegister\Service\ObjectAction\ObjectActionDefinitions $definitions,
		\OCA\OpenRegister\Service\ObjectAction\ObjectActionList $list,
		\OCA\OpenRegister\Service\ObjectAction\ObjectActionRunner $runner,
		\OCA\OpenRegister\Service\ObjectAction\ObjectActionHistory $history,
		\OCA\OpenRegister\Service\ObjectAction\ObjectActionBulk $bulk,
		\Psr\Log\LoggerInterface $logger,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\ObjectActionsControllerServices(
				catalogue: new \OCA\OpenRegister\Controller\Support\ObjectActionsCatalogue(
					objects: $objects,
					macros: $macros,
					definitions: $definitions,
					list: $list,
				),
				execution: new \OCA\OpenRegister\Controller\Support\ObjectActionsExecution(
					runner: $runner,
					history: $history,
					bulk: $bulk,
				),
				platform: new \OCA\OpenRegister\Controller\Support\ConversationPlatform(
					logger: $logger,
				),
			),
		];
	}

	/**
	 * Build ObjectActionsController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return ObjectActionsController
	 */
	public static function create(mixed ...$args): ObjectActionsController {
		return new ObjectActionsController(...self::args(...$args));
	}
}
