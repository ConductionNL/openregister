<?php

/**
 * The schema and table lookups the related-row applier resolves a block with.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Query
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/query-related-schema-rows/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Query;

use OCA\OpenRegister\Db\MagicMapper\MagicTableHandlerInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * The lookups `RelatedRowQueryApplier` works with.
 *
 * @spec openspec/changes/query-related-schema-rows/specs/zoeken-filteren/spec.md
 */
final readonly class RelatedRowLookups {

	/**
	 * Bundle the lookups.
	 *
	 * @param SchemaMapperInterface      $schemaMapper The schema lookup.
	 * @param MagicTableHandlerInterface $tableHandler Resolves a register and schema to their table.
	 */
	public function __construct(
		public SchemaMapperInterface $schemaMapper,
		public MagicTableHandlerInterface $tableHandler,
	) {
	}//end __construct()
}//end class
