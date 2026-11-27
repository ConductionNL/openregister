<?php

/**
 * TokenCeiling
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Db
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Rbac\TokenGrantNarrower;

/**
 * A token's ceiling as the magic mapper applies it: the predicate every row a
 * granted call reaches must also meet, and the same refusal for one row,
 * whoever the caller is.
 *
 * It is AND-ed onto the query rather than folded into the rules, because the
 * administrator and owner admits are not rules: they reach rows without
 * reading the block, and a grant they escape is not a ceiling (task 2.1b).
 * The verbs and the row condition come from the marker the narrowed block
 * carries, the same marker the PHP ceiling reads, so the two verdicts read
 * one condition from one place.
 *
 * @spec openspec/changes/scoped-api-tokens/tasks.md#2-1c
 */
final class TokenCeiling {

	/**
	 * The predicate no row meets.
	 *
	 * @var string
	 */
	public const NONE = '1 = 0';

	/**
	 * Constructor.
	 *
	 * @param TokenGrantNarrower $narrower The reader of the marker.
	 * @param RbacMatchSql       $compiler The compiler of `match` blocks.
	 * @param ConditionMatcher   $matcher  The evaluator of `match` blocks on one object.
	 */
	public function __construct(
		private readonly TokenGrantNarrower $narrower,
		private readonly RbacMatchSql $compiler,
		private readonly ConditionMatcher $matcher,
	) {
	}//end __construct()

	/**
	 * The ceiling of a call whose authorization was resolved with a grant bound.
	 *
	 * A block without a marker was not narrowed, although a grant is bound,
	 * so the narrowing cannot be shown to have happened and no row is reached.
	 * A verb the grant does not hold reaches no row. A grant without a row
	 * condition limits verbs only, and puts no predicate on the rows. A
	 * condition naming a key the table lacks, or one that compiles to nothing,
	 * reaches no row.
	 *
	 * @param RbacAccessCall $call The call, its authorization resolved.
	 *
	 * @return string|null The predicate, or null when the grant puts none on the rows.
	 *
	 * @spec openspec/changes/scoped-api-tokens/tasks.md#2-1c
	 */
	public function predicate(RbacAccessCall $call): ?string {
		if (is_array(($call->authorization[TokenGrantNarrower::MARKER] ?? null)) === false) {
			return self::NONE;
		}

		if ($this->narrower->markerPermits(authorization: $call->authorization, action: $call->action) === false) {
			return self::NONE;
		}

		$match = $this->narrower->markerMatch(authorization: $call->authorization);
		if ($match === null) {
			return null;
		}

		if ($call->columns()->admits(match: $match) === false) {
			return self::NONE;
		}

		return ($this->compiler->matchConditions(block: $match, columnPrefix: $call->columnPrefix) ?? self::NONE);
	}//end predicate()

	/**
	 * Whether the ceiling refuses one action on one object.
	 *
	 * The same reading as {@see predicate()}: no marker or a verb the grant
	 * lacks refuses; a row condition refuses an object that does not meet it,
	 * or names metadata no record carries. A question about no object is
	 * answered by the verbs alone.
	 *
	 * @param array|null $authorization The resolved, narrowed block.
	 * @param string     $action        The action.
	 * @param array|null $objectData    The object as a `match` clause reads it, null for none.
	 *
	 * @return bool True when refused.
	 *
	 * @spec openspec/changes/scoped-api-tokens/tasks.md#1-1d
	 */
	public function refuses(?array $authorization, string $action, ?array $objectData): bool {
		if (is_array(($authorization[TokenGrantNarrower::MARKER] ?? null)) === false
			|| $this->narrower->markerPermits(authorization: $authorization, action: $action) === false
		) {
			return true;
		}

		$match = $this->narrower->markerMatch(authorization: $authorization);
		if ($match === null || $objectData === null) {
			return false;
		}

		return $this->matcher->namesUnknownMetadata(match: $match, object: $objectData) === true
			|| $this->matcher->objectMatchesConditions(object: $objectData, match: $match) === false;
	}//end refuses()

	/**
	 * An access result with the ceiling applied.
	 *
	 * An open result (`bypass`) becomes the ceiling alone; every condition of
	 * a narrowed result is AND-ed with it, and an empty one stays empty, which
	 * already reaches no row.
	 *
	 * @param array{bypass: bool, conditions: array<int, string>} $result  The result without the ceiling.
	 * @param string                                              $ceiling The predicate.
	 *
	 * @return array{bypass: bool, conditions: array<int, string>} The result under the ceiling.
	 *
	 * @spec openspec/changes/scoped-api-tokens/tasks.md#2-1c
	 */
	public static function under(array $result, string $ceiling): array {
		if (($result['bypass'] ?? false) === true) {
			return ['bypass' => false, 'conditions' => [$ceiling]];
		}

		return [
			'bypass' => false,
			'conditions' => array_map(
				static fn (string $condition): string => '(' . $condition . ') AND (' . $ceiling . ')',
				($result['conditions'] ?? [])
			),
		];
	}//end under()
}//end class
