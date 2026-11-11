<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\PropertyVocabularyController;

/**
 * Builds PropertyVocabularyController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class PropertyVocabularyControllerFactory {
	/**
	 * The constructor arguments of PropertyVocabularyController.
	 *
	 * @param IRequest $request Constructor argument.
	 * @param PropertyVocabularyInterface $vocabulary Constructor argument.
	 * @param ExtendingFormDeclarationInterface $declarations Constructor argument.
	 * @param SchemaMapperInterface $schemaMapper Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\Schemas\PropertyVocabularyInterface $vocabulary,
		\OCA\OpenRegister\Service\Schemas\ExtendingFormDeclarationInterface $declarations,
		\OCA\OpenRegister\Db\SchemaMapperInterface $schemaMapper,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\PropertyVocabularyControllerServices(
				vocabulary: $vocabulary,
				declarations: $declarations,
				schemaMapper: $schemaMapper,
			),
		];
	}

	/**
	 * Build PropertyVocabularyController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return PropertyVocabularyController
	 */
	public static function create(mixed ...$args): PropertyVocabularyController {
		return new PropertyVocabularyController(...self::args(...$args));
	}
}
