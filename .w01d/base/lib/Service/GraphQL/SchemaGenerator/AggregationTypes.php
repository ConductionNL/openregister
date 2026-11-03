<?php

/**
 * The GraphQL types that describe an ad-hoc aggregation.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\GraphQL\SchemaGenerator
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/graphql-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\GraphQL\SchemaGenerator;

use GraphQL\Type\Definition\EnumType;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * Builds and caches GroupByInput, TimeInterval, AggregationMetric,
 * AggregationMetricInput and GroupBucket.
 *
 * 🔑 EACH TYPE IS BUILT ONCE AND SHARED. graphql-php identifies a type by
 * NAME, so two instances called `GroupBucket` in one schema is a duplicate
 * type error rather than two equal types. The lazy field on each getter is
 * what keeps that true, and it is the only reason these are methods rather
 * than constants.
 *
 * Kept apart from {@see TypeMapperHandler} because none of them depend on a
 * register schema: they are the same five shapes for every schema on the
 * instance, while the mapper around them is entirely about turning ONE
 * schema's properties into types.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) `GraphQL\Type\Definition\Type::string()`,
 * `::int()`, `::listOf()` and `::nonNull()` are graphql-php's own factory API for
 * the built-in scalars and wrappers. There is no instance to inject and nothing
 * to stub short of wrapping the library, which would buy nothing: the calls
 * return the library's singletons and a second construction path for them is a
 * duplicate-type error waiting to happen. This is the SAME suppression
 * {@see TypeMapperHandler} already carries, for the same calls; the code moved,
 * the reason did not.
 *
 * @spec openspec/specs/graphql-api/spec.md
 */
class AggregationTypes {

	/**
	 * Shared GroupByInput input type. Backs the optional `groupBy`
	 * argument on every auto-generated list query. See the
	 * `add-time-bucket-aggregation` change for the spec contract.
	 *
	 * @var InputObjectType|null
	 */
	private ?InputObjectType $groupByInputType = null;

	/**
	 * Shared TimeInterval enum (MINUTE..YEAR). Used inside GroupByInput.
	 *
	 * @var EnumType|null
	 */
	private ?EnumType $timeIntervalType = null;

	/**
	 * Shared AggregationMetric enum (COUNT|SUM|AVG|MIN|MAX). Used
	 * inside GroupByInput.
	 *
	 * @var EnumType|null
	 */
	private ?EnumType $aggMetricType = null;

	/**
	 * Shared GroupBucket object type. Element shape of the `groups`
	 * field on every Connection.
	 *
	 * @var ObjectType|null
	 */
	private ?ObjectType $groupBucketType = null;

	/**
	 * Shared AggregationMetricInput type. One entry of a `metrics` list.
	 *
	 * @var InputObjectType|null
	 */
	private ?InputObjectType $metricInputType = null;

	/**
	 * The custom scalar types, set after the schema generator builds them.
	 *
	 * @var array<string, Type>
	 */
	private array $scalars = [];

	/**
	 * Hand over the custom scalars the metric input's `condition` field needs.
	 *
	 * @param array<string, Type> $scalars The custom scalar types.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-graphql-resolver-must-reset-state-between-requests
	 */
	public function setScalars(array $scalars): void {
		$this->scalars = $scalars;
	}//end setScalars()

	/**
	 * Get (or lazily build) the shared GroupBucket object type.
	 *
	 * @return ObjectType The GroupBucket type.
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	public function getGroupBucketType(): ObjectType {
		if ($this->groupBucketType !== null) {
			return $this->groupBucketType;
		}

		$this->groupBucketType = new ObjectType(
			[
				'name' => 'GroupBucket',
				'description' => 'A single bucket in an aggregation result.',
				'fields' => [
					// NULLABLE, and it was not.
					//
					// `key: String!` forced the resolver to coerce a null group
					// key to '' — a row whose group field is null became
					// indistinguishable from one whose value is genuinely the
					// empty string. The engine returns null there and means it.
					'key' => [
						'type' => Type::string(),
						'description' => 'Group key for a single-field grouping. '
							. 'NULL when the grouped field is null on those rows — which is not the '
							. 'same as an empty string. Null for a composite grouping; use `keys`.',
					],
					// ALSO NULLABLE. A multi-metric result carries `values` and
					// no `value` at all, so `Float!` would have forced 0.0 —
					// reporting zero for every bucket rather than admitting the
					// figure lives elsewhere.
					'value' => [
						'type' => Type::float(),
						'description' => 'Single-metric value. NULL for a multi-metric grouping; use `values`.',
					],
					'keys' => [
						'type' => $this->scalars['JSON'],
						'description' => 'Composite group key as a {field: value} map. '
							. 'Present when the aggregation groups on more than one field.',
					],
					'values' => [
						'type' => $this->scalars['JSON'],
						'description' => 'Figure per response key, for a multi-metric aggregation '
							. '(`sum_amount`, or an `as` alias such as `totalDebit`).',
					],
					'joined' => [
						'type' => $this->scalars['JSON'],
						'description' => 'Figures pulled from a joined schema, keyed '
							. '`<Schema>.<field>`. Present only when the aggregation declares a join.',
					],
				],
			]
		);

		return $this->groupBucketType;
	}//end getGroupBucketType()

	/**
	 * Get (or lazily build) the shared TimeInterval enum.
	 *
	 * @return EnumType The TimeInterval enum.
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	public function getTimeIntervalType(): EnumType {
		if ($this->timeIntervalType !== null) {
			return $this->timeIntervalType;
		}

		$this->timeIntervalType = new EnumType(
			[
				'name' => 'TimeInterval',
				'description' => 'Bucketing interval for ad-hoc time-bucket aggregations.',
				'values' => [
					'MINUTE' => ['value' => 'MINUTE'],
					'HOUR' => ['value' => 'HOUR'],
					'DAY' => ['value' => 'DAY'],
					'WEEK' => ['value' => 'WEEK'],
					'MONTH' => ['value' => 'MONTH'],
					'QUARTER' => ['value' => 'QUARTER'],
					'YEAR' => ['value' => 'YEAR'],
				],
			]
		);

		return $this->timeIntervalType;
	}//end getTimeIntervalType()

	/**
	 * Get (or lazily build) the shared AggregationMetric enum.
	 *
	 * @return EnumType The AggregationMetric enum.
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	public function getAggregationMetricType(): EnumType {
		if ($this->aggMetricType !== null) {
			return $this->aggMetricType;
		}

		$this->aggMetricType = new EnumType(
			[
				'name' => 'AggregationMetric',
				'description' => 'Metric for ad-hoc aggregations.',
				'values' => [
					'COUNT' => ['value' => 'COUNT'],
					'SUM' => ['value' => 'SUM'],
					'AVG' => ['value' => 'AVG'],
					'MIN' => ['value' => 'MIN'],
					'MAX' => ['value' => 'MAX'],
				],
			]
		);

		return $this->aggMetricType;
	}//end getAggregationMetricType()

	/**
	 * Get (or lazily build) the shared GroupByInput input type.
	 *
	 * @return InputObjectType The GroupByInput type.
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	public function getGroupByInputType(): InputObjectType {
		if ($this->groupByInputType !== null) {
			return $this->groupByInputType;
		}

		$this->groupByInputType = new InputObjectType(
			[
				'name' => 'GroupByInput',
				'description' => 'Ad-hoc aggregation arg; `interval` set => time-bucketed, otherwise categorical groupBy.',
				'fields' => [
					'field' => [
						'type' => Type::nonNull(Type::string()),
						'description' => 'Field to group on. Must be a declared schema property or magic metadata column.',
					],
					'interval' => [
						'type' => $this->getTimeIntervalType(),
						'description' => 'Optional bucketing interval. When supplied, requires `from` + `to`.',
					],
					'from' => [
						'type' => Type::string(),
						'description' => 'ISO-8601 lower bound, inclusive. Required when `interval` is set.',
					],
					'to' => [
						'type' => Type::string(),
						'description' => 'ISO-8601 upper bound, exclusive. Required when `interval` is set.',
					],
					'metric' => [
						'type' => $this->getAggregationMetricType(),
						'defaultValue' => 'COUNT',
						'description' => 'Aggregation metric. Default COUNT.',
					],
					'metricField' => [
						'type' => Type::string(),
						'description' => 'Field to aggregate over. Required when metric != COUNT.',
					],
					// Composite grouping. `field` above stays required and
					// remains the single-field spelling; `fields` is the
					// multi-field one, and a bucket then carries `keys` rather
					// than `key`.
					'fields' => [
						'type' => Type::listOf(Type::nonNull(Type::string())),
						'description' => 'Group on several fields (cross-tab). Each bucket then carries '
							. '`keys` as a {field: value} map, and `key` is null.',
					],
					// Several figures over one grouping. Each bucket then
					// carries `values`, and `value` is null.
					'metrics' => [
						'type' => Type::listOf(Type::nonNull($this->getAggregationMetricInputType())),
						'description' => 'Several figures over one grouping. Each bucket then carries '
							. '`values` keyed by response key or `as` alias, and `value` is null.',
					],
				],
			]
		);

		return $this->groupByInputType;
	}//end getGroupByInputType()

	/**
	 * Get (or lazily build) the AggregationMetricInput type.
	 *
	 * One entry of an ad-hoc `metrics` list. `condition` scopes THIS figure to a
	 * subset of the grouped rows — the debit/credit split — and `as` names its
	 * response key, which a conditional metric needs: two conditional sums over
	 * one field both derive `sum_<field>`, so without an alias the second would
	 * overwrite the first and quietly return one figure where two were asked for.
	 *
	 * `condition` is JSON because it is a filter OBJECT, the same shape as the
	 * aggregation's own filter. Deliberately not a string expression — a second,
	 * string-shaped grammar is precisely what the engine has been unpicking.
	 *
	 * @return InputObjectType The metric-entry input type.
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	public function getAggregationMetricInputType(): InputObjectType {
		if ($this->metricInputType !== null) {
			return $this->metricInputType;
		}

		$this->metricInputType = new InputObjectType(
			[
				'name' => 'AggregationMetricInput',
				'description' => 'One figure in a multi-metric aggregation.',
				'fields' => [
					'metric' => [
						'type' => Type::nonNull($this->getAggregationMetricType()),
						'description' => 'The metric to compute.',
					],
					'field' => [
						'type' => Type::string(),
						'description' => 'Field to aggregate. Required for every metric except COUNT.',
					],
					'condition' => [
						'type' => $this->scalars['JSON'],
						'description' => 'Filter object scoping THIS figure to a subset of the grouped '
							. 'rows, e.g. {"side": "debit"}. Same shape as the aggregation filter.',
					],
					'as' => [
						'type' => Type::string(),
						'description' => 'Response key for this figure. Required in practice whenever two '
							. 'entries share a metric+field pair, since both derive the same default key.',
					],
				],
			]
		);

		return $this->metricInputType;
	}//end getAggregationMetricInputType()

}//end class
