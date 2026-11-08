<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\TagsController;

/**
 * Builds TagsController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class TagsControllerFactory {
	/**
	 * The constructor arguments of TagsController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param ObjectService $objectService Constructor argument.
	 * @param FileServiceInterface $fileService Constructor argument.
	 * @param TaggingHandlerInterface $taggingHandler Constructor argument.
	 * @param ?IUserSession $userSession Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\ObjectService $objectService,
		\OCA\OpenRegister\Service\FileServiceInterface $fileService,
		\OCA\OpenRegister\Kernel\Contracts\TaggingHandlerInterface $taggingHandler,
		?\OCP\IUserSession $userSession = null,
	): array {
		return [
			$appName,
			$request,
			new \OCA\OpenRegister\Controller\Support\TagsControllerServices(
				objectService: $objectService,
				fileService: $fileService,
				taggingHandler: $taggingHandler,
			),
			$userSession,
		];
	}

	/**
	 * Build TagsController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return TagsController
	 */
	public static function create(mixed ...$args): TagsController {
		return new TagsController(...self::args(...$args));
	}
}
