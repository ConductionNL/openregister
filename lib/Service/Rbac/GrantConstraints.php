<?php

/**
 * A grant may end, and a grant may be scoped to an area.
 *
 * Two constraints on an entry, read in one pass, because both answer the same
 * question: does this rule reach this caller HERE and NOW, or is it written for
 * a different place or a moment that has passed.
 *
 * AN END NEEDS NO SWEEP. A grant carrying `until` is evaluated at resolution
 * time, so the moment it passes the grant stops answering. Nothing has to run to
 * take it away, which matters because the job that takes a right away is the one
 * nobody notices has stopped (design D-11). A workflow step that grants access
 * until its deadline writes that deadline into the grant: no second clock, and a
 * step that moves its deadline rewrites the grant the same way it wrote it.
 *
 * SCOPED ADMINISTRATION IS NOT A SECOND ADMINISTRATOR. `manage` scoped to a
 * named register lets somebody administer their own area without administering
 * the instance, which is the difference between delegating and handing over.
 * The constraint is not special-cased to `manage`: any verb may be scoped, and
 * `manage` is simply the one it was asked for.
 *
 * THE KEY IS `scopedTo`, NOT `scope`. A block already carries a top-level
 * `scope` naming an object's visibility (`private`, `organisation`), and a
 * second meaning for one word inside the same block is how a rule ends up read
 * by the wrong reader.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Drops the entries of a block that have expired or belong to another area.
 *
 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
 */
class GrantConstraints {

	/**
	 * The entry key carrying the moment a grant stops answering.
	 *
	 * @var string
	 */
	public const UNTIL_KEY = 'until';

	/**
	 * The entry key carrying the area a grant is confined to.
	 *
	 * @var string
	 */
	public const SCOPED_TO_KEY = 'scopedTo';

	/**
	 * Block keys that are settings rather than lists of entries.
	 *
	 * @var array<int, string>
	 */
	private const SKIPPED_KEYS = ['public', 'inheritFromPublic', ObjectScopeResolver::SCOPE_KEY];

	/**
	 * Whether any entry in this block carries a constraint at all.
	 *
	 * The cheap exit, and the backwards-compatibility promise made structural:
	 * a block that names neither key is returned untouched, so an instance that
	 * writes no ending and no scope resolves exactly as it did before this
	 * existed.
	 *
	 * @param array|null $authorization The block as written.
	 *
	 * @return bool True when something in the block has to be read.
	 *
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	public function declaresAnyConstraint(?array $authorization): bool {
		if (is_array($authorization) === false || $authorization === []) {
			return false;
		}

		$encoded = json_encode($authorization);
		if (is_string($encoded) === false) {
			return true;
		}

		return (
			str_contains($encoded, '"' . self::UNTIL_KEY . '"') === true
			|| str_contains($encoded, '"' . self::SCOPED_TO_KEY . '"') === true
		);
	}//end declaresAnyConstraint()

	/**
	 * The block with every entry that cannot answer here and now removed.
	 *
	 * Applied to the deny side as well as the grant side. A deny that ends is
	 * the same promise in the other direction, and a deny scoped to one register
	 * has no business removing a verb in another.
	 *
	 * @param array|null            $authorization The cascaded block.
	 * @param array<string, mixed>  $area          Keys `register` and `schema`, naming where the question is asked.
	 * @param DateTimeInterface|null $now          The moment, or null for the real one.
	 *
	 * @return array|null The block, with the entries that do not reach here removed.
	 *
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	public function apply(?array $authorization, array $area = [], ?DateTimeInterface $now = null): ?array {
		if ($this->declaresAnyConstraint(authorization: $authorization) === false) {
			return $authorization;
		}

		$now = ($now ?? new DateTimeImmutable());

		$filtered = [];
		foreach ($authorization as $key => $value) {
			if (is_string($key) === false || in_array($key, self::SKIPPED_KEYS, true) === true) {
				$filtered[$key] = $value;
				continue;
			}

			if ($key === DenyResolver::DENY_KEY && is_array($value) === true) {
				$filtered[$key] = $this->apply(authorization: $value, area: $area, now: $now);
				continue;
			}

			if ($key === 'roles' && is_array($value) === true) {
				$filtered[$key] = $this->applyToRoleAssignments(assignments: $value, area: $area, now: $now);
				continue;
			}

			if (is_array($value) === false) {
				$filtered[$key] = $value;
				continue;
			}

			$filtered[$key] = array_values(
				array_filter(
					$value,
					fn (mixed $entry): bool => $this->reaches(entry: $entry, area: $area, now: $now)
				)
			);
		}//end foreach

		return $filtered;
	}//end apply()

	/**
	 * Whether one entry still reaches this area at this moment.
	 *
	 * A bare string entry carries neither constraint and always reaches: the
	 * grammar is additive, so a rule written before this existed keeps meaning
	 * what it meant.
	 *
	 * @param mixed                 $entry The entry as written.
	 * @param array<string, mixed>  $area  Where the question is asked.
	 * @param DateTimeInterface     $now   The moment.
	 *
	 * @return bool True when the entry answers here and now.
	 *
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	public function reaches(mixed $entry, array $area, DateTimeInterface $now): bool {
		if (is_array($entry) === false) {
			return true;
		}

		if ($this->stillRunning(entry: $entry, now: $now) === false) {
			return false;
		}

		return $this->covers(entry: $entry, area: $area);
	}//end reaches()

	/**
	 * Whether an entry's end is still in the future.
	 *
	 * An `until` nobody can read is treated as expired. Fail closed: the other
	 * reading turns a typo into a grant that never ends, and the whole point of
	 * an end is that somebody meant it to arrive.
	 *
	 * @param array             $entry The entry.
	 * @param DateTimeInterface $now   The moment.
	 *
	 * @return bool True when the grant has not ended.
	 */
	private function stillRunning(array $entry, DateTimeInterface $now): bool {
		$until = ($entry[self::UNTIL_KEY] ?? null);
		if ($until === null) {
			return true;
		}

		if (is_string($until) === false || trim($until) === '') {
			return false;
		}

		$ends = strtotime($until);
		if ($ends === false) {
			return false;
		}

		return $ends > $now->getTimestamp();
	}//end stillRunning()

	/**
	 * Whether an entry's area covers the place the question is asked.
	 *
	 * A `scopedTo` naming neither registers nor schemas covers nothing. Fail
	 * closed again, and for the same reason: an empty area is a rule somebody
	 * started writing.
	 *
	 * @param array                $entry The entry.
	 * @param array<string, mixed> $area  Where the question is asked.
	 *
	 * @return bool True when the entry reaches this register and schema.
	 */
	private function covers(array $entry, array $area): bool {
		$scopedTo = ($entry[self::SCOPED_TO_KEY] ?? null);
		if ($scopedTo === null) {
			return true;
		}

		if (is_array($scopedTo) === false || $scopedTo === []) {
			return false;
		}

		$named = false;
		foreach (['registers' => 'register', 'schemas' => 'schema'] as $listKey => $areaKey) {
			$list = ($scopedTo[$listKey] ?? null);
			if (is_array($list) === false || $list === []) {
				continue;
			}

			$named = true;
			if ($this->names(list: $list, value: ($area[$areaKey] ?? null)) === true) {
				continue;
			}

			return false;
		}

		return $named;
	}//end covers()

	/**
	 * Whether a list names one area, by slug or by id.
	 *
	 * Both spellings are accepted because both are what an author has to hand:
	 * a slug when they write the rule, an id when a screen generated it. The
	 * area in hand is therefore given as both, and either one matching is a
	 * match: the alternative is a rule that works until somebody writes the
	 * other spelling, which is the kind of failure nobody connects to the rule.
	 *
	 * @param array<int, mixed> $list  The names the rule gives.
	 * @param mixed             $value The area in hand, one name or several.
	 *
	 * @return bool True when the list names it.
	 */
	private function names(array $list, mixed $value): bool {
		if ($value === null) {
			return false;
		}

		$names = [$value];
		if (is_array($value) === true) {
			$names = $value;
		}

		$mine = [];
		foreach ($names as $name) {
			if (is_scalar($name) === true && (string)$name !== '') {
				$mine[] = (string)$name;
			}
		}

		if ($mine === []) {
			return false;
		}

		foreach ($list as $named) {
			if (is_scalar($named) === false) {
				continue;
			}

			if (in_array((string)$named, $mine, true) === true) {
				return true;
			}
		}

		return false;
	}//end names()

	/**
	 * The role assignments with their expired and out-of-area holders removed.
	 *
	 * A role assignment is a map of role name to holders, so the constraint sits
	 * on the holder rather than on the verb. A role granted to a group until
	 * Friday is the same promise as a verb granted until Friday.
	 *
	 * @param array                $assignments The block's `roles` map.
	 * @param array<string, mixed> $area        Where the question is asked.
	 * @param DateTimeInterface    $now         The moment.
	 *
	 * @return array The assignments.
	 */
	private function applyToRoleAssignments(array $assignments, array $area, DateTimeInterface $now): array {
		$filtered = [];
		foreach ($assignments as $roleName => $holders) {
			if (is_array($holders) === false) {
				$filtered[$roleName] = $holders;
				continue;
			}

			$kept = array_values(
				array_filter(
					$holders,
					fn (mixed $holder): bool => $this->reaches(entry: $holder, area: $area, now: $now)
				)
			);

			// A role nobody holds any more is dropped rather than kept empty:
			// an empty holder list reads as "granted to nobody" further down,
			// and that is what it now means.
			if ($kept !== []) {
				$filtered[$roleName] = $kept;
			}
		}

		return $filtered;
	}//end applyToRoleAssignments()
}//end class
