<?php

/**
 * OpenRegister property-vocabulary controller services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Schemas\ExtendingFormDeclarationInterface;
use OCA\OpenRegister\Service\Schemas\PropertyVocabularyInterface;

/**
 * The services of PropertyVocabularyController, bundled so its constructor stays small.
 *
 * @spec openspec/specs/runtime-schema-api/spec.md
 */
final class PropertyVocabularyControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param PropertyVocabularyInterface       $vocabulary   The published vocabulary.
	 * @param ExtendingFormDeclarationInterface $declarations The extending-form declaration reader.
	 * @param SchemaMapperInterface             $schemaMapper Schema lookup, for the declared narrowings.
	 */
	public function __construct(
		public readonly PropertyVocabularyInterface $vocabulary,
		public readonly ExtendingFormDeclarationInterface $declarations,
		public readonly SchemaMapperInterface $schemaMapper,
	) {
	}//end __construct()
}//end class
