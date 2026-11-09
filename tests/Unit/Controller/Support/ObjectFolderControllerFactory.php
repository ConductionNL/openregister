<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Files\Controller\ObjectFolderController;

/**
 * Builds ObjectFolderController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class ObjectFolderControllerFactory {
	/**
	 * The constructor arguments of ObjectFolderController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param ObjectFileAccess $objectFileAccess Constructor argument.
	 * @param ObjectFolderBrowser $browser Constructor argument.
	 * @param ObjectFolderUploads $uploads Constructor argument.
	 * @param ObjectFolderResponder $responder Constructor argument.
	 * @param FileWriteGuard $fileWriteGuard Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Files\Service\File\ObjectFileAccess $objectFileAccess,
		\OCA\OpenRegister\Files\Service\File\ObjectFolderBrowser $browser,
		\OCA\OpenRegister\Files\Service\File\ObjectFolderUploads $uploads,
		\OCA\OpenRegister\Files\Service\File\ObjectFolderResponder $responder,
		\OCA\OpenRegister\Service\Object\FileWriteGuard $fileWriteGuard,
	): array {
		return [
			$appName,
			$request,
			new \OCA\OpenRegister\Files\Controller\Support\ObjectFolderControllerServices(
				objectFileAccess: $objectFileAccess,
				browser: $browser,
				uploads: $uploads,
				responder: $responder,
			),
			$fileWriteGuard,
		];
	}

	/**
	 * Build ObjectFolderController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return ObjectFolderController
	 */
	public static function create(mixed ...$args): ObjectFolderController {
		return new ObjectFolderController(...self::args(...$args));
	}
}
