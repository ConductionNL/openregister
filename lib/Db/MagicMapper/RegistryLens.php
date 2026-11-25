<?php

/**
 * RegistryLens: the `_registry[state]` and `_registry[updatedBefore]` query lenses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-subscription-state-and-freshness-are-query-lenses
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use OCA\OpenRegister\Db\RegistrySubscription;
use OCA\OpenRegister\Exception\RegistryLensException;

/**
 * Reads the `_registry` lens out of a query and writes its SQL.
 *
 * `_registry[state]` keeps the objects whose registry subscription is in one
 * of the named states (a single state, a comma-separated list or an array).
 * An object without a subscription row is in state `none`, the state a new
 * row starts in, so `none` also matches objects that never had one.
 *
 * `_registry[updatedBefore]` keeps the objects whose subscription last
 * received an update before the given moment. A subscription that never
 * received one is staler than any moment and matches, and so does an object
 * without a subscription row.
 *
 * Both keys together narrow to the objects that satisfy both. A lens that
 * cannot be read is refused rather than dropped: a dropped lens answers the
 * whole list to a deliberately narrow question.
 *
 * The SQL is one EXISTS over `openregister_registry_subs` (and, for `none`,
 * a NOT EXISTS), against the outer magic table's `_uuid`. Every value is
 * bound through the caller's binder, so it lands on the OUTER query builder,
 * whose parameters are the ones bound at execution.
 *
 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-subscription-state-and-freshness-are-query-lenses
 */
final class RegistryLens {

	/**
	 * The query key.
	 */
	public const KEY = '_registry';

	/**
	 * The subscription table, prefixed at execution.
	 */
	private const TABLE = '*PREFIX*openregister_registry_subs';

	/**
	 * The subscription alias inside the subquery.
	 */
	private const ALIAS = 'cn_rs';

	/**
	 * The states a subscription can be in.
	 */
	private const STATES = [
		RegistrySubscription::STATE_NONE,
		RegistrySubscription::STATE_REQUESTED,
		RegistrySubscription::STATE_ACTIVE,
		RegistrySubscription::STATE_ENDED,
		RegistrySubscription::STATE_FAILED,
	];

	/**
	 * The keys the lens reads.
	 */
	private const KEYS = ['state', 'updatedBefore'];

	/**
	 * The lens a query carries, or null when it carries none.
	 *
	 * @param array<string, mixed> $query The request query.
	 *
	 * @return array{states: list<string>|null, updatedBefore: string|null}|null The states, and the moment
	 *         as a UTC `Y-m-d H:i:s`, each null when not asked for.
	 *
	 * @throws RegistryLensException When the lens names an unknown key, state or an unreadable moment.
	 *
	 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-subscription-state-and-freshness-are-query-lenses
	 */
	public function parse(array $query): ?array {
		if (array_key_exists(self::KEY, $query) === false) {
			return null;
		}

		$lens = $query[self::KEY];
		if (is_array($lens) === false || $lens === []) {
			throw new RegistryLensException("'_registry' takes 'state' and/or 'updatedBefore', as _registry[state]=active.");
		}

		$unknown = array_diff(array_map('strval', array_keys($lens)), self::KEYS);
		if ($unknown !== []) {
			throw new RegistryLensException(
				sprintf("'_registry' does not know '%s'; it takes 'state' and 'updatedBefore'.", implode("', '", $unknown))
			);
		}

		return [
			'states' => self::states(value: ($lens['state'] ?? null)),
			'updatedBefore' => self::moment(value: ($lens['updatedBefore'] ?? null)),
		];
	}//end parse()

	/**
	 * The condition of a parsed lens.
	 *
	 * @param array{states: list<string>|null, updatedBefore: string|null} $lens      The parsed lens.
	 * @param string                                                         $outerUuid The qualified uuid column of the outer table.
	 * @param callable(mixed, bool): string                                  $bind      Binds a value (true: a list of strings) and returns its placeholder.
	 *
	 * @return string The condition; `1 = 1` when the lens asks for nothing.
	 *
	 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-subscription-state-and-freshness-are-query-lenses
	 */
	public function condition(array $lens, string $outerUuid, callable $bind): string {
		$where = [];
		if ($lens['states'] !== null) {
			$where[] = self::ALIAS . '.state IN (' . $bind($lens['states'], true) . ')';
		}

		if ($lens['updatedBefore'] !== null) {
			$where[] = '(' . self::ALIAS . '.last_update_at IS NULL OR ' . self::ALIAS . '.last_update_at < ' . $bind($lens['updatedBefore'], false) . ')';
		}

		if ($where === []) {
			return '1 = 1';
		}

		$exists = 'EXISTS (SELECT 1 FROM ' . self::TABLE . ' ' . self::ALIAS
			. ' WHERE ' . self::ALIAS . '.object_uuid = ' . $outerUuid . ' AND ' . implode(' AND ', $where) . ')';

		// An object without a row is in state `none` and has never been updated.
		if ($lens['states'] === null || in_array(RegistrySubscription::STATE_NONE, $lens['states'], true) === true) {
			$none = 'NOT EXISTS (SELECT 1 FROM ' . self::TABLE . ' ' . self::ALIAS . 'n WHERE ' . self::ALIAS . 'n.object_uuid = ' . $outerUuid . ')';
			return '(' . $exists . ' OR ' . $none . ')';
		}

		return $exists;
	}//end condition()

	/**
	 * The states asked for, or null when none.
	 *
	 * @param mixed $value The `state` value: a state, a comma-separated list or a list.
	 *
	 * @return list<string>|null The states.
	 *
	 * @throws RegistryLensException When a value is not a known state.
	 */
	private static function states(mixed $value): ?array {
		if ($value === null) {
			return null;
		}

		$values = $value;
		if (is_string($value) === true) {
			$values = explode(',', $value);
		}

		if (is_array($values) === false) {
			throw new RegistryLensException("'_registry[state]' takes a state or a list of states.");
		}

		$states = [];
		foreach ($values as $state) {
			$state = is_string($state) === true ? strtolower(trim($state)) : $state;
			if (in_array($state, self::STATES, true) === false) {
				throw new RegistryLensException(
					sprintf("'_registry[state]' takes %s.", implode(', ', self::STATES))
				);
			}

			$states[] = $state;
		}

		return array_values(array_unique($states));
	}//end states()

	/**
	 * The moment asked for, as a UTC `Y-m-d H:i:s`, or null when none.
	 *
	 * @param mixed $value The `updatedBefore` value: a date or date-time.
	 *
	 * @return string|null The moment.
	 *
	 * @throws RegistryLensException When the value is not a date.
	 */
	private static function moment(mixed $value): ?string {
		if ($value === null) {
			return null;
		}

		$refusal = "'_registry[updatedBefore]' takes a date or date-time, as 2026-10-24 or 2026-10-24T09:00:00Z.";
		if (is_string($value) === false || trim($value) === '' || preg_match('/^\d{4}-\d{2}-\d{2}/', trim($value)) !== 1) {
			throw new RegistryLensException($refusal);
		}

		try {
			$moment = new DateTimeImmutable(trim($value), new DateTimeZone('UTC'));
		} catch (Exception $e) {
			throw new RegistryLensException($refusal);
		}

		return $moment->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
	}//end moment()
}//end class
