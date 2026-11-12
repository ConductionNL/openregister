<?php

/**
 * One transform to apply: a resolved source value, the field's transform block and the row it came from.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\MigrationPack
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/migration-mapping-packs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\MigrationPack;

/**
 * The operation MappingTransformer::apply() performs (ADR-011 Rule 2).
 *
 * @spec openspec/specs/migration-mapping-packs/spec.md
 */
final class TransformRequest {
	/**
	 * Constructor.
	 *
	 * @param mixed                     $value     The resolved source value (null/'' only for `const`).
	 * @param array<string, mixed>|null $transform The transform block, or null for identity passthrough.
	 * @param array<string, mixed>      $sourceRow The full source row (`concat` resolves extra fields from it).
	 */
	public function __construct(
		public readonly mixed $value,
		public readonly ?array $transform,
		public readonly array $sourceRow,
	) {
	}//end __construct()
}//end class
