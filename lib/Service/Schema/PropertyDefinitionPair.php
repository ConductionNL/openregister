<?php

/**
 * PropertyDefinitionPair: one property's old and new definition in a schema diff.
 *
 * Answers which constraint keywords each side declares and builds the
 * constraint change entries that SchemaDiffService records for the property.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schema
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schema;

/**
 * The old and new definition of one property present in both schema versions.
 */
final class PropertyDefinitionPair {

	/**
	 * Build the pair.
	 *
	 * @param string               $name Property name.
	 * @param array<string, mixed> $old  Old property definition.
	 * @param array<string, mixed> $new  New property definition.
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function __construct(
		public readonly string $name,
		public readonly array $old,
		public readonly array $new,
	) {
	}//end __construct()

	/**
	 * Whether the old definition declares a keyword (also with a null value).
	 *
	 * @param string $keyword The constraint keyword.
	 *
	 * @return bool True when declared in the old definition.
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function hasOld(string $keyword): bool {
		return array_key_exists($keyword, $this->old);
	}//end hasOld()

	/**
	 * Whether the new definition declares a keyword (also with a null value).
	 *
	 * @param string $keyword The constraint keyword.
	 *
	 * @return bool True when declared in the new definition.
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function hasNew(string $keyword): bool {
		return array_key_exists($keyword, $this->new);
	}//end hasNew()

	/**
	 * Whether both definitions declare a keyword.
	 *
	 * @param string $keyword The constraint keyword.
	 *
	 * @return bool True when declared on both sides.
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function hasBoth(string $keyword): bool {
		return $this->hasOld(keyword: $keyword) === true && $this->hasNew(keyword: $keyword) === true;
	}//end hasBoth()

	/**
	 * Build a constraint_tightened change for this property.
	 *
	 * @param array<string, mixed>|null $old The old constraint, null when it was absent.
	 * @param array<string, mixed>|null $new The new constraint, null when it is absent.
	 *
	 * @return array<string, mixed> The change.
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function tightened(?array $old, ?array $new): array {
		return ['property' => $this->name, 'kind' => 'constraint_tightened', 'old' => $old, 'new' => $new];
	}//end tightened()

	/**
	 * Build a constraint_relaxed change for this property.
	 *
	 * @param array<string, mixed>|null $old The old constraint, null when it was absent.
	 * @param array<string, mixed>|null $new The new constraint, null when it is absent.
	 *
	 * @return array<string, mixed> The change.
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function relaxed(?array $old, ?array $new): array {
		return ['property' => $this->name, 'kind' => 'constraint_relaxed', 'old' => $old, 'new' => $new];
	}//end relaxed()
}//end class
