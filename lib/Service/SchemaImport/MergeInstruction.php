<?php

/**
 * OpenRegister Schema Import — MergeInstruction.
 *
 * The three property maps of one update-from-source merge plus the conflicts
 * the caller confirmed to apply.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\SchemaImport
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\SchemaImport;

/**
 * One three-way merge for update-from-source (ThreeWayMerge::compute()).
 *
 * @spec openspec/specs/schema-import/spec.md
 */
final class MergeInstruction {
	/**
	 * Constructor.
	 *
	 * @param array<string, array<string,mixed>> $baseline Imported baseline property definitions.
	 * @param array<string, array<string,mixed>> $current  Current schema property definitions.
	 * @param array<string, array<string,mixed>> $incoming Freshly-imported property definitions.
	 * @param array<int, string>                 $resolved Conflicting property names the caller confirmed to apply.
	 */
	public function __construct(
		public readonly array $baseline,
		public readonly array $current,
		public readonly array $incoming,
		public readonly array $resolved = [],
	) {
	}//end __construct()

	/**
	 * Every property name any of the three maps carries, first seen first.
	 *
	 * @return array<int, int|string> The names.
	 *
	 * @spec openspec/specs/schema-import/spec.md
	 */
	public function names(): array {
		return array_unique(array_merge(array_keys($this->baseline), array_keys($this->current), array_keys($this->incoming)));
	}//end names()

	/**
	 * The confirmed conflict names as a set keyed by name.
	 *
	 * @return array<int|string, true> The set.
	 *
	 * @spec openspec/specs/schema-import/spec.md
	 */
	public function resolvedSet(): array {
		return array_fill_keys($this->resolved, true);
	}//end resolvedSet()
}//end class
