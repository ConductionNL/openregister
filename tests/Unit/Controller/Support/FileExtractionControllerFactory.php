<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Files\Controller\FileExtractionController;

/**
 * Builds FileExtractionController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class FileExtractionControllerFactory {
	/**
	 * The constructor arguments of FileExtractionController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param TextExtractionService $textExtractor Constructor argument.
	 * @param VectorizationServiceInterface $vectorizationService Constructor argument.
	 * @param ChunkMapper $chunkMapper Constructor argument.
	 * @param EntityRelationMapper $entityRelationMapper Constructor argument.
	 * @param RiskLevelService $riskLevelService Constructor argument.
	 * @param IRootFolder $rootFolder Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 * @param IGroupManager $groupManager Constructor argument.
	 * @param ?FileReadScope $fileReadScope Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Files\Service\TextExtractionService $textExtractor,
		\OCA\OpenRegister\Search\Service\VectorizationServiceInterface $vectorizationService,
		\OCA\OpenRegister\Files\Db\ChunkMapper $chunkMapper,
		\OCA\OpenRegister\Db\EntityRelationMapper $entityRelationMapper,
		\OCA\OpenRegister\Files\Service\RiskLevelService $riskLevelService,
		\OCP\Files\IRootFolder $rootFolder,
		\OCP\IUserSession $userSession,
		\OCP\IGroupManager $groupManager,
		?\OCA\OpenRegister\Files\Service\File\FileReadScope $fileReadScope = null,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Files\Controller\Support\FileExtractionControllerServices(
				mappers: new \OCA\OpenRegister\Files\Controller\Support\FileExtractionMappers(
					chunkMapper: $chunkMapper,
					entityRelationMapper: $entityRelationMapper,
				),
				domain: new \OCA\OpenRegister\Files\Controller\Support\FileExtractionDomain(
					textExtractor: $textExtractor,
					vectorizationService: $vectorizationService,
					riskLevelService: $riskLevelService,
					fileReadScope: $fileReadScope,
				),
				platform: new \OCA\OpenRegister\Files\Controller\Support\FileExtractionPlatform(
					rootFolder: $rootFolder,
					userSession: $userSession,
					groupManager: $groupManager,
				),
			),
		];
	}

	/**
	 * Build FileExtractionController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return FileExtractionController
	 */
	public static function create(mixed ...$args): FileExtractionController {
		return new FileExtractionController(...self::args(...$args));
	}
}
