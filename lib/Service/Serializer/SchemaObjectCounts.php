<?php

/**
 * Pre-computed object counts per schema, for the `@self.stats` extension.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Serializer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Serializer;

/**
 * The per-schema object totals a register's `@self.stats` extension attaches.
 *
 * A schema the counts do not name, or name without a `total`, counts zero.
 *
 * @spec openspec/specs/register-service-extensions/spec.md
 */
final class SchemaObjectCounts {

	/**
	 * Constructor.
	 *
	 * @param array<int|string, array{total?: int}>|null $totals Schema id → ['total' => int], or null when nothing was counted.
	 */
	public function __construct(
		public readonly ?array $totals = null,
	) {
	}//end __construct()

	/**
	 * The object total for one schema; zero when it was not counted.
	 *
	 * @param int|string|null $schemaId The schema id.
	 *
	 * @return int The total.
	 *
	 * @spec openspec/specs/register-service-extensions/spec.md
	 */
	public function totalFor(int|string|null $schemaId): int {
		if ($schemaId === null || isset($this->totals[$schemaId]['total']) === false) {
			return 0;
		}

		return (int)$this->totals[$schemaId]['total'];
	}//end totalFor()
}//end class
