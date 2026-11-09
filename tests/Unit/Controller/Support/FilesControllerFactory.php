<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Files\Controller\FilesController;

/**
 * Builds FilesController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class FilesControllerFactory {
	/**
	 * The constructor arguments of FilesController.
	 *
	 * @param IRequest $request Constructor argument.
	 * @param FileService $fileService Constructor argument.
	 * @param ObjectService $objectService Constructor argument.
	 * @param IRootFolder $rootFolder Constructor argument.
	 * @param IUserManager $userManager Constructor argument.
	 * @param IEventDispatcher $eventDispatcher Constructor argument.
	 * @param ?FileMapper $fileMapper Constructor argument.
	 * @param ?FileAuditHandler $fileAuditHandler Constructor argument.
	 * @param ?IUserSession $userSession Constructor argument.
	 * @param ?IL10N $l10n Constructor argument.
	 * @param ?ObjectFileAccess $objectFileAccess Constructor argument.
	 * @param ?OfficeSessionService $officeSessionService Constructor argument.
	 * @param ?AttachNodeHandler $attachNodeHandler Constructor argument.
	 * @param ?FileWriteGuard $fileWriteGuard Constructor argument.
	 * @param ?FileRestrictionGuard $fileRestrictions Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\FileService $fileService,
		\OCA\OpenRegister\Service\ObjectService $objectService,
		\OCP\Files\IRootFolder $rootFolder,
		\OCP\IUserManager $userManager,
		\OCP\EventDispatcher\IEventDispatcher $eventDispatcher,
		?\OCA\OpenRegister\Files\Db\FileMapper $fileMapper = null,
		?\OCA\OpenRegister\Files\Service\File\FileAuditHandler $fileAuditHandler = null,
		?\OCP\IUserSession $userSession = null,
		?\OCP\IL10N $l10n = null,
		?\OCA\OpenRegister\Files\Service\File\ObjectFileAccess $objectFileAccess = null,
		?\OCA\OpenRegister\Files\Service\File\OfficeSessionService $officeSessionService = null,
		?\OCA\OpenRegister\Files\Service\File\AttachNodeHandler $attachNodeHandler = null,
		?\OCA\OpenRegister\Service\Object\FileWriteGuard $fileWriteGuard = null,
		?\OCA\OpenRegister\Files\Service\File\FileRestrictionGuard $fileRestrictions = null,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Files\Controller\Support\FilesControllerServices(
				store: new \OCA\OpenRegister\Files\Controller\Support\FileStore(
					fileService: $fileService,
					objectService: $objectService,
					rootFolder: $rootFolder,
					fileMapper: $fileMapper,
				),
				platform: new \OCA\OpenRegister\Files\Controller\Support\FilePlatform(
					userManager: $userManager,
					eventDispatcher: $eventDispatcher,
					userSession: $userSession,
					l10n: $l10n,
				),
				guards: new \OCA\OpenRegister\Files\Controller\Support\FileGuards(
					objectFileAccess: $objectFileAccess,
					fileWriteGuard: $fileWriteGuard,
					fileRestrictions: $fileRestrictions,
					fileAuditHandler: $fileAuditHandler,
				),
				editing: new \OCA\OpenRegister\Files\Controller\Support\FileEditing(
					officeSessionService: $officeSessionService,
					attachNodeHandler: $attachNodeHandler,
				),
			),
		];
	}

	/**
	 * Build FilesController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return FilesController
	 */
	public static function create(mixed ...$args): FilesController {
		return new FilesController(...self::args(...$args));
	}
}
