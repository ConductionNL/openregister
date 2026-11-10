<?php

/**
 * The optional aggregations of a GraphQL list query.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\GraphQL
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 *
 * @spec openspec/specs/graphql-api/spec.md
 */

namespace OCA\OpenRegister\Service\GraphQL;

use GraphQL\Error\Error;
use InvalidArgumentException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Search\Service\Aggregation\AggregationQuery;
use OCA\OpenRegister\Search\Service\Aggregation\TimeseriesRequestValidatorInterface;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\Aggregation\NamedAggregationRef;
use OCA\OpenRegister\Service\Aggregation\NamedAggregationOptions;
use RuntimeException;

/**
 * Runs a list query's `groupBy` and its declared `aggregation`.
 *
 * A failure is thrown as a GraphQL `Error`, so the field-level error surface picks it
 * up and the rest of the connection still resolves.
 *
 * @spec openspec/specs/graphql-api/spec.md
 */
final class ListAggregations {

	/**
	 * Constructor.
	 *
	 * @param AggregationRunner          $runner    The aggregation dispatcher.
	 * @param TimeseriesRequestValidatorInterface $validator The validator the REST endpoint uses too.
	 */
	public function __construct(
		private readonly AggregationRunner $runner,
		private readonly TimeseriesRequestValidatorInterface $validator,
	) {
	}//end __construct()

	/**
	 * Add what the query asked for to its connection.
	 *
	 * @param array<string, mixed> $connection The connection.
	 * @param ListRequest          $request    The list query.
	 *
	 * @return array<string, mixed> The connection with its aggregations.
	 *
	 * @throws Error When an aggregation is invalid, unknown or refused.
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	public function addTo(array $connection, ListRequest $request): array {
		$groupBy = $request->groupBy();
		if ($groupBy !== null) {
			$connection['groups'] = $this->groups(request: $request, groupBy: $groupBy);
		}

		$name = $request->aggregationName();
		if ($name !== null) {
			$connection['aggregation'] = $this->declared(request: $request, name: $name);
		}

		return $connection;
	}//end addTo()

	/**
	 * Run the ad-hoc `groupBy`.
	 *
	 * @param ListRequest          $request The list query.
	 * @param array<string, mixed> $groupBy The groupBy argument.
	 *
	 * @return array<int, array<string, mixed>>|null The buckets, or null without a register.
	 *
	 * @throws Error When validation fails or RBAC denies the request.
	 */
	private function groups(ListRequest $request, array $groupBy): ?array {
		if ($request->register === null) {
			return null;
		}

		$query = $this->validated(request: $request, groupBy: $groupBy);

		try {
			$result = $this->runner->runAdhoc(register: $request->register, schema: $request->schema, query: $query);
		} catch (NotAuthorizedException $e) {
			throw new Error($e->getMessage());
		}

		return $this->buckets(groups: ($result['groups'] ?? []));
	}//end groups()

	/**
	 * Validate the `groupBy` into an aggregation query.
	 *
	 * @param ListRequest          $request The list query.
	 * @param array<string, mixed> $groupBy The groupBy argument.
	 *
	 * @return AggregationQuery The query.
	 *
	 * @throws Error When validation fails.
	 */
	private function validated(ListRequest $request, array $groupBy): AggregationQuery {
		$input = $this->validatorInput(groupBy: $groupBy);
		$input['filter'] = $request->propertyFilter();

		try {
			return $this->validator->validate(input: $input, schema: $request->schema);
		} catch (InvalidArgumentException $e) {
			throw new Error($e->getMessage());
		}
	}//end validated()

	/**
	 * The `groupBy` argument in the validator's input shape.
	 *
	 * @param array<string, mixed> $groupBy The groupBy argument.
	 *
	 * @return array<string, mixed> The validator input.
	 */
	private function validatorInput(array $groupBy): array {
		return [
			'field' => (string)$groupBy['field'],
			'interval' => ($groupBy['interval'] ?? null),
			'from' => ($groupBy['from'] ?? null),
			'to' => ($groupBy['to'] ?? null),
			'metric' => strtolower((string)($groupBy['metric'] ?? 'count')),
			'metricField' => ($groupBy['metricField'] ?? null),
			'fields' => ($groupBy['fields'] ?? null),
			'metrics' => ($groupBy['metrics'] ?? null),
		];
	}//end validatorInput()

	/**
	 * Run a DECLARED aggregation by name.
	 *
	 * The query's property filter is a NARROWING constraint: the engine refuses any
	 * request key the declaration already pins, so a caller can add a constraint and
	 * never relax a declared one. The envelope is returned as the REST endpoint emits
	 * it, because its shape varies with what the aggregation declares.
	 *
	 * @param ListRequest $request The list query.
	 * @param string      $name    The declared aggregation's name.
	 *
	 * @return array<string, mixed>|null The result envelope, or null without a register.
	 *
	 * @throws Error When the aggregation is unknown, malformed, or RBAC denies it.
	 */
	private function declared(ListRequest $request, string $name): ?array {
		if ($request->register === null) {
			return null;
		}

		try {
			return $this->runner->run(
				ref: new NamedAggregationRef(
					register: (string)$request->register->getSlug(),
					schema: (string)$request->schema->getSlug(),
					name: $name
				),
				options: new NamedAggregationOptions(bypassRbac: false, extraFilter: $request->propertyFilter())
			);
		} catch (NotAuthorizedException | RuntimeException $e) {
			throw new Error($e->getMessage());
		}
	}//end declared()

	/**
	 * Map engine buckets onto the GraphQL `GroupBucket` shape.
	 *
	 * @param array<int, mixed> $groups Engine buckets.
	 *
	 * @return array<int, array<string, mixed>> Buckets in GroupBucket shape.
	 */
	private function buckets(array $groups): array {
		$buckets = array_filter($groups, static fn (mixed $bucket): bool => is_array(value: $bucket) === true);

		return array_values(array_map(fn (array $bucket): array => $this->bucket(bucket: $bucket), $buckets));
	}//end buckets()

	/**
	 * One bucket, keeping what the engine said.
	 *
	 * A null key stays null, so it differs from a key that is the empty string. A
	 * multi-metric bucket carries `values` and no `value`, so its `value` stays null, not 0.0.
	 *
	 * @param array<string, mixed> $bucket The engine bucket.
	 *
	 * @return array<string, mixed> The GroupBucket.
	 */
	private function bucket(array $bucket): array {
		return [
			'key' => $this->bucketKey(bucket: $bucket),
			'value' => $this->bucketValue(bucket: $bucket),
			'keys' => ($bucket['keys'] ?? null),
			'values' => ($bucket['values'] ?? null),
			'joined' => ($bucket['joined'] ?? null),
		];
	}//end bucket()

	/**
	 * The key of a bucket as a string, or null.
	 *
	 * @param array<string, mixed> $bucket The engine bucket.
	 *
	 * @return string|null The key.
	 */
	private function bucketKey(array $bucket): ?string {
		if (isset($bucket['key']) === false) {
			return null;
		}

		return (string)$bucket['key'];
	}//end bucketKey()

	/**
	 * The value of a bucket as a float, or null.
	 *
	 * @param array<string, mixed> $bucket The engine bucket.
	 *
	 * @return float|null The value.
	 */
	private function bucketValue(array $bucket): ?float {
		if (isset($bucket['value']) === false) {
			return null;
		}

		return (float)$bucket['value'];
	}//end bucketValue()
}//end class
