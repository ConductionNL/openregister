<?php

/**
 * A filter over the rows of a related schema.
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

/**
 * One `_related[<schema>][<fk>]` block, parsed.
 *
 * An object matches when AT LEAST ONE row of the related schema, whose foreign
 * key points at that object, satisfies EVERY condition in the block. That
 * asymmetry is the whole semantic and it is the thing readers get wrong: it is
 * "a row exists that is all of these", not "rows exist that are each of these".
 * A case with an urgency row and a district row does not match a block asking
 * for one row that is both.
 *
 * Two blocks on one schema therefore mean two rows, and are two separate
 * existence clauses rather than one with more conditions. `_related[p][case]`
 * written twice is how a caller asks for a case carrying both properties.
 *
 * @spec openspec/changes/query-related-schema-rows/specs/zoeken-filteren/spec.md
 */
final class RelatedRowFilter {

	/**
	 * Constructor.
	 *
	 * @param string                                                     $schema     The related schema's slug.
	 * @param string                                                     $foreignKey The property on it pointing back.
	 * @param array<int, array{field: string, operator: string, value: mixed}> $conditions The row conditions.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $schema,
		public readonly string $foreignKey,
		public readonly array $conditions,
	) {
	}//end __construct()
}//end class
