<?php

/**
 * The type a migration cast step converts a value to, and the date format it reads.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schema
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/schema-migration/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schema;

/**
 * Where one cast step takes a value: its target type (one of
 * MigrationValueCaster::TYPES) and, for a date, the format to read it with.
 *
 * @spec openspec/specs/schema-migration/spec.md
 */
final class CastTarget {
	/**
	 * The target as the step declares it.
	 *
	 * @param string      $type   Target type, one of MigrationValueCaster::TYPES.
	 * @param string|null $format Optional date format.
	 */
	public function __construct(
		public readonly string $type,
		public readonly ?string $format = null,
	) {
	}//end __construct()
}//end class
