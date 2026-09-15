<?php

/**
 * OpenRegister SearchTermNode
 *
 * One node of a parsed full-text search term: a literal term, or a boolean
 * combination of other nodes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Search;

/**
 * An immutable node in a parsed search term.
 *
 * A `term` node carries the literal text the user typed plus the two wildcard
 * flags. Everything else is a boolean node over children.
 */
final class SearchTermNode {
	/**
	 * A literal term, matched against every searchable column.
	 */
	public const TYPE_TERM = 'term';

	/**
	 * Every child must match.
	 */
	public const TYPE_AND = 'and';

	/**
	 * At least one child must match.
	 */
	public const TYPE_OR = 'or';

	/**
	 * The single child must not match.
	 */
	public const TYPE_NOT = 'not';

	/**
	 * Constructor, private so the named factories are the only entry.
	 *
	 * @param string            $type           One of the TYPE_* constants.
	 * @param string            $value          Literal text for a term node.
	 * @param SearchTermNode[]  $children       Children for a boolean node.
	 * @param bool              $leadingWildcard  Whether the term began with `*`.
	 * @param bool              $trailingWildcard Whether the term ended with `*`.
	 *
	 * @phpstan-param list<SearchTermNode> $children
	 *
	 * @psalm-param list<SearchTermNode> $children
	 */
	private function __construct(
		public readonly string $type,
		public readonly string $value,
		public readonly array $children,
		public readonly bool $leadingWildcard,
		public readonly bool $trailingWildcard,
	) {
	}//end __construct()

	/**
	 * Build a literal term node.
	 *
	 * @param string $value            The literal text, wildcards already stripped.
	 * @param bool   $leadingWildcard  Whether the term began with `*`.
	 * @param bool   $trailingWildcard Whether the term ended with `*`.
	 *
	 * @return self The term node.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The two flags are the term's own
	 * shape, not a mode switch: `*foo*` sets both and `foo` sets neither, and
	 * splitting them into four constructors would say less, not more.
	 */
	public static function term(
		string $value,
		bool $leadingWildcard = false,
		bool $trailingWildcard = false,
	): self {
		return new self(
			type: self::TYPE_TERM,
			value: $value,
			children: [],
			leadingWildcard: $leadingWildcard,
			trailingWildcard: $trailingWildcard
		);
	}//end term()

	/**
	 * Build a conjunction.
	 *
	 * @param SearchTermNode[] $children The operands.
	 *
	 * @phpstan-param list<SearchTermNode> $children
	 *
	 * @psalm-param list<SearchTermNode> $children
	 *
	 * @return self The `and` node, or the single child when there is only one.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	public static function all(array $children): self {
		if (count($children) === 1) {
			return $children[0];
		}

		return new self(
			type: self::TYPE_AND,
			value: '',
			children: array_values($children),
			leadingWildcard: false,
			trailingWildcard: false
		);
	}//end all()

	/**
	 * Build a disjunction.
	 *
	 * @param SearchTermNode[] $children The operands.
	 *
	 * @phpstan-param list<SearchTermNode> $children
	 *
	 * @psalm-param list<SearchTermNode> $children
	 *
	 * @return self The `or` node, or the single child when there is only one.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	public static function any(array $children): self {
		if (count($children) === 1) {
			return $children[0];
		}

		return new self(
			type: self::TYPE_OR,
			value: '',
			children: array_values($children),
			leadingWildcard: false,
			trailingWildcard: false
		);
	}//end any()

	/**
	 * Build a negation.
	 *
	 * @param SearchTermNode $child The operand.
	 *
	 * @return self The `not` node.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	public static function not(self $child): self {
		return new self(
			type: self::TYPE_NOT,
			value: '',
			children: [$child],
			leadingWildcard: false,
			trailingWildcard: false
		);
	}//end not()

	/**
	 * The SQL LIKE pattern for a term node.
	 *
	 * A term with no wildcard keeps the substring match this search has always
	 * used (`%term%`), which is what makes an operator-free term mean exactly
	 * what it meant before this change. A trailing `*` anchors the start, a
	 * leading `*` anchors the end. The literal text is escaped so a `%` or `_`
	 * the user typed matches itself instead of acting as a wildcard.
	 *
	 * @return string The LIKE pattern, lowercased.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	public function likePattern(): string {
		$escaped = str_replace(
			['\\', '%', '_'],
			['\\\\', '\\%', '\\_'],
			mb_strtolower($this->value)
		);

		// No wildcard at all means the historical substring match on both sides.
		$hasWildcard = ($this->leadingWildcard === true || $this->trailingWildcard === true);
		if ($hasWildcard === false) {
			return '%' . $escaped . '%';
		}

		$prefix = '';
		if ($this->leadingWildcard === true) {
			$prefix = '%';
		}

		$suffix = '';
		if ($this->trailingWildcard === true) {
			$suffix = '%';
		}

		return $prefix . $escaped . $suffix;
	}//end likePattern()
}//end class
