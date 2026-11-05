<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\FileTextController;

/**
 * Builds FileTextController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class FileTextControllerFactory {
	/**
	 * The constructor arguments of FileTextController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param TextExtractionService $textExtractor Constructor argument.
	 * @param FileService $fileService Constructor argument.
	 * @param EntityRelationMapper $entityRelationMapper Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 * @param ManualEntityService $manualEntityService Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 * @param IRootFolder $rootFolder Constructor argument.
	 * @param IGroupManager $groupManager Constructor argument.
	 * @param ?FileReadScope $fileReadScope Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\TextExtractionService $textExtractor,
		\OCA\OpenRegister\Service\FileService $fileService,
		\OCA\OpenRegister\Db\EntityRelationMapper $entityRelationMapper,
		\Psr\Log\LoggerInterface $logger,
		\OCA\OpenRegister\Service\File\ManualEntityService $manualEntityService,
		\OCP\IUserSession $userSession,
		\OCP\Files\IRootFolder $rootFolder,
		\OCP\IGroupManager $groupManager,
		?\OCA\OpenRegister\Service\File\FileReadScope $fileReadScope = null,
	): array {
		return [
			$appName,
			$request,
			new \OCA\OpenRegister\Controller\Support\FileTextControllerServices(
				session: new \OCA\OpenRegister\Controller\Support\ControllerSession(
					userSession: $userSession,
					groupManager: $groupManager,
					logger: $logger ?? new \Psr\Log\NullLogger(),
				),
				mappers: new \OCA\OpenRegister\Controller\Support\FileTextMappers(
					entityRelationMapper: $entityRelationMapper,
				),
				domain: new \OCA\OpenRegister\Controller\Support\FileTextDomain(
					textExtractor: $textExtractor,
					fileService: $fileService,
					manualEntityService: $manualEntityService,
					fileReadScope: $fileReadScope,
				),
				platform: new \OCA\OpenRegister\Controller\Support\FileTextPlatform(
					rootFolder: $rootFolder,
				),
			),
		];
	}

	/**
	 * Build FileTextController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return FileTextController
	 */
	public static function create(mixed ...$args): FileTextController {
		return new FileTextController(...self::args(...$args));
	}
}
