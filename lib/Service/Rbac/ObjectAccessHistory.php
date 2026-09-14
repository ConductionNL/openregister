<?php

/**
 * How one object's access set changed, and what it was on a day last year.
 *
 * The other half of the auditor's question. {@see ObjectPermissionsResolver}
 * answers who holds what NOW; this answers who held what THEN, and which rule
 * took it away afterwards. They are separate classes because they are separate
 * questions: one reads the rules as they stand, the other reads a trail.
 *
 * READ FROM THE AUDIT TRAIL, NOT FROM A SECOND TABLE. An object's
 * `authorization` column is versioned by the same trail that versions its data,
 * so the set at a past moment is reconstructible from what is already recorded.
 * A dedicated table would have had to be written from the day it shipped to
 * answer a question about last year, which is exactly when it cannot.
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

use OCA\OpenRegister\Db\AuditTrail;

/**
 * Reconstructs an object's access set from its audit trail.
 *
 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
 */
class ObjectAccessHistory {

	/**
	 * The audit-trail change key this report reads.
	 *
	 * @var string
	 */
	private const AUTHORIZATION_KEY = 'authorization';

	/**
	 * Constructor.
	 *
	 * @param ObjectPermissionsResolver $holders Reads an access set out of a block.
	 */
	public function __construct(
		private readonly ObjectPermissionsResolver $holders = new ObjectPermissionsResolver(),
	) {
	}//end __construct()

	/**
	 * The history of one object's access set.
	 *
	 * Every trail entry whose change set touches the authorization column is one
	 * moment the access set moved, and the entry names who moved it. An entry
	 * that changed only the object's data is not one, and is dropped rather than
	 * reported as a change nobody made.
	 *
	 * @param array<int, AuditTrail> $entries The object's audit trail, newest first.
	 * @param string|null            $moment  An ISO-8601 moment to report the set AS OF, or null for every change.
	 *
	 * @return array{changes: array<int, array<string, mixed>>, asOf: array<string, mixed>|null}
	 *         The changes, and the set as it stood at the named moment.
	 *
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	public function forEntries(array $entries, ?string $moment = null): array {
		$asked = $this->timestampOf(value: $moment);

		$changes = [];
		$asOf = null;

		foreach ($entries as $entry) {
			$change = $this->authorizationChangeIn(entry: $entry);
			if ($change === null) {
				continue;
			}

			$changes[] = $change;

			if ($asOf === null) {
				$asOf = $this->setAsOf(asked: $asked, change: $change, changes: $changes);
			}
		}

		return ['changes' => $changes, 'asOf' => $asOf];
	}//end of()

	/**
	 * The set this change produced, when this is the change that was standing.
	 *
	 * The entries arrive newest first, so the first change at or before the
	 * moment asked about is the one that produced the set standing then:
	 * everything after it had not happened yet.
	 *
	 * @param integer|null                     $asked   The moment asked about.
	 * @param array<string, mixed>             $change  The change under consideration.
	 * @param array<int, array<string, mixed>> $changes Every change collected so far, newest first.
	 *
	 * @return array<string, mixed>|null The set, or null when this change is not the one.
	 */
	private function setAsOf(?int $asked, array $change, array $changes): ?array {
		if ($asked === null || $change['at'] === null) {
			return null;
		}

		$changedAt = strtotime((string)$change['at']);
		if ($changedAt === false || $changedAt > $asked) {
			return null;
		}

		return [
			'at' => $change['at'],
			'holders' => $this->holders->holders(blocks: ['object' => $change['to']])['holders'],
			'setBy' => $change['by'],
			'changedAfterwardsBy' => $this->laterChangeIn(changes: $changes),
		];
	}//end setAsOf()

	/**
	 * The change that came after the one answering for a past moment.
	 *
	 * "The grant was held then, and this is the rule that removed it afterwards"
	 * is the whole of the auditor's question, and the second half is the half a
	 * point-in-time read usually leaves out.
	 *
	 * @param array<int, array<string, mixed>> $changes The changes collected so far, newest first.
	 *
	 * @return array<string, mixed>|null The later change, or null when nothing changed since.
	 */
	private function laterChangeIn(array $changes): ?array {
		if (count($changes) < 2) {
			return null;
		}

		$later = $changes[(count($changes) - 2)];

		return ['at' => $later['at'], 'by' => $later['by'], 'to' => $later['to']];
	}//end laterChangeIn()

	/**
	 * One trail entry's authorization change, or null when it carried none.
	 *
	 * @param AuditTrail $entry The trail entry.
	 *
	 * @return array<string, mixed>|null The change.
	 */
	private function authorizationChangeIn(AuditTrail $entry): ?array {
		$changed = $entry->getChanged();
		if (is_array($changed) === false || isset($changed[self::AUTHORIZATION_KEY]) === false) {
			return null;
		}

		$change = $changed[self::AUTHORIZATION_KEY];
		if (is_array($change) === false) {
			return null;
		}

		return [
			'at' => $entry->getCreated()?->format('c'),
			'by' => $entry->getUser(),
			'byName' => $entry->getUserName(),
			'action' => $entry->getAction(),
			'from' => $this->blockOf(value: ($change['old'] ?? null)),
			'to' => $this->blockOf(value: ($change['new'] ?? null)),
		];
	}//end authorizationChangeIn()

	/**
	 * One side of a change, as a block.
	 *
	 * @param mixed $value The stored value, which may be JSON text.
	 *
	 * @return array|null The block.
	 */
	private function blockOf(mixed $value): ?array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_string($value) === true && $value !== '') {
			$decoded = json_decode($value, true);
			if (is_array($decoded) === true) {
				return $decoded;
			}
		}

		return null;
	}//end blockOf()

	/**
	 * The moment a request asked about, as a timestamp.
	 *
	 * @param string|null $value The request's value.
	 *
	 * @return integer|null The timestamp, or null when nothing usable was asked.
	 */
	private function timestampOf(?string $value): ?int {
		if ($value === null || trim($value) === '') {
			return null;
		}

		$moment = strtotime($value);
		if ($moment === false) {
			return null;
		}

		return $moment;
	}//end timestampOf()
}//end class
