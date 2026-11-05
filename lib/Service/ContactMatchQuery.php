<?php

/**
 * OpenRegister Contact Match Query
 *
 * One search for objects that match a contact on email or organisation.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author	Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link	  https://www.OpenRegister.app
 *
 * @spec openspec/specs/contacts-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * The term, the match type and confidence it reports, the property name patterns
 * a value must sit under, and optionally the schema name patterns a result must have.
 * Immutable: each with* method returns a copy.
 *
 * @spec openspec/specs/contacts-actions/spec.md
 */
final class ContactMatchQuery {

	/**
	 * Confidence reported for a match.
	 *
	 * @var float
	 */
	private float $confidence = 1.0;

	/**
	 * Whether the value must equal the term (case-insensitive) rather than contain it or be contained by it.
	 *
	 * @var boolean
	 */
	private bool $exactMatch = true;

	/**
	 * Property name patterns.
	 *
	 * @var array<int, string>
	 */
	private array $propertyPatterns = [];

	/**
	 * Schema name patterns, null for any schema.
	 *
	 * @var array<int, string>|null
	 */
	private ?array $schemaFilter = null;

	/**
	 * Constructor.
	 *
	 * @param string $searchTerm The term to search for.
	 * @param string $matchType  The match type label (email, name, organization).
	 *
	 * @spec openspec/specs/contacts-actions/spec.md
	 */
	public function __construct(
		public readonly string $searchTerm,
		public readonly string $matchType,
	) {
	}//end __construct()

	/**
	 * A copy with the given confidence and matching mode.
	 *
	 * @param float $confidence The confidence score.
	 * @param bool  $exactMatch Whether to require an exact value match.
	 *
	 * @return self The copy.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 *
	 * @spec openspec/specs/contacts-actions/spec.md
	 */
	public function withScoring(float $confidence, bool $exactMatch): self {
		$copy             = clone $this;
		$copy->confidence = $confidence;
		$copy->exactMatch = $exactMatch;
		return $copy;
	}//end withScoring()

	/**
	 * A copy with the given property name patterns and schema filter.
	 *
	 * @param array<int, string>      $propertyPatterns Property name patterns to match.
	 * @param array<int, string>|null $schemaFilter     Schema name patterns, null for any schema.
	 *
	 * @return self The copy.
	 *
	 * @spec openspec/specs/contacts-actions/spec.md
	 */
	public function withPatterns(array $propertyPatterns, ?array $schemaFilter=null): self {
		$copy = clone $this;
		$copy->propertyPatterns = $propertyPatterns;
		$copy->schemaFilter     = $schemaFilter;
		return $copy;
	}//end withPatterns()

	/**
	 * Confidence reported for a match.
	 *
	 * @return float
	 *
	 * @spec openspec/specs/contacts-actions/spec.md
	 */
	public function confidence(): float {
		return $this->confidence;
	}//end confidence()

	/**
	 * Whether a search result's schema title (or name) contains one of the schema patterns.
	 *
	 * @param array $result The search result object.
	 *
	 * @return bool True without a schema filter.
	 *
	 * @spec openspec/specs/contacts-actions/spec.md
	 */
	public function acceptsSchema(array $result): bool {
		if ($this->schemaFilter === null) {
			return true;
		}

		$schemaName = strtolower($result['schema']['title'] ?? $result['schema']['name'] ?? '');
		foreach ($this->schemaFilter as $pattern) {
			if (str_contains($schemaName, strtolower($pattern)) === true) {
				return true;
			}
		}

		return false;
	}//end acceptsSchema()

	/**
	 * Whether a string property under one of the patterns matches the term.
	 *
	 * @param array $result The search result object.
	 *
	 * @return bool True if a matching property is found.
	 *
	 * @spec openspec/specs/contacts-actions/spec.md
	 */
	public function matchesProperty(array $result): bool {
		foreach ($result as $key => $value) {
			if (is_string($value) === true && $this->isPatternKey(key: (string)$key) === true
				&& $this->valueMatches(value: $value) === true
			) {
				return true;
			}
		}

		return false;
	}//end matchesProperty()

	/**
	 * Whether a property name contains one of the patterns.
	 *
	 * @param string $key The property name.
	 *
	 * @return bool
	 */
	private function isPatternKey(string $key): bool {
		$keyLower = strtolower($key);
		foreach ($this->propertyPatterns as $pattern) {
			if (str_contains($keyLower, strtolower($pattern)) === true) {
				return true;
			}
		}

		return false;
	}//end isPatternKey()

	/**
	 * Whether a value matches the term: equal ignoring case, or, when not exact,
	 * containing the term or contained in it.
	 *
	 * @param string $value The property value.
	 *
	 * @return bool
	 */
	private function valueMatches(string $value): bool {
		if ($this->exactMatch === true) {
			return strtolower($value) === strtolower($this->searchTerm);
		}

		return stripos($value, $this->searchTerm) !== false || stripos($this->searchTerm, $value) !== false;
	}//end valueMatches()
}//end class
