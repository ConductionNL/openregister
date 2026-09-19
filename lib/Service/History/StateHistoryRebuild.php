<?php

/**
 * Rebuilds the state-history projection from the audit trail it derives from.
 *
 * The projection is written forward from the moment it shipped, so every object
 * that transitioned before that has no line. Rebuilding is not a migration: it
 * is a derivation that can be run again whenever the projection is doubted, and
 * it must be able to stop halfway without leaving the table half-true.
 *
 * 🔴 REBUILDING ONE OBJECT REPLACES ITS LINE, it does not add to it. A second
 * pass that appended would double every interval, and "was ever in bezwaar"
 * would be true twice for a case that was there once.
 *
 * 🔴 THE PROPERTY IS THE ONE THE SCHEMA DECLARES, the same rule the live
 * projection follows. The audit trail records every changed field, so a rebuild
 * reading "whatever changed" would file intervals under keys the schema never
 * declared as states — and a filter could then reach them.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\History
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\History;

use DateTime;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\StateHistory;
use OCA\OpenRegister\Db\StateHistoryMapper;
use Psr\Log\LoggerInterface;

/**
 * Derives state intervals from recorded changes.
 *
 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
 */
class StateHistoryRebuild {

	/**
	 * Constructor.
	 *
	 * @param StateHistoryMapper $intervals The projection.
	 * @param AuditTrailMapper   $audit     The trail it derives from.
	 * @param LoggerInterface    $logger    Diagnostics.
	 */
	public function __construct(
		private readonly StateHistoryMapper $intervals,
		private readonly AuditTrailMapper $audit,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The intervals a change history describes for one declared property.
	 *
	 * Pure, and the whole of the derivation. Each recorded change of the
	 * property closes the interval before it and opens one at the moment of the
	 * change; the last stays open, because the object is still in that state.
	 *
	 * 🔑 THE FIRST CHANGE OPENS TWO INTERVALS, not one: its `old` value is
	 * where the object was until that moment, and dropping it would lose every
	 * state an object held before its first recorded transition — which is the
	 * exact set of states a rebuild exists to recover.
	 *
	 * @param array<int, array{created?: mixed, changed?: mixed}> $changes  The change rows, oldest first.
	 * @param string                                              $property The declared lifecycle property.
	 *
	 * @return array<int, array{value: string, enteredAt: ?DateTime, leftAt: ?DateTime}> The intervals.
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	public function intervalsFor(array $changes, string $property): array {
		$moves = [];
		foreach ($changes as $change) {
			$entry = ((array)($change['changed'] ?? []))[$property] ?? null;
			if (is_array($entry) === false || array_key_exists('new', $entry) === false) {
				continue;
			}

			$stampedAt = $this->moment(raw: ($change['created'] ?? null));
			if ($stampedAt === null) {
				continue;
			}

			$moves[] = [
				'old' => ($entry['old'] ?? null),
				'new' => ($entry['new'] ?? null),
				'at' => $stampedAt,
			];
		}//end foreach

		if ($moves === []) {
			return [];
		}

		$intervals = [];
		$first = $moves[0];
		if (is_scalar($first['old']) === true && (string)$first['old'] !== '') {
			// Where the object was before anything was recorded about it. Its
			// start is unknown, which is a fact, not a zero.
			$intervals[] = ['value' => (string)$first['old'], 'enteredAt' => null, 'leftAt' => $first['at']];
		}

		foreach ($moves as $index => $move) {
			if (is_scalar($move['new']) === false || (string)$move['new'] === '') {
				continue;
			}

			$intervals[] = [
				'value' => (string)$move['new'],
				'enteredAt' => $move['at'],
				'leftAt' => ($moves[($index + 1)]['at'] ?? null),
			];
		}

		return $intervals;
	}//end intervalsFor()

	/**
	 * Rebuild one object's line.
	 *
	 * @param string $objectUuid The object.
	 * @param string $property   The declared lifecycle property.
	 * @param string $register   The register slug.
	 * @param string $schema     The schema slug.
	 *
	 * @return int Intervals written.
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	public function rebuildObject(string $objectUuid, string $property, string $register, string $schema): int {
		try {
			$intervals = $this->intervalsFor(
				changes: $this->audit->findChangesForObject(objectUuid: $objectUuid),
				property: $property
			);

			$this->intervals->deleteForObject(objectUuid: $objectUuid);

			foreach ($intervals as $interval) {
				$row = new StateHistory();
				$row->setObjectUuid($objectUuid);
				$row->setRegister($register);
				$row->setSchema($schema);
				$row->setProperty($property);
				$row->setValue($interval['value']);
				$row->setEnteredAt($interval['enteredAt']);
				$row->setLeftAt($interval['leftAt']);
				$this->intervals->insert($row);
			}

			return count($intervals);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'[StateHistoryRebuild] Could not rebuild {object}: {error}',
				['object' => $objectUuid, 'error' => $e->getMessage(), 'exception' => $e]
			);
			return 0;
		}//end try
	}//end rebuildObject()

	/**
	 * Read a recorded moment.
	 *
	 * @param mixed $raw The recorded value.
	 *
	 * @return DateTime|null The moment.
	 */
	private function moment(mixed $raw): ?DateTime {
		if ($raw instanceof DateTime === true) {
			return $raw;
		}

		if (is_string($raw) === false || trim($raw) === '') {
			return null;
		}

		try {
			return new DateTime($raw);
		} catch (\Exception) {
			return null;
		}
	}//end moment()
}//end class
