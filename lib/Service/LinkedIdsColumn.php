<?php

/**
 * OpenRegister LinkedIdsColumn
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/linked-entity-types/spec.md
 */

namespace OCA\OpenRegister\Service;

/**
 * The new value of one linked-type column: which type (the column) and the ids it holds.
 *
 * @spec openspec/specs/linked-entity-types/spec.md
 */
final class LinkedIdsColumn {

	/**
	 * Constructor.
	 *
	 * @param string $type The linked entity type (its column).
	 * @param array  $ids  The linked ids.
	 */
	public function __construct(
		public readonly string $type,
		public readonly array $ids,
	) {

	}//end __construct()
}//end class
