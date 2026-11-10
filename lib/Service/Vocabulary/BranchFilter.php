<?php

/**
 * OpenRegister BranchFilter
 *
 * What a `?categorie[branch]=<uri>&categorie[depth]=<n>` filter value asks
 * for: the branch root and, optionally, how deep below it to match. Parsed
 * once from the raw filter value so the expander decides whether a filter is
 * a branch filter and what it asks for in one place.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vocabulary
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/skos-concept-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Vocabulary;

/**
 * A branch filter: the root asked for and the optional depth bound.
 *
 * @spec openspec/specs/skos-concept-registers/spec.md
 */
final class BranchFilter {

	/**
	 * Constructor.
	 *
	 * @param string       $root  The branch root's uri, trimmed and non-empty.
	 * @param integer|null $depth The requested depth bound, or null for the declaration's own.
	 */
	public function __construct(
		public readonly string $root,
		public readonly ?int $depth = null,
	) {

	}//end __construct()

	/**
	 * The branch filter a raw filter value asks for, or null when it asks for none.
	 *
	 * @param mixed $value The filter value as parsed from the request.
	 *
	 * @return self|null The branch filter.
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	public static function fromFilterValue(mixed $value): ?self {
		if (is_array($value) === false) {
			return null;
		}

		$root = ($value[CodedFilterExpander::BRANCH_KEY] ?? null);
		if (is_string($root) === false || trim($root) === '') {
			return null;
		}

		return new self(root: trim($root), depth: self::depthOf(value: $value));
	}//end fromFilterValue()

	/**
	 * The depth bound a filter value asks for, or null.
	 *
	 * @param array<string,mixed> $value The filter value.
	 *
	 * @return integer|null The requested depth.
	 */
	private static function depthOf(array $value): ?int {
		$depth = ($value[CodedFilterExpander::DEPTH_KEY] ?? null);
		if (is_numeric($depth) === false) {
			return null;
		}

		return (int)$depth;
	}//end depthOf()
}//end class
