<?php

/**
 * Which `_extend` post-processing a serialized register gets.
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
 * Whether a register's schema ids expand to schema objects, and the counts those objects carry.
 *
 * Built from the `_extend` keys a caller passes: `schemas` expands, and
 * `@self.stats` attaches counts (only meaningful alongside `schemas`).
 * Unknown keys are ignored.
 *
 * @spec openspec/specs/register-service-extensions/spec.md
 */
final class RegisterExpansion {

	/**
	 * Constructor.
	 *
	 * @param bool                    $schemas Whether schema ids expand to schema objects.
	 * @param SchemaObjectCounts|null $counts  The counts expanded schemas carry, or null to attach none.
	 */
	public function __construct(
		public readonly bool $schemas = false,
		public readonly ?SchemaObjectCounts $counts = null,
	) {
	}//end __construct()

	/**
	 * The expansion an `_extend` list asks for.
	 *
	 * @param array<int, string>                         $extend      Extension keys (`schemas`, `@self.stats`).
	 * @param array<int|string, array{total?: int}>|null $schemaStats Pre-computed per-schema counts.
	 *
	 * @return self The expansion.
	 *
	 * @spec openspec/specs/register-service-extensions/spec.md
	 */
	public static function fromExtend(array $extend, ?array $schemaStats = null): self {
		$counts = null;
		if (in_array('@self.stats', $extend, true) === true) {
			$counts = new SchemaObjectCounts(totals: $schemaStats);
		}

		return new self(schemas: in_array('schemas', $extend, true), counts: $counts);
	}//end fromExtend()
}//end class
