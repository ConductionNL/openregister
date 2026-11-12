<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\TranslationController;

/**
 * Builds TranslationController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class TranslationControllerFactory {
	/**
	 * The constructor arguments of TranslationController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param TranslationStatusService $statusService Constructor argument.
	 * @param TranslationMapper $translationMapper Constructor argument.
	 * @param SchemaMapperInterface $schemaMapper Constructor argument.
	 * @param BulkTranslationService $bulkService Constructor argument.
	 * @param MagicMapperInterface $objectMapper Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\TranslationStatusService $statusService,
		\OCA\OpenRegister\Db\TranslationMapper $translationMapper,
		\OCA\OpenRegister\Db\SchemaMapperInterface $schemaMapper,
		\OCA\OpenRegister\Service\BulkTranslationService $bulkService,
		\OCA\OpenRegister\Db\MagicMapperInterface $objectMapper,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\TranslationControllerServices(
				statusService: $statusService,
				bulkService: $bulkService,
				mappers: new \OCA\OpenRegister\Controller\Support\TranslationMappers(
					translationMapper: $translationMapper,
					schemaMapper: $schemaMapper,
					objectMapper: $objectMapper,
				),
			),
		];
	}

	/**
	 * Build TranslationController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return TranslationController
	 */
	public static function create(mixed ...$args): TranslationController {
		return new TranslationController(...self::args(...$args));
	}
}
