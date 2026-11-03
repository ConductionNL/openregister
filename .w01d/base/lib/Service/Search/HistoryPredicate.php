<?php

/**
 * A filter over what a record has BEEN, parsed once and answered about.
 *
 * Two shapes, both naming a property and both narrowing an ordinary list
 * query:
 *
 * - `_was_ever[status]=bezwaar` — every object whose `status` ever held
 *   `bezwaar`, including one sitting in it right now.
 * - `_changed_between[status]=2026-01-01,2026-06-30` — every object whose
 *   `status` took a new value inside that period.
 *
 * 🔴 A PROPERTY THIS DOES NOT KNOW IS A REFUSAL, NOT AN EMPTY PAGE. An
 * unprojected property has no intervals, so matching it would answer "no cases
 * were ever in bezwaar" to a question the system cannot answer at all. The two
 * are indistinguishable on screen and only one of them is true.
 *
 * 🔴 THE PROPERTIES IT CAN ANSWER ABOUT ARE THE ONES SCHEMAS DECLARE. The
 * caller supplies that list from `x-openregister-lifecycle.field`, never from
 * the keys present in the projection table: a property that happened to get
 * written would otherwise become filterable, and a value the schema never
 * declared as a state would be reachable by anyone who guessed the name.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Search
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Search;

use DateTimeImmutable;
use JsonSerializable;

/**
 * One parsed history predicate.
 *
 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
 */
final class HistoryPredicate implements JsonSerializable {

	/**
	 * The query key carrying a "was ever at" filter.
	 *
	 * @var string
	 */
	public const WAS_EVER = '_was_ever';

	/**
	 * The query key carrying a "changed between" filter.
	 *
	 * @var string
	 */
	public const CHANGED_BETWEEN = '_changed_between';

	/**
	 * Constructor.
	 *
	 * @param array<string, string>                                              $wasEver        property => value.
	 * @param array<string, array{after: DateTimeImmutable, before: DateTimeImmutable}> $changedBetween property => period.
	 * @param string[]                                                           $unparsed       Filters that did not read.
	 */
	private function __construct(
		private readonly array $wasEver,
		private readonly array $changedBetween,
		private readonly array $unparsed,
	) {
	}//end __construct()

	/**
	 * Read the predicate off a query.
	 *
	 * @param array<string, mixed> $query The search query.
	 *
	 * @return self The predicate, empty when the query carries none.
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	public static function parse(array $query): self {
		$wasEver = [];
		$changedBetween = [];
		$unparsed = [];

		foreach ((array)($query[self::WAS_EVER] ?? []) as $property => $value) {
			$name = self::readPropertyName(raw: $property);
			if ($name === null || is_scalar($value) === false || (string)$value === '') {
				$unparsed[] = self::WAS_EVER . '[' . (string)$property . ']';
				continue;
			}

			$wasEver[$name] = (string)$value;
		}

		foreach ((array)($query[self::CHANGED_BETWEEN] ?? []) as $property => $value) {
			$name = self::readPropertyName(raw: $property);
			$period = self::readPeriod(raw: $value);
			if ($name === null || $period === null) {
				$unparsed[] = self::CHANGED_BETWEEN . '[' . (string)$property . ']';
				continue;
			}

			$changedBetween[$name] = $period;
		}

		return new self(wasEver: $wasEver, changedBetween: $changedBetween, unparsed: $unparsed);
	}//end parse()

	/**
	 * Whether this predicate has anything to say about the result set.
	 *
	 * @return bool True when at least one filter read.
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	public function narrows(): bool {
		return ($this->wasEver !== [] || $this->changedBetween !== []);
	}//end narrows()

	/**
	 * The `was ever at` filters, property => value.
	 *
	 * @return array<string, string> The filters.
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	public function wasEver(): array {
		return $this->wasEver;
	}//end wasEver()

	/**
	 * The `changed between` filters, property => period.
	 *
	 * @return array<string, array{after: DateTimeImmutable, before: DateTimeImmutable}> The filters.
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	public function changedBetween(): array {
		return $this->changedBetween;
	}//end changedBetween()

	/**
	 * Every property this predicate names, in query order.
	 *
	 * @return string[] The property names.
	 *
	 * @psalm-return list<string>
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	public function properties(): array {
		return array_values(array_unique(array_merge(array_keys($this->wasEver), array_keys($this->changedBetween))));
	}//end properties()

	/**
	 * The filters that did not read.
	 *
	 * @return string[] The filter keys.
	 *
	 * @psalm-return list<string>
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	public function unparsed(): array {
		return $this->unparsed;
	}//end unparsed()

	/**
	 * The refusal this predicate earns against the projected properties.
	 *
	 * Returns the message naming the FIRST property that has no projection, or
	 * null when every named property is answerable. Naming it is the point: a
	 * filter the system cannot answer must not look like a filter that found
	 * nothing.
	 *
	 * @param string[] $projectedProperties Properties the schemas declare as lifecycle fields.
	 *
	 * @return string|null The refusal, or null.
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	public function refusalFor(array $projectedProperties): ?string {
		foreach ($this->properties() as $property) {
			if (in_array($property, $projectedProperties, true) === false) {
				return sprintf(
					'No history is recorded for property "%s", so it cannot be filtered over time. '
					. 'History is recorded for the properties a schema declares as its lifecycle field.',
					$property
				);
			}
		}

		return null;
	}//end refusalFor()

	/**
	 * Serialise what was understood, for the response's own account of itself.
	 *
	 * @return array<string, mixed> The predicate.
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	public function jsonSerialize(): array {
		$periods = [];
		foreach ($this->changedBetween as $property => $period) {
			$periods[$property] = [
				'after' => $period['after']->format('c'),
				'before' => $period['before']->format('c'),
			];
		}

		return [
			'wasEver' => $this->wasEver,
			'changedBetween' => $periods,
			'unparsed' => $this->unparsed,
		];
	}//end jsonSerialize()

	/**
	 * Read a property name, refusing anything that is not one.
	 *
	 * @param mixed $raw The raw key.
	 *
	 * @return string|null The name, or null when it is not one.
	 */
	private static function readPropertyName(mixed $raw): ?string {
		if (is_string($raw) === false) {
			return null;
		}

		$name = trim($raw);
		if ($name === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/', $name) !== 1) {
			return null;
		}

		return $name;
	}//end readPropertyName()

	/**
	 * Read a `after,before` period.
	 *
	 * @param mixed $raw The raw value: "a,b" or ['after' => a, 'before' => b].
	 *
	 * @return array{after: DateTimeImmutable, before: DateTimeImmutable}|null The period, or null.
	 */
	private static function readPeriod(mixed $raw): ?array {
		$after = null;
		$before = null;

		if (is_string($raw) === true) {
			$parts = array_map('trim', explode(',', $raw));
			if (count($parts) === 2) {
				[$after, $before] = $parts;
			}
		} elseif (is_array($raw) === true) {
			$after = ($raw['after'] ?? null);
			$before = ($raw['before'] ?? null);
		}

		if (is_string($after) === false || is_string($before) === false) {
			return null;
		}

		try {
			$start = new DateTimeImmutable($after);
			$end = new DateTimeImmutable($before);
		} catch (\Exception) {
			return null;
		}

		if ($start > $end) {
			return null;
		}

		return ['after' => $start, 'before' => $end];
	}//end readPeriod()
}//end class
