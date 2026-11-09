<?php

/**
 * How one Tables table projects its rows onto object properties.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/tables-virtual-register/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

/**
 * The column descriptors, the column-to-property map and the relation-target check of one table's rows.
 *
 * @spec openspec/specs/tables-virtual-register/spec.md
 */
final class TablesRowProjection {
	/**
	 * Constructor.
	 *
	 * @param array<int, array<string, mixed>> $columns            The table's column descriptors.
	 * @param array<int, string>               $columnMap          Map of columnId to property name.
	 * @param callable(int): bool              $targetSchemaExists Does a relation's target table have a seeded schema?
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $columns,
		public readonly array $columnMap,
		public readonly mixed $targetSchemaExists,
	) {
	}//end __construct()
}//end class
