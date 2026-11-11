<?php

/**
 * The GraphQL types that describe an ad-hoc aggregation.
 *
 * @category Service
 * @package  OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator
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

namespace OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator;

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
	 * The TimeInterval enum's values, finest first. Each name is also its value.
	 *
	 * @var list<string>
	 */
	private const TIME_INTERVALS = [
		'MINUTE',
		'HOUR',
		'DAY',
		'WEEK',
		'MONTH',
		'QUARTER',
		'YEAR',
	];

	/**
	 * The AggregationMetric enum's values. Each name is also its value.
	 *
	 * @var list<string>
	 */
	private const AGGREGATION_METRICS = [
		'COUNT',
		'SUM',
		'AVG',
		'MIN',
		'MAX',
	];

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
				'fields' => array_merge($this->groupBucketSingleFields(), $this->groupBucketMapFields()),
			]
		);

		return $this->groupBucketType;
	}//end getGroupBucketType()

	/**
	 * The GroupBucket fields of a single-field, single-metric bucket: `key` and `value`.
	 *
	 * @return array<string, array<string, mixed>> The field definitions, in schema order.
	 */
	private function groupBucketSingleFields(): array {
		return [
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
		];
	}//end groupBucketSingleFields()

	/**
	 * The GroupBucket fields that carry a JSON map: `keys`, `values` and `joined`.
	 *
	 * @return array<string, array<string, mixed>> The field definitions, in schema order.
	 */
	private function groupBucketMapFields(): array {
		return array_merge($this->groupBucketCompositeFields(), $this->groupBucketJoinedField());
	}//end groupBucketMapFields()

	/**
	 * The GroupBucket fields that carry a composite key or several figures: `keys` and `values`.
	 *
	 * @return array<string, array<string, mixed>> The field definitions, in schema order.
	 */
	private function groupBucketCompositeFields(): array {
		return [
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
		];
	}//end groupBucketCompositeFields()

	/**
	 * The GroupBucket field that carries figures from a joined schema: `joined`.
	 *
	 * @return array<string, array<string, mixed>> The field definition.
	 */
	private function groupBucketJoinedField(): array {
		return [
			'joined' => [
				'type' => $this->scalars['JSON'],
				'description' => 'Figures pulled from a joined schema, keyed '
					. '`<Schema>.<field>`. Present only when the aggregation declares a join.',
			],
		];
	}//end groupBucketJoinedField()

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
				'values' => $this->enumValues(names: self::TIME_INTERVALS),
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
				'values' => $this->enumValues(names: self::AGGREGATION_METRICS),
			]
		);

		return $this->aggMetricType;
	}//end getAggregationMetricType()

	/**
	 * Spell a list of enum names as graphql-php enum values, each name its own value.
	 *
	 * @param list<string> $names The enum names, in schema order.
	 *
	 * @return array<string, array{value: string}> The enum value definitions.
	 */
	private function enumValues(array $names): array {
		$values = [];
		foreach ($names as $name) {
			$values[$name] = ['value' => $name];
		}

		return $values;
	}//end enumValues()

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
				'fields' => array_merge($this->groupByGroupingFields(), $this->groupByMetricFields()),
			]
		);

		return $this->groupByInputType;
	}//end getGroupByInputType()

	/**
	 * The GroupByInput fields that say how rows are grouped: `field`, `interval`, `from` and `to`.
	 *
	 * @return array<string, array<string, mixed>> The field definitions, in schema order.
	 */
	private function groupByGroupingFields(): array {
		return array_merge($this->groupByFieldAndInterval(), $this->groupByTimeRange());
	}//end groupByGroupingFields()

	/**
	 * The GroupByInput fields that say what is computed per group: `metric`, `metricField`, `fields` and `metrics`.
	 *
	 * @return array<string, array<string, mixed>> The field definitions, in schema order.
	 */
	private function groupByMetricFields(): array {
		return array_merge($this->groupBySingleMetric(), $this->groupByCompositeFields());
	}//end groupByMetricFields()

	/**
	 * The GroupByInput fields naming what to group on: `field` and `interval`.
	 *
	 * @return array<string, array<string, mixed>> The field definitions, in schema order.
	 */
	private function groupByFieldAndInterval(): array {
		return [
			'field' => [
				'type' => Type::nonNull(Type::string()),
				'description' => 'Field to group on. Must be a declared schema property or magic metadata column.',
			],
			'interval' => [
				'type' => $this->getTimeIntervalType(),
				'description' => 'Optional bucketing interval. When supplied, requires `from` + `to`.',
			],
		];
	}//end groupByFieldAndInterval()

	/**
	 * The GroupByInput fields bounding a time-bucketed grouping: `from` and `to`.
	 *
	 * @return array<string, array<string, mixed>> The field definitions, in schema order.
	 */
	private function groupByTimeRange(): array {
		return [
			'from' => [
				'type' => Type::string(),
				'description' => 'ISO-8601 lower bound, inclusive. Required when `interval` is set.',
			],
			'to' => [
				'type' => Type::string(),
				'description' => 'ISO-8601 upper bound, exclusive. Required when `interval` is set.',
			],
		];
	}//end groupByTimeRange()

	/**
	 * The GroupByInput fields of a single-metric grouping: `metric` and `metricField`.
	 *
	 * @return array<string, array<string, mixed>> The field definitions, in schema order.
	 */
	private function groupBySingleMetric(): array {
		return [
			'metric' => [
				'type' => $this->getAggregationMetricType(),
				'defaultValue' => 'COUNT',
				'description' => 'Aggregation metric. Default COUNT.',
			],
			'metricField' => [
				'type' => Type::string(),
				'description' => 'Field to aggregate over. Required when metric != COUNT.',
			],
		];
	}//end groupBySingleMetric()

	/**
	 * The GroupByInput fields of a cross-tab or multi-metric grouping: `fields` and `metrics`.
	 *
	 * @return array<string, array<string, mixed>> The field definitions, in schema order.
	 */
	private function groupByCompositeFields(): array {
		return [
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
		];
	}//end groupByCompositeFields()

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
				'fields' => array_merge($this->metricInputFigureFields(), $this->metricInputScopeFields()),
			]
		);

		return $this->metricInputType;
	}//end getAggregationMetricInputType()

	/**
	 * The AggregationMetricInput fields naming the figure: `metric` and `field`.
	 *
	 * @return array<string, array<string, mixed>> The field definitions, in schema order.
	 */
	private function metricInputFigureFields(): array {
		return [
			'metric' => [
				'type' => Type::nonNull($this->getAggregationMetricType()),
				'description' => 'The metric to compute.',
			],
			'field' => [
				'type' => Type::string(),
				'description' => 'Field to aggregate. Required for every metric except COUNT.',
			],
		];
	}//end metricInputFigureFields()

	/**
	 * The AggregationMetricInput fields scoping and naming the figure: `condition` and `as`.
	 *
	 * @return array<string, array<string, mixed>> The field definitions, in schema order.
	 */
	private function metricInputScopeFields(): array {
		return [
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
		];
	}//end metricInputScopeFields()

}//end class
