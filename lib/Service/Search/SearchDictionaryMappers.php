<?php

/**
 * OpenRegister SearchDictionaryMappers
 *
 * The two mappers {@see SearchDictionaryProvider} resolves the vocabulary
 * register's and the concept schemas' ids through.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Search
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Search;

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * Resolves the dictionary's register and schema ids by slug.
 *
 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
 */
final readonly class SearchDictionaryMappers {

	/**
	 * Bundle the collaborators.
	 *
	 * @param RegisterMapperInterface $registers Resolves the vocabulary register's id.
	 * @param SchemaMapperInterface   $schemas   Resolves the concept schemas' ids.
	 */
	public function __construct(
		public RegisterMapperInterface $registers,
		public SchemaMapperInterface $schemas,
	) {
	}//end __construct()
}//end class
