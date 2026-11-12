<?php

/**
 * OpenRegister LegalHoldLedger
 *
 * One legal hold per matter on an object (openregister#4172). Two matters can
 * cover the same record, a lawsuit and an audit, or two Woo appeals, and with
 * one slot the second placement overwrote the first and releasing either
 * lifted both.
 *
 * `retention.legalHold.holds` is the list of active holds, each with an `id`,
 * an `ownerKey` (for example `filinq:legalHoldCase:<uuid>`), a `reason`,
 * `placedBy` and `placedDate`. `retention.legalHold.active` is derived: true
 * while any hold is in the list. The top-level `reason`, `placedBy` and
 * `placedDate` mirror the most recent active hold. Every reader that asks
 * `legalHold.active` (destruction, retention clocks, e-depot, audit retention)
 * therefore keeps working unchanged. A released hold moves to `history`.
 *
 * A stored single-slot hold with no `holds` list is read as a list of one,
 * owned by {@see self::LEGACY_OWNER}, so existing data stays valid.
 *
 * Pure: it takes and returns the retention array and touches nothing else, so
 * LegalHoldService and RetentionService share one implementation.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use Symfony\Component\Uid\Uuid;

/**
 * Places and releases legal holds per owner key on a retention array.
 */
class LegalHoldLedger {

	/**
	 * The owner of a hold placed without an owner key, and of a stored single-slot hold.
	 *
	 * @var string
	 */
	public const LEGACY_OWNER = 'openregister:manual';

	/**
	 * The retention array with one matter's hold added, or restated.
	 *
	 * @param array      $retention The object's retention array.
	 * @param HoldAction $action    The matter placing it, why, and who places it when.
	 *
	 * @return array The retention array with the hold in place.
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function placeAction(array $retention, HoldAction $action): array {
		[$reason, $stamp] = [$action->reason, $action->stamp];
		$ownerKey = $this->owner(ownerKey: $action->ownerKey);
		$holds = $this->holds(legalHold: ($retention['legalHold'] ?? null));

		$index = $this->indexOf(holds: $holds, ownerKey: $ownerKey);
		if ($index !== null) {
			// The same matter restating its hold keeps its id and first placement.
			$holds[$index]['reason'] = $reason;
			return $this->write(retention: $retention, holds: $holds);
		}

		$holds[] = [
			'id' => Uuid::v4()->toRfc4122(), 'ownerKey' => $ownerKey, 'reason' => $reason, 'placedBy' => $stamp->userId, 'placedDate' => $stamp->now,
		];

		return $this->write(retention: $retention, holds: $holds);
	}//end placeAction()

	/**
	 * The retention array with one matter's hold, or every hold, moved to history.
	 *
	 * @param array      $retention The object's retention array.
	 * @param HoldAction $action    The matter releasing its hold (null releases every hold), why, and who releases it when.
	 *
	 * @return array The retention array, the released holds moved to history.
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function releaseAction(array $retention, HoldAction $action): array {
		[$ownerKey, $stamp] = [$action->ownerKey, $action->stamp];
		$history = $this->history(retention: $retention);

		$kept = [];
		foreach ($this->holds(legalHold: ($retention['legalHold'] ?? null)) as $hold) {
			if ($ownerKey !== null && $hold['ownerKey'] !== $ownerKey) {
				$kept[] = $hold;
				continue;
			}

			$history[] = array_merge($hold, ['releasedBy' => $stamp->userId, 'releasedDate' => $stamp->now, 'releaseReason' => $action->reason]);
		}

		$retention['legalHold'] = ['history' => $history];

		return $this->write(retention: $retention, holds: $kept);
	}//end releaseAction()

	/**
	 * The active holds on a retention array, a stored single slot read as a list of one
	 *
	 * @param array $retention The object's retention array.
	 *
	 * @return array<int, array<string, mixed>> The active holds.
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function activeHolds(array $retention): array {
		return $this->holds(legalHold: ($retention['legalHold'] ?? null));
	}//end activeHolds()

	/**
	 * Normalise the stored hold into a list
	 *
	 * @param mixed $legalHold The stored `retention.legalHold`.
	 *
	 * @return array<int, array<string, mixed>> The active holds.
	 */
	private function holds(mixed $legalHold): array {
		if (is_array($legalHold) === false) {
			return [];
		}

		if (is_array($legalHold['holds'] ?? null) === true) {
			return array_values(array_filter($legalHold['holds'], 'is_array'));
		}

		if (($legalHold['active'] ?? false) !== true) {
			return [];
		}

		return [$this->legacyHold(legalHold: $legalHold)];
	}//end holds()

	/**
	 * A stored single-slot hold, read as one hold of the manual owner
	 *
	 * @param array<string, mixed> $legalHold The stored `retention.legalHold`.
	 *
	 * @return array<string, mixed> The hold.
	 */
	private function legacyHold(array $legalHold): array {
		return [
			'id' => (string) ($legalHold['id'] ?? 'legacy'),
			'ownerKey' => self::LEGACY_OWNER,
			'reason' => ($legalHold['reason'] ?? null),
			'placedBy' => ($legalHold['placedBy'] ?? null),
			'placedDate' => ($legalHold['placedDate'] ?? null),
		];
	}//end legacyHold()

	/**
	 * The released holds recorded so far
	 *
	 * @param array $retention The retention array.
	 *
	 * @return array The history, empty when none or unreadable.
	 */
	private function history(array $retention): array {
		$history = ($retention['legalHold']['history'] ?? []);
		if (is_array($history) === false) {
			return [];
		}

		return $history;
	}//end history()

	/**
	 * Write the list and the derived single-slot fields back
	 *
	 * @param array $retention The retention array.
	 * @param array $holds     The active holds.
	 *
	 * @return array The retention array.
	 */
	private function write(array $retention, array $holds): array {
		$latest = null;
		if ($holds !== []) {
			$latest = $holds[count($holds) - 1];
		}

		$retention['legalHold'] = [
			'active' => ($holds !== []),
			'reason' => ($latest['reason'] ?? null),
			'placedBy' => ($latest['placedBy'] ?? null),
			'placedDate' => ($latest['placedDate'] ?? null),
			'holds' => array_values($holds),
			'history' => $this->history(retention: $retention),
		];

		return $retention;
	}//end write()

	/**
	 * The index of an owner's hold
	 *
	 * @param array  $holds    The holds.
	 * @param string $ownerKey The owner key.
	 *
	 * @return int|null The index, or null when the owner holds nothing.
	 */
	private function indexOf(array $holds, string $ownerKey): ?int {
		foreach ($holds as $index => $hold) {
			if (($hold['ownerKey'] ?? null) === $ownerKey) {
				return (int) $index;
			}
		}

		return null;
	}//end indexOf()

	/**
	 * The owner key to use
	 *
	 * @param string|null $ownerKey The given owner key.
	 *
	 * @return string The owner key, the manual owner when none was given.
	 */
	private function owner(?string $ownerKey): string {
		$ownerKey = trim((string) $ownerKey);
		if ($ownerKey === '') {
			return self::LEGACY_OWNER;
		}

		return $ownerKey;
	}//end owner()
}//end class
