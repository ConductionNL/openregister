<?php

/**
 * One page of a coded property's options.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/property-code-list-from-concept-scheme/specs/skos-concept-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

/**
 * Bounds an option list: a search over label, notation and value, then one page of it.
 *
 * A scheme can hold thousands of concepts, so the options answer is the first page with
 * the count of every matching option and a hint that the form should offer a search; a
 * scheme within one page comes back whole. The parameters follow the concept list on the
 * same controller: `q`, `_limit` and `_offset`.
 *
 * @spec openspec/changes/property-code-list-from-concept-scheme/specs/skos-concept-registers/spec.md
 */
final class CodedOptionPage {

	/**
	 * The page size when none is asked, and the largest one served.
	 */
	public const LIMIT = 200;

	/**
	 * Constructor.
	 *
	 * @param int    $limit  The page size, 1 to LIMIT.
	 * @param int    $offset The options skipped, 0 or more.
	 * @param string $query  The search, empty for none.
	 *
	 * @spec openspec/changes/property-code-list-from-concept-scheme/specs/skos-concept-registers/spec.md
	 */
	public function __construct(
		public readonly int $limit,
		public readonly int $offset,
		public readonly string $query,
	) {
	}//end __construct()

	/**
	 * The page the request parameters ask for, with out-of-range values brought into range.
	 *
	 * @param mixed $limit  The `_limit` parameter.
	 * @param mixed $offset The `_offset` parameter.
	 * @param mixed $query  The `q` parameter.
	 *
	 * @return self The page.
	 *
	 * @spec openspec/changes/property-code-list-from-concept-scheme/specs/skos-concept-registers/spec.md
	 */
	public static function fromParameters(mixed $limit, mixed $offset, mixed $query): self {
		$size = self::LIMIT;
		if (is_numeric($limit) === true) {
			$size = max(1, min(self::LIMIT, (int)$limit));
		}

		$skip = 0;
		if (is_numeric($offset) === true) {
			$skip = max(0, (int)$offset);
		}

		$search = '';
		if (is_scalar($query) === true) {
			$search = trim((string)$query);
		}

		return new self(limit: $size, offset: $skip, query: $search);
	}//end fromParameters()

	/**
	 * The page of the options, with the count of every matching option.
	 *
	 * @param list<array<string, mixed>> $options The options in order.
	 *
	 * @return array{results: list<array<string, mixed>>, total: int, limit: int, offset: int, page: int, pages: int, searchHint: bool}
	 *
	 * @spec openspec/changes/property-code-list-from-concept-scheme/specs/skos-concept-registers/spec.md
	 */
	public function of(array $options): array {
		$matching = array_values(array_filter($options, fn (array $option): bool => $this->matches(option: $option)));
		$total = count($matching);

		return [
			'results' => array_slice($matching, $this->offset, $this->limit),
			'total' => $total,
			'limit' => $this->limit,
			'offset' => $this->offset,
			'page' => ((int)floor($this->offset / $this->limit) + 1),
			'pages' => (int)ceil($total / $this->limit),
			// More options than one page holds: the form should search rather than scroll.
			'searchHint' => $total > $this->limit,
		];
	}//end of()

	/**
	 * Whether an option's label, notation or value holds the search, ignoring case.
	 *
	 * @param array<string, mixed> $option The option.
	 *
	 * @return bool True without a search.
	 *
	 * @spec openspec/changes/property-code-list-from-concept-scheme/specs/skos-concept-registers/spec.md
	 */
	private function matches(array $option): bool {
		if ($this->query === '') {
			return true;
		}

		foreach (['label', 'notation', 'value'] as $field) {
			$text = ($option[$field] ?? null);
			if (is_scalar($text) === true && mb_stripos((string)$text, $this->query) !== false) {
				return true;
			}
		}

		return false;
	}//end matches()
}//end class
