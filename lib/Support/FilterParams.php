<?php

/**
 * FilterParams: one filter grammar for object search and the aggregations.
 *
 * @category Support
 * @package  OCA\OpenRegister\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-both-filter-spellings-mean-the-same-filter-on-object-search-and-the-aggregations
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Support;

use Psr\Log\LoggerInterface;

/**
 * Normalises the two ways a caller can spell a property filter, `x=v` and
 * `filter[x]=v`, so object search and the aggregation endpoints read both.
 *
 * WHY THIS EXISTS. The two sibling endpoints disagreed, and each answered the
 * other's spelling with a confident wrong number (openregister#3611):
 *
 *   - Object search (`/api/objects/{register}/{schema}`) read only the bare
 *     spelling. `filter[origin]=manual` became a filter on a property literally
 *     named `filter`, which no schema has, so every such query returned the
 *     empty set. A caller summing those rows rendered 0.
 *   - The aggregations (`/api/objects/aggregations/...`) read only the bracket
 *     spelling. A bare `origin=manual` was dropped, so the caller got the figure
 *     for the whole schema while believing it had scoped the query.
 *
 * Both failures were silent. This class makes the known-property case agree and
 * gives the unknown-key case a warning, without refusing anything: a 400 would
 * break every caller on the wrong spelling at the same moment.
 *
 * WHAT STAYS AS IT WAS. A key that names no property keeps the result its
 * endpoint already gave it, and is logged. That is deliberate: a bare query
 * parameter the aggregations never read (a cache-buster, a stray `v=2`) must
 * not start zeroing a KPI tile. Only a key that names a declared property is
 * newly honoured on the endpoint that used to ignore it.
 *
 * Kept static on the same terms as {@see QueryLimit}: both read paths have to
 * reach the SAME answer, and one pure function reachable by name from both is
 * the property being bought.
 *
 * @psalm-suppress UnusedClass Referenced from the query paths; psalm's
 *  entry-point analysis does not follow the controllers that reach them.
 */
final class FilterParams {

	/**
	 * The request parameter that carries the bracket spelling, `filter[x]=v`.
	 */
	public const FILTER_KEY = 'filter';

	/**
	 * Non-underscore parameters that carry object-search CONTEXT, not a filter.
	 *
	 * Also the tail of `MagicSearchHandler::getReservedParams()`, which reads
	 * this constant, so the search handler and this class cannot drift apart.
	 *
	 * @var string[]
	 */
	public const OBJECT_CONTEXT_PARAMS = ['register', 'schema', 'registers', 'schemas', 'extend'];

	/**
	 * Parameters `SearchQueryHandler::buildSearchQuery()` strips before it
	 * reads filters. They steer the request (`deleted` includes soft-deleted
	 * rows, `rbac`/`multi` are ignored for security) and are never filters.
	 *
	 * @var string[]
	 */
	public const OBJECT_SYSTEM_PARAMS = ['id', 'rbac', 'multi', 'deleted'];

	/**
	 * Route placeholders every aggregation route carries. `IRequest::getParams()`
	 * merges URL parameters in with the query string, so without this a schema
	 * slug would be read as a filter on a property called `schema`.
	 *
	 * @var string[]
	 */
	public const AGGREGATION_ROUTE_PARAMS = ['register', 'schema'];

	/**
	 * The control parameters each aggregation action reads by name, taken
	 * from `AggregationController`. None of them is ever a filter, on either
	 * spelling of the endpoint; a property that shares one of these names can
	 * only be filtered as `filter[name]`.
	 *
	 * `aggregate` carries `name` because its route has a `{name}` placeholder,
	 * and most fleet schemas declare a `name` property.
	 *
	 * @var array<string, string[]>
	 */
	public const AGGREGATION_CONTROL_PARAMS = [
		'aggregate'  => ['filter', 'name'],
		'value'      => ['filter', 'metric', 'field', 'metrics'],
		'grouped'    => ['filter', 'metric', 'field', 'metrics', 'groupBy', 'sort', 'limit'],
		'timeseries' => ['filter', 'field', 'interval', 'from', 'to', 'metric', 'metricField', 'cumulative'],
	];

	/**
	 * Lift `filter[x]=v` into a bare `x=v` for the object-search grammar.
	 *
	 * Runs on the parameters AFTER `buildSearchQuery()` has undone PHP's
	 * dot-mangling, so a bracket key is taken literally, as it arrived. A
	 * nested bracket (`filter[address][city]=v`) lifts to the same nested shape
	 * the bare dot spelling (`address.city=v`) produces. When both spellings
	 * name one key, the bracket value wins.
	 *
	 * A key that is not a filter under the bare spelling stays behind in
	 * `filter`: an underscore-prefixed key, a context or system parameter, or a
	 * numeric key. The spelling is not a way to reach a control parameter, and
	 * the leftover `filter` keeps the result such a request gave before.
	 *
	 * @param array<array-key, mixed> $params The request parameters.
	 *
	 * @return array<array-key, mixed> The parameters with the bracket keys lifted.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-both-filter-spellings-mean-the-same-filter-on-object-search-and-the-aggregations
	 */
	public static function liftBracketFilter(array $params): array {
		$bracket = ($params[self::FILTER_KEY] ?? null);
		if (is_array($bracket) === false || $bracket === [] || array_is_list($bracket) === true) {
			return $params;
		}

		$reserved = array_merge(self::OBJECT_CONTEXT_PARAMS, self::OBJECT_SYSTEM_PARAMS);
		$leftover = [];
		foreach ($bracket as $key => $value) {
			if (self::isFilterName(key: $key, reserved: $reserved) === false) {
				$leftover[$key] = $value;
				continue;
			}

			$params[$key] = $value;
		}

		unset($params[self::FILTER_KEY]);
		if ($leftover !== []) {
			$params[self::FILTER_KEY] = $leftover;
		}

		return $params;
	}//end liftBracketFilter()

	/**
	 * Build an aggregation's filter map from both spellings.
	 *
	 * The bracket map passes through exactly as the endpoint always read it.
	 * A bare key joins it only when it names a declared property of the schema
	 * and is not one of the action's control parameters. When both spellings
	 * name one key, the bracket value wins.
	 *
	 * Keys that name no property come back in `unknown` for the caller to log:
	 * a bare one was ignored, as it always was, and a bracket one still filters
	 * on a column the rows do not have, so it still matches nothing.
	 *
	 * @param array<array-key, mixed> $bracket       The `filter[...]` map.
	 * @param array<array-key, mixed> $params        Every request parameter.
	 * @param string[]                $controlParams The action's control parameters.
	 * @param array<array-key, mixed> $properties    The schema's declared properties.
	 *
	 * @return array{filter: array<array-key, mixed>, unknown: list<string>}
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-both-filter-spellings-mean-the-same-filter-on-object-search-and-the-aggregations
	 */
	public static function forAggregation(array $bracket, array $params, array $controlParams, array $properties): array {
		$reserved = self::reservedForAggregation(controlParams: $controlParams);
		$filter = [];
		$unknown = [];

		foreach ($params as $key => $value) {
			if (self::isFilterName(key: $key, reserved: $reserved) === false) {
				continue;
			}

			if (array_key_exists($key, $properties) === false) {
				$unknown[] = (string)$key;
				continue;
			}

			$filter[$key] = $value;
		}

		foreach ($bracket as $key => $value) {
			$filter[$key] = $value;
			if (is_string($key) === true
				&& $key !== ''
				&& str_starts_with($key, '_') === false
				&& array_key_exists($key, $properties) === false
			) {
				$unknown[] = $key;
			}
		}

		return [
			'filter'  => $filter,
			'unknown' => array_values(array_unique($unknown)),
		];
	}//end forAggregation()

	/**
	 * Whether any parameter could become a bare aggregation filter.
	 *
	 * Lets the controller skip the schema lookup on the common request that
	 * carries only control parameters.
	 *
	 * @param array<array-key, mixed> $params        Every request parameter.
	 * @param string[]                $controlParams The action's control parameters.
	 *
	 * @return bool True when at least one parameter is a candidate filter name.
	 */
	public static function hasBareCandidates(array $params, array $controlParams): bool {
		$reserved = self::reservedForAggregation(controlParams: $controlParams);
		foreach (array_keys($params) as $key) {
			if (self::isFilterName(key: $key, reserved: $reserved) === true) {
				return true;
			}
		}

		return false;
	}//end hasBareCandidates()

	/**
	 * The object-field filter keys of a built search query that name no
	 * property of the schema.
	 *
	 * Mirrors `MagicSearchHandler::isObjectFieldFilterKey()`: `@self`, every
	 * underscore-prefixed key and every context parameter are not object-field
	 * filters. What is left and is not a declared property is exactly the set
	 * the search handler answers with `1 = 0`.
	 *
	 * @param array<array-key, mixed> $query      The query from `buildSearchQuery()`.
	 * @param array<array-key, mixed> $properties The schema's declared properties.
	 *
	 * @return list<string> The unknown keys, in query order.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-both-filter-spellings-mean-the-same-filter-on-object-search-and-the-aggregations
	 */
	public static function unknownObjectFilterKeys(array $query, array $properties): array {
		$unknown = [];
		foreach (array_keys($query) as $key) {
			$key = (string)$key;
			if ($key === '@self'
				|| str_starts_with($key, '_') === true
				|| in_array($key, self::OBJECT_CONTEXT_PARAMS, true) === true
				|| array_key_exists($key, $properties) === true
			) {
				continue;
			}

			$unknown[] = $key;
		}

		return $unknown;
	}//end unknownObjectFilterKeys()

	/**
	 * Log ONE warning naming every filter key that named no property.
	 *
	 * This is what makes the silent failure loud without breaking a caller.
	 * It is a warning and not a refusal on purpose; turning it into an HTTP 400
	 * is the follow-up once the logs show no caller depends on the old answer.
	 *
	 * @param LoggerInterface|null $logger   Where to write; null writes nothing.
	 * @param string[]             $keys     The unknown keys.
	 * @param string               $endpoint The route name, e.g. `objects#index`.
	 * @param string               $register The register reference from the URL.
	 * @param string               $schema   The schema reference from the URL.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-both-filter-spellings-mean-the-same-filter-on-object-search-and-the-aggregations
	 */
	public static function warnUnknownKeys(
		?LoggerInterface $logger,
		array $keys,
		string $endpoint,
		string $register,
		string $schema,
	): void {
		if ($logger === null || $keys === []) {
			return;
		}

		$logger->warning(
			sprintf(
				'[OpenRegister] %s: filter key(s) "%s" name no property of schema "%s" in register "%s", so they do not'
				. ' filter on a property. Check the spelling; an unknown filter key is meant to become an HTTP 400.',
				$endpoint,
				implode('", "', $keys),
				$schema,
				$register
			),
			[
				'app'      => 'openregister',
				'endpoint' => $endpoint,
				'register' => $register,
				'schema'   => $schema,
				'keys'     => array_values($keys),
			]
		);
	}//end warnUnknownKeys()

	/**
	 * The names an aggregation action never reads as a filter: its own control
	 * parameters, the route placeholders, and `filter` itself.
	 *
	 * One builder for both readers on purpose. {@see hasBareCandidates()}
	 * decides whether the schema is worth resolving and {@see forAggregation()}
	 * decides what filters; if those two disagreed, a key could be called a
	 * candidate and then dropped, or skipped before it was ever considered.
	 *
	 * @param string[] $controlParams The action's control parameters.
	 *
	 * @return string[] Every name that is never a filter on this action.
	 */
	private static function reservedForAggregation(array $controlParams): array {
		return array_merge($controlParams, self::AGGREGATION_ROUTE_PARAMS, [self::FILTER_KEY]);
	}//end reservedForAggregation()

	/**
	 * Whether a request key can name a property filter at all.
	 *
	 * @param int|string $key      The request key.
	 * @param string[]   $reserved Names that are never filters on this endpoint.
	 *
	 * @return bool False for numeric, empty, underscore-prefixed and reserved keys.
	 */
	private static function isFilterName(int|string $key, array $reserved): bool {
		if (is_string($key) === false || $key === '' || str_starts_with($key, '_') === true) {
			return false;
		}

		return in_array($key, $reserved, true) === false;
	}//end isFilterName()
}//end class
