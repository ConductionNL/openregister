<?php

/**
 * OpenRegister RelativeTimeSweep
 *
 * Selects the objects of a schema for which a `$age` condition holds, with
 * one indexed comparison instead of a per-object evaluation.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;

/**
 * The query half of a relative-time rule (task 3.3b).
 *
 * {@see RelativeTimeEvaluation::comparisonFor()} compiles the condition into
 * one property, one operator and one instant; this class hands that to the
 * object search as an operator filter, so the database selects the due
 * objects (`WHERE <column> <= ?`) and nothing walks the schema in PHP.
 *
 * One call covers ONE organisation: a business-unit condition counts in the
 * organisation's calendar, so objects of two organisations can have two
 * thresholds. The organisation is therefore always part of the query
 * (`IS NULL` for objects without one), and a caller sweeping a schema asks
 * once per organisation.
 *
 * Selecting is all this does. What a due object then triggers (an automatic
 * transition, a notification) runs through its own engine, with that
 * engine's checks.
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
 */
final class RelativeTimeSweep {

	/**
	 * The search operator each compiled comparison maps to.
	 *
	 * @var array<string, string>
	 */
	public const SEARCH_OPERATORS = ['<=' => 'lte', '>' => 'gt'];

	/**
	 * Constructor.
	 *
	 * @param RelativeTimeEvaluation    $evaluation The clock, the calendar and the compiled comparison.
	 * @param ObjectOperationsInterface $objects    The object search.
	 */
	public function __construct(
		private readonly RelativeTimeEvaluation $evaluation,
		private readonly ObjectOperationsInterface $objects,
	) {
	}//end __construct()

	/**
	 * The search query selecting the due objects of one schema and organisation.
	 *
	 * @param int|string           $register     The register id.
	 * @param int|string           $schema       The schema id.
	 * @param mixed                $node         The `$age` node.
	 * @param string|null          $organisation The organisation, or null for objects without one.
	 * @param array<string, mixed> $filters      Further filters (e.g. the state a transition leaves), ANDed.
	 *
	 * @return array<string, mixed>|null The query, or null when the node is not a `$age` node.
	 *
	 * @throws ConditionRefusedException When the condition or its calendar cannot be read.
	 * @throws InvalidArgumentException  When a further filter already constrains the compared property or `@self`.
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
	 */
	public function query(int|string $register, int|string $schema, mixed $node, ?string $organisation, array $filters = []): ?array {
		$comparison = $this->evaluation->comparisonFor(node: $node, organisation: $organisation);
		if ($comparison === null) {
			return null;
		}

		$property = $comparison['property'];
		foreach ([$property, '@self'] as $reserved) {
			if (array_key_exists($reserved, $filters) === true) {
				throw new InvalidArgumentException(
					sprintf('A relative-time sweep sets "%s" itself; a further filter may not constrain it', $reserved)
				);
			}
		}

		$organisationFilter = 'IS NULL';
		if ($organisation !== null && $organisation !== '') {
			$organisationFilter = $organisation;
		}

		$query = $filters;
		$query['@self'] = [
			'register' => (string)$register,
			'schema' => (string)$schema,
			'organisation' => $organisationFilter,
		];
		$query[$property] = [self::SEARCH_OPERATORS[$comparison['operator']] => self::utc(instant: $comparison['value'])];

		return $query;
	}//end query()

	/**
	 * The due objects of one schema and organisation.
	 *
	 * The access flags are the caller's: a background sweep has no request
	 * user and says so explicitly, a request-bound caller keeps both checks.
	 *
	 * @param int|string           $register     The register id.
	 * @param int|string           $schema       The schema id.
	 * @param mixed                $node         The `$age` node.
	 * @param string|null          $organisation The organisation, or null for objects without one.
	 * @param array<string, mixed> $filters      Further filters, ANDed.
	 * @param bool                 $rbac         Whether the caller's RBAC applies.
	 * @param bool                 $multitenancy Whether the caller's multitenancy applies.
	 *
	 * @return array<int, mixed> The due objects; none when the node is not a `$age` node.
	 *
	 * @throws ConditionRefusedException When the condition or its calendar cannot be read.
	 * @throws InvalidArgumentException  When a further filter constrains the compared property or `@self`.
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
	 */
	public function dueObjects(
		int|string $register,
		int|string $schema,
		mixed $node,
		?string $organisation,
		array $filters = [],
		bool $rbac = true,
		bool $multitenancy = true,
	): array {
		$query = $this->query(register: $register, schema: $schema, node: $node, organisation: $organisation, filters: $filters);
		if ($query === null) {
			return [];
		}

		$found = $this->objects->searchObjects(query: $query, _rbac: $rbac, _multitenancy: $multitenancy);
		if (is_array($found) === false) {
			return [];
		}

		return array_values($found);
	}//end dueObjects()

	/**
	 * The compiled instant in UTC, so stored values and the threshold share one offset.
	 *
	 * @param string $instant The compiled instant (ATOM).
	 *
	 * @return string The same instant, in UTC (ATOM).
	 */
	private static function utc(string $instant): string {
		return (new DateTimeImmutable($instant))->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
	}//end utc()
}//end class
