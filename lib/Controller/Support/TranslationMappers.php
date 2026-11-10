<?php

/**
 * OpenRegister translation controller services
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

use OCA\OpenRegister\Db\MagicMapper\MagicObjectFinderInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\TranslationMapperInterface;

/**
 * The mappers the translation API reads translations, schemas and objects from.
 *
 * @spec openspec/changes/i18n-source-of-truth/tasks.md#phase-3
 */
final class TranslationMappers {
	/**
	 * Bundle the mappers.
	 *
	 * @param TranslationMapperInterface $translationMapper The translation mapper.
	 * @param SchemaMapperInterface      $schemaMapper      The schema mapper.
	 * @param MagicObjectFinderInterface $objectMapper      The object mapper.
	 *
	 * @spec openspec/changes/i18n-source-of-truth/tasks.md#phase-3
	 */
	public function __construct(
		public readonly TranslationMapperInterface $translationMapper,
		public readonly SchemaMapperInterface $schemaMapper,
		public readonly MagicObjectFinderInterface $objectMapper,
	) {
	}//end __construct()
}//end class
