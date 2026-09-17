<?php

/**
 * ExternalGrantGuard — a grant to a principal outside the organisation carries
 * an end date, or it does not exist.
 *
 * D-6 IS ONE SENTENCE: optional expiry is expiry nobody sets. The grammar
 * already has an ending, {@see GrantConstraints::UNTIL_KEY}, and an entry that
 * declares none simply never ends. That is right for a colleague and wrong for
 * the adviser who came in for six weeks, so the ending stops being optional for
 * the entries that say they are external.
 *
 * WHY DECLARED AND NOT INFERRED. The instance can say who is inside an
 * organisation, but inferring externality from that and then refusing writes
 * would retroactively invalidate every bare-string grant already written across
 * the fleet, which is a migration disguised as a guard. An entry declares
 * `external: true`, the guard refuses it without an end, and
 * {@see lapsingWithin()} names the ones about to run out so the holder is
 * warned before the work stops mid-sentence rather than after.
 *
 * WHAT THE LAPSE DOES NOT TOUCH. Nothing here, and nothing in GrantConstraints,
 * writes to an object. When the end arrives the entry stops answering and the
 * objects the collaborator wrote on are exactly as they were, still carrying
 * their author. The work survives the access because the two were never the
 * same record.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Rbac
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Refuses an external grant with no end, and names the ones about to lapse.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/authorization-rbac/spec.md
 */
class ExternalGrantGuard {
	/**
	 * The entry key declaring that a principal is outside the organisation.
	 *
	 * @var string
	 */
	public const EXTERNAL_KEY = 'external';

	/**
	 * The rule a refusal names.
	 *
	 * @var string
	 */
	public const RULE = 'external-grant-needs-an-end';

	/**
	 * How many days before an end counts as "about to lapse" by default.
	 *
	 * @var int
	 */
	public const DEFAULT_WARNING_DAYS = 14;

	/**
	 * Block keys that are settings rather than lists of entries.
	 *
	 * The same exclusions GrantConstraints makes, for the same reason: a
	 * setting is not a rule and has no principal to be external.
	 *
	 * @var array<int, string>
	 */
	private const SKIPPED_KEYS = ['public', 'inheritFromPublic'];

	/**
	 * The external entries in a block that carry no usable end date.
	 *
	 * An empty list means the block may be written. A non-empty one names every
	 * offending entry, so a caller fixes all of them in one pass rather than
	 * discovering them one refusal at a time.
	 *
	 * @param array|null $authorization The block as written.
	 *
	 * @return array<int, array<string, mixed>> The entries that must not be written.
	 *
	 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/authorization-rbac/spec.md
	 */
	public function endlessExternalGrants(?array $authorization): array {
		$offending = [];

		foreach ($this->entries(authorization: $authorization) as $found) {
			if ($this->isExternal(entry: $found['entry']) === false) {
				continue;
			}

			if ($this->hasUsableEnd(entry: $found['entry']) === true) {
				continue;
			}

			$offending[] = [
				'action' => $found['action'],
				'principal' => $this->principalOf(entry: $found['entry']),
				'rule' => self::RULE,
				'message' => 'A grant to a principal outside the organisation carries an end date. '
					. 'Add `' . GrantConstraints::UNTIL_KEY . '` to this entry, or drop `'
					. self::EXTERNAL_KEY . '` if the principal is not external after all.',
			];
		}

		return $offending;
	}//end endlessExternalGrants()

	/**
	 * The refusal body for a block that may not be written, or null.
	 *
	 * @param array|null $authorization The block as written.
	 *
	 * @return array<string, mixed>|null The refusal, or null when the block is fine.
	 *
	 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/authorization-rbac/spec.md
	 */
	public function refusalFor(?array $authorization): ?array {
		$offending = $this->endlessExternalGrants(authorization: $authorization);
		if ($offending === []) {
			return null;
		}

		return [
			'error' => 'EXTERNAL_GRANT_REFUSED',
			'rule' => self::RULE,
			'message' => 'One or more grants to principals outside the organisation carry no end date, '
				. 'so nothing was saved.',
			'grants' => $offending,
		];
	}//end refusalFor()

	/**
	 * The external grants whose end arrives within the warning window.
	 *
	 * The holder is warned before it lapses so the work is not lost
	 * mid-sentence, which is only possible if somebody can ask which grants are
	 * about to run out.
	 *
	 * @param array|null             $authorization The block as written.
	 * @param int                    $days          How far ahead to look.
	 * @param DateTimeInterface|null $now           The moment, or null for the real one.
	 *
	 * @return array<int, array<string, mixed>> The grants about to lapse, soonest first.
	 *
	 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/authorization-rbac/spec.md
	 */
	public function lapsingWithin(
		?array $authorization,
		int $days = self::DEFAULT_WARNING_DAYS,
		?DateTimeInterface $now = null,
	): array {
		$now = ($now ?? new DateTimeImmutable());
		$horizon = ($now->getTimestamp() + ($days * 86400));

		$lapsing = [];
		foreach ($this->entries(authorization: $authorization) as $found) {
			if ($this->isExternal(entry: $found['entry']) === false) {
				continue;
			}

			$ends = $this->endOf(entry: $found['entry']);
			// An end already past is not a warning, it is a lapse. Reporting it
			// as "about to lapse" would send somebody to renew access that has
			// already stopped answering.
			if ($ends === null || $ends <= $now->getTimestamp() || $ends > $horizon) {
				continue;
			}

			$lapsing[] = [
				'action' => $found['action'],
				'principal' => $this->principalOf(entry: $found['entry']),
				'endsAt' => date(DATE_ATOM, $ends),
				'daysLeft' => (int)ceil((($ends - $now->getTimestamp()) / 86400)),
			];
		}

		usort(
			$lapsing,
			static fn (array $left, array $right): int => ($left['daysLeft'] <=> $right['daysLeft'])
		);

		return $lapsing;
	}//end lapsingWithin()

	/**
	 * Every entry in a block, with the action it was written under.
	 *
	 * @param array|null $authorization The block.
	 *
	 * @return array<int, array{action: string, entry: mixed}> The entries.
	 */
	private function entries(?array $authorization): array {
		if (is_array($authorization) === false) {
			return [];
		}

		$found = [];
		foreach ($authorization as $action => $value) {
			if (is_string($action) === false
				|| in_array($action, self::SKIPPED_KEYS, true) === true
				|| is_array($value) === false
			) {
				continue;
			}

			foreach ($value as $entry) {
				$found[] = [
					'action' => $action,
					'entry' => $entry,
				];
			}
		}

		return $found;
	}//end entries()

	/**
	 * Whether an entry declares its principal is outside the organisation.
	 *
	 * @param mixed $entry The entry as written.
	 *
	 * @return bool True when it declares itself external.
	 */
	private function isExternal(mixed $entry): bool {
		if (is_array($entry) === false) {
			return false;
		}

		return (($entry[self::EXTERNAL_KEY] ?? false) === true);
	}//end isExternal()

	/**
	 * Whether an entry carries an end date anybody can read.
	 *
	 * Fail closed on an unreadable one, exactly as GrantConstraints does: an
	 * `until` nobody can parse is not an end, and treating it as one would turn
	 * a typo into the permanent access this rule exists to prevent.
	 *
	 * @param array<string, mixed> $entry The entry.
	 *
	 * @return bool True when the end is present and readable.
	 */
	private function hasUsableEnd(array $entry): bool {
		return ($this->endOf(entry: $entry) !== null);
	}//end hasUsableEnd()

	/**
	 * An entry's end as a timestamp, or null when it has none it can read.
	 *
	 * @param mixed $entry The entry.
	 *
	 * @return int|null The timestamp.
	 */
	private function endOf(mixed $entry): ?int {
		if (is_array($entry) === false) {
			return null;
		}

		$until = ($entry[GrantConstraints::UNTIL_KEY] ?? null);
		if (is_string($until) === false || trim($until) === '') {
			return null;
		}

		$ends = strtotime($until);
		if ($ends === false) {
			return null;
		}

		return $ends;
	}//end endOf()

	/**
	 * The principal an entry names, for the refusal to be actionable.
	 *
	 * @param mixed $entry The entry.
	 *
	 * @return string The principal, or an empty string when it names none.
	 */
	private function principalOf(mixed $entry): string {
		if (is_string($entry) === true) {
			return $entry;
		}

		if (is_array($entry) === false) {
			return '';
		}

		foreach (['name', 'principal', 'id', 'user', 'group'] as $key) {
			$value = ($entry[$key] ?? null);
			if (is_string($value) === true && trim($value) !== '') {
				return $value;
			}
		}

		return '';
	}//end principalOf()
}//end class
