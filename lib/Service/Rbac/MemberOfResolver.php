<?php

/**
 * The caller's set for a `$memberOf` match: values held by their membership rows.
 *
 * `{"administrationId": {"$memberOf": {"schema": "administration-membership",
 * "property": "administrationId"}}}` admits an object whose `administrationId`
 * is one of the `administrationId` values on the caller's rows of the
 * membership schema. This class answers which values those are.
 *
 * 🔴 THE READ RUNS WITHOUT RBAC, ON PURPOSE, as DepartmentMatrixPersonValues'
 * does: it is called while a permission is being decided, and a read that asked
 * the permission handler again would re-enter that decision. Nothing read here
 * is returned to anyone but the rule engine.
 *
 * 🔴 EVERYTHING THAT GOES WRONG DENIES. A malformed operand is unresolvable
 * (null, which every interpreter treats as deny); a read that fails or finds
 * nothing is the empty set, which `$in` admits nothing for.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/access-follows-membership/specs/rbac-scopes/spec.md#requirement-a-match-can-admit-by-the-callers-membership-records
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\OperatorEvaluator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves a `$memberOf` operand to the caller's set, once per request.
 */
class MemberOfResolver {

	/**
	 * The operator this class resolves.
	 *
	 * @var string
	 */
	public const OPERATOR = '$memberOf';

	/**
	 * The membership field matched against the user id when the operand names none.
	 *
	 * @var string
	 */
	public const DEFAULT_MATCH = 'userId';

	/**
	 * At most this many membership rows are read for one caller and operand.
	 * Past it the set is truncated, which can only hide rows.
	 *
	 * @var int
	 */
	public const ROW_LIMIT = 500;

	/**
	 * Sets per caller and operand, for the rest of the request.
	 *
	 * @var array<string, string[]>
	 */
	private array $cache = [];

	/**
	 * Constructor.
	 *
	 * @param SchemaMapper      $schemas   Loads the membership schema.
	 * @param RegisterMapper    $registers Finds the register that holds it.
	 * @param MagicMapper       $objects   Reads the membership rows.
	 * @param OperatorEvaluator $operators Evaluates the operand's `where` filters.
	 * @param LoggerInterface   $logger    Logs a set that could not be read.
	 */
	public function __construct(
		private readonly SchemaMapper $schemas,
		private readonly RegisterMapper $registers,
		private readonly MagicMapper $objects,
		private readonly OperatorEvaluator $operators,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The values the caller's membership rows hold for the operand.
	 *
	 * @param mixed       $operand The `$memberOf` operand.
	 * @param string|null $userId  The current user, or null when nobody is signed in.
	 *
	 * @return string[]|null The set, or null when the operand is malformed.
	 *
	 * @spec openspec/changes/access-follows-membership/specs/rbac-scopes/spec.md#requirement-a-match-can-admit-by-the-callers-membership-records
	 */
	public function valuesFor(mixed $operand, ?string $userId): ?array {
		$spec = $this->normalise(operand: $operand);
		if ($spec === null) {
			$this->logger->warning(
				message: '[MemberOfResolver] A $memberOf operand is malformed; the rule admits nobody',
				context: ['operand' => $operand]
			);
			return null;
		}

		if ($userId === null || $userId === '') {
			return [];
		}

		$key = $userId . '|' . json_encode($spec);
		if (array_key_exists($key, $this->cache) === false) {
			$this->cache[$key] = $this->read(spec: $spec, userId: $userId);
		}

		return $this->cache[$key];
	}//end valuesFor()

	/**
	 * Check the operand and fill in its defaults.
	 *
	 * @param mixed $operand The `$memberOf` operand.
	 *
	 * @return array{schema: string, register: string, property: string, match: string, where: array<string, mixed>, current: array{from: string, until: string}|null}|null
	 *         The operand, or null when it is malformed.
	 */
	private function normalise(mixed $operand): ?array {
		if (is_array($operand) === false || array_is_list($operand) === true) {
			return null;
		}

		$schema   = trim((string)($operand['schema'] ?? ''));
		$property = trim((string)($operand['property'] ?? ''));
		$match    = trim((string)($operand['match'] ?? self::DEFAULT_MATCH));
		$where    = ($operand['where'] ?? []);
		$current  = ($operand['current'] ?? null);
		if ($schema === '' || $property === '' || $match === '' || is_array($where) === false) {
			return null;
		}

		if ($where !== [] && array_is_list($where) === true) {
			return null;
		}

		foreach ($where as $condition) {
			if (is_array($condition) === true && array_key_exists(self::OPERATOR, $condition) === true) {
				return null;
			}
		}

		if ($current !== null) {
			$current = $this->normaliseWindow(current: $current);
			if ($current === null) {
				return null;
			}
		}

		ksort($where);

		return [
			'schema'   => $schema,
			'register' => trim((string)($operand['register'] ?? '')),
			'property' => $property,
			'match'    => $match,
			'where'    => $where,
			'current'  => $current,
		];
	}//end normalise()

	/**
	 * Check the validity window: at least one of `from` and `until` names a field.
	 *
	 * @param mixed $current The operand's `current`.
	 *
	 * @return array{from: string, until: string}|null The window, or null when it is malformed.
	 */
	private function normaliseWindow(mixed $current): ?array {
		if (is_array($current) === false) {
			return null;
		}

		$from  = trim((string)($current['from'] ?? ''));
		$until = trim((string)($current['until'] ?? ''));
		if ($from === '' && $until === '') {
			return null;
		}

		return ['from' => $from, 'until' => $until];
	}//end normaliseWindow()

	/**
	 * Read the caller's membership rows and collect the set.
	 *
	 * @param array  $spec   The normalised operand.
	 * @param string $userId The current user.
	 *
	 * @return string[] The set.
	 */
	private function read(array $spec, string $userId): array {
		try {
			$schema = $this->schemas->find($spec['schema'], _rbac: false, _multitenancy: false);

			$registerId = $spec['register'];
			if ($registerId === '') {
				$registerId = (string)($this->registers->getFirstRegisterWithSchema((int)$schema->getId()) ?? '');
			}

			if ($registerId === '') {
				return [];
			}

			$register = $this->registers->find(id: $registerId, _rbac: false, _multitenancy: false);

			$rows = $this->objects->searchObjects(
				query: [
					'@self'        => ['register' => (string)$register->getId(), 'schema' => (string)$schema->getId()],
					$spec['match'] => $userId,
					'_limit'       => self::ROW_LIMIT,
				],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[MemberOfResolver] Could not read the membership rows; the caller is a member of nothing',
				context: ['schema' => $spec['schema'], 'error' => $e->getMessage()]
			);
			return [];
		}//end try

		if (is_array($rows) === false) {
			return [];
		}

		if (count($rows) >= self::ROW_LIMIT) {
			$this->logger->warning(
				message: '[MemberOfResolver] The caller has more membership rows than are read; the set is truncated',
				context: ['schema' => $spec['schema'], 'limit' => self::ROW_LIMIT]
			);
		}

		$kept = [];
		foreach ($rows as $row) {
			$data = $row;
			if ($row instanceof ObjectEntity === true) {
				$data = ($row->getObject() ?? []);
			}

			if (is_array($data) === true && $this->rowCounts(data: $data, spec: $spec) === true) {
				$kept[] = $data;
			}
		}

		return DepartmentMatrixPersonValues::collect(
			persons: $kept,
			property: $spec['property'],
			match: $spec['match'],
			userId: $userId
		);
	}//end read()

	/**
	 * Whether a membership row passes the operand's filters and window.
	 *
	 * @param array<string, mixed> $data The row's data.
	 * @param array                $spec The normalised operand.
	 *
	 * @return bool True when the row counts.
	 */
	private function rowCounts(array $data, array $spec): bool {
		foreach ($spec['where'] as $field => $expected) {
			$actual = ($data[$field] ?? null);
			if (is_array($expected) === true) {
				if ($this->operators->valueMatchesOperator(value: $actual, operators: $expected) === false) {
					return false;
				}

				continue;
			}

			if ($actual !== $expected) {
				return false;
			}
		}

		if ($spec['current'] === null) {
			return true;
		}

		$today = date('Y-m-d');
		$from  = $this->dayOf(value: ($data[$spec['current']['from']] ?? null));
		$until = $this->dayOf(value: ($data[$spec['current']['until']] ?? null));

		return ($from === '' || $from <= $today) && ($until === '' || $until >= $today);
	}//end rowCounts()

	/**
	 * The `Y-m-d` day of a stored date, or '' when there is none.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return string The day.
	 */
	private function dayOf(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		return substr(trim($value), 0, 10);
	}//end dayOf()
}//end class
