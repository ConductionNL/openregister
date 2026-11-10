<?php

/**
 * A property of an aliased row in a related-row subquery.
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
 * A property of one aliased row: the related row, or the object row it joins.
 *
 * The property becomes a typed column on a magic table and a JSON key in the
 * objects table; which one is the target's storage, not this object's.
 *
 * @spec openspec/changes/query-related-schema-rows/specs/zoeken-filteren/spec.md
 */
final readonly class RelatedRowField {

	/**
	 * Bundle the field.
	 *
	 * @param string $alias The row's alias.
	 * @param string $name  The property name.
	 */
	public function __construct(
		public string $alias,
		public string $name,
	) {
	}//end __construct()
}//end class
