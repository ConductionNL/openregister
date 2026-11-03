<?php

/**
 * OpenRegister MDTO Event Mapper
 *
 * Derives MDTO `event` entries for an object from the audit trail.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Edepot
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-derive-mdto-event-entries-from-the-audit-trail
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Selects the audit-trail rows that are archival events and maps them to MDTO.
 *
 * ## The mapping decision, written down here because it is a judgement call
 *
 * `openregister_audit_trails` is not an archival event log. It records every
 * read, list, search and tool invocation as well as the writes, so dumping it
 * into an MDTO export would bury the record's history under traffic and would
 * grow without bound. Three rules narrow it:
 *
 * 1. **An allow-list, not a deny-list.** Only the actions in
 *    {@see self::EVENT_TYPE_BY_ACTION} become events. An action nobody has
 *    mapped is dropped, so a new audit action added elsewhere in the codebase
 *    cannot silently start appearing in an archival export.
 * 2. **Every emitted label comes from MDTO's own EventTypeLijst.** That list is
 *    declared open by the standard, but nothing here invents a term: the five
 *    labels used are Creatie, Wijziging, Overbrenging, Vernietigen and
 *    Bevriezing, each quoted in the spec requirement this class cites.
 * 3. **A hard bound of {@see self::MAX_EVENTS}.** The selection is made in the
 *    database (newest first, limited), so a million-row trail costs one bounded
 *    query. When the trail holds more qualifying rows than the bound, the
 *    record's own creation row is fetched separately and kept, because losing
 *    the Creatie event is the one truncation an archivist would notice.
 *
 * Deliberately NOT mapped, and why:
 *
 * - `read` / `list` / `search` / `get`: consulting a record is not an event in
 *   its history, and these are the highest-volume rows in the table.
 * - `delete`: openregister writes `delete` for a trash operation as well as for
 *   a purge. MDTO's `Vernietigen` means "blijvend ontoegankelijk maken", so
 *   labelling a reversible trash operation that way would be a false statement
 *   to a receiving e-Depot. The purpose-built `archival.destroyed` action is
 *   mapped instead, and it is written only by the destruction execution job.
 * - `archival.transfer_initiated` / `archival.transfer_failed`: an attempt and
 *   a failure are operational facts about this system, not facts about the
 *   record.
 * - `archival.destruction_approved` / `archival.legal_hold_released`: a
 *   decision and the END of a restriction. Neither has a label in the
 *   EventTypeLijst and neither is the object changing.
 * - `referential_integrity.*` and every other namespaced action: unmapped.
 *
 * @psalm-suppress UnusedClass
 */
class MdtoEventMapper {

	/**
	 * The MDTO begrippenlijst that the emitted event labels are taken from.
	 */
	public const EVENT_TYPE_LIST = 'EventTypeLijst';

	/**
	 * The most events emitted for one object.
	 *
	 * A record's archival history is a handful of lifecycle rows plus an
	 * update stream that has no ceiling. This bounds the export; see the class
	 * docblock for why the creation row survives truncation.
	 */
	public const MAX_EVENTS = 25;

	/**
	 * Audit action to MDTO EventTypeLijst label.
	 *
	 * Every key is an action this repository actually writes, and every value
	 * is a label defined in MDTO's EventTypeLijst.
	 *
	 * @var array<string, string>
	 */
	public const EVENT_TYPE_BY_ACTION = [
		'create' => 'Creatie',
		'update' => 'Wijziging',
		'archival.transferred' => 'Overbrenging',
		'archival.destroyed' => 'Vernietigen',
		'archival.legal_hold_placed' => 'Bevriezing',
	];

	/**
	 * Constructor.
	 *
	 * @param AuditTrailMapper $auditTrailMapper Source of the event history.
	 * @param LoggerInterface $logger Logger for truncation and lookup failures.
	 */
	public function __construct(
		private readonly AuditTrailMapper $auditTrailMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Collect the MDTO events for one object, oldest first.
	 *
	 * Returns an empty list when the object has no uuid, when no audit row
	 * qualifies, or when the audit trail cannot be read. The last case is
	 * logged: an export missing its event history is a degraded export, not a
	 * failed one, and refusing to build a SIP because the audit table was
	 * unreachable would stop a transfer for a reason MDTO treats as optional.
	 *
	 * @param ObjectEntity $object The object whose history is wanted.
	 *
	 * @return list<array{type: string, time: string|null, actorName: string|null, actorId: string|null}>
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-derive-mdto-event-entries-from-the-audit-trail
	 */
	public function forObject(ObjectEntity $object): array {
		$uuid = $object->getUuid();
		if (empty($uuid) === true) {
			return [];
		}

		$rows = $this->fetchQualifyingRows(uuid: $uuid);
		if (empty($rows) === true) {
			return [];
		}

		$rows = $this->applyBound(rows: $rows, uuid: $uuid);

		$events = [];
		foreach ($rows as $row) {
			$events[] = $this->toEvent(row: $row);
		}

		return $events;
	}//end forObject()

	/**
	 * Read the audit rows whose action is on the allow-list, oldest first.
	 *
	 * Two reads, both bounded. The first takes the newest MAX_EVENTS
	 * qualifying rows. The second takes the single oldest `create` row, which
	 * the first read misses on any object with a long update stream.
	 *
	 * @param string $uuid The object uuid.
	 *
	 * @return array<int, AuditTrail> Rows keyed by audit-trail id, oldest first.
	 */
	private function fetchQualifyingRows(string $uuid): array {
		$actions = implode(',', array_keys(self::EVENT_TYPE_BY_ACTION));

		$rows = [];
		try {
			$recent = $this->auditTrailMapper->findAll(
				limit: self::MAX_EVENTS,
				offset: null,
				filters: ['object_uuid' => $uuid, 'action' => $actions],
				sort: ['created' => 'DESC'],
			);

			$creation = $this->auditTrailMapper->findAll(
				limit: 1,
				offset: null,
				filters: ['object_uuid' => $uuid, 'action' => 'create'],
				sort: ['created' => 'ASC'],
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[MdtoEventMapper] Could not read the audit trail; MDTO event elements will be absent',
				context: ['objectUuid' => $uuid, 'exception' => $e]
			);
			return [];
		}//end try

		foreach (array_merge($recent, $creation) as $row) {
			$rows[$row->getId()] = $row;
		}

		uasort(
			$rows,
			static function (AuditTrail $left, AuditTrail $right): int {
				return self::sortKey(row: $left) <=> self::sortKey(row: $right);
			}
		);

		return $rows;
	}//end fetchQualifyingRows()

	/**
	 * Trim the row set to MAX_EVENTS, keeping the creation row.
	 *
	 * @param array<int, AuditTrail> $rows Qualifying rows, oldest first.
	 * @param string $uuid The object uuid, for the truncation log line.
	 *
	 * @return list<AuditTrail> At most MAX_EVENTS rows, oldest first.
	 */
	private function applyBound(array $rows, string $uuid): array {
		$ordered = array_values($rows);
		if (count($ordered) <= self::MAX_EVENTS) {
			return $ordered;
		}

		$creation = null;
		foreach ($ordered as $row) {
			if ($row->getAction() === 'create') {
				$creation = $row;
				break;
			}
		}

		$tailSize = self::MAX_EVENTS;
		if ($creation !== null) {
			$tailSize = (self::MAX_EVENTS - 1);
		}

		$kept = array_slice($ordered, -$tailSize);
		if ($creation !== null) {
			array_unshift($kept, $creation);
		}

		$this->logger->info(
			message: '[MdtoEventMapper] Audit trail exceeded the MDTO event bound; older events were omitted',
			context: [
				'objectUuid' => $uuid,
				'qualifying' => count($ordered),
				'emitted' => count($kept),
				'bound' => self::MAX_EVENTS,
			]
		);

		return $kept;
	}//end applyBound()

	/**
	 * Map one audit row to the MDTO event shape.
	 *
	 * `eventResultaat` is deliberately never populated. MDTO defines it as the
	 * outcome of the event as far as durable accessibility is concerned, and an
	 * openregister audit row carries no such outcome: its hash and previousHash
	 * attest to the integrity of the AUDIT ROW, not to a result of the event.
	 * Writing the chain hash there would look like provenance and would not be.
	 *
	 * @param AuditTrail $row The audit row.
	 *
	 * @return array{type: string, time: string|null, actorName: string|null, actorId: string|null}
	 */
	private function toEvent(AuditTrail $row): array {
		$time = null;
		$created = $row->getCreated();
		if ($created !== null) {
			$time = $created->format('c');
		}

		$actorName = $row->getUserName();
		if (empty($actorName) === true) {
			$actorName = null;
		}

		$actorId = $row->getUser();
		if (empty($actorId) === true) {
			$actorId = null;
		}

		return [
			'type' => self::EVENT_TYPE_BY_ACTION[(string)$row->getAction()],
			'time' => $time,
			'actorName' => $actorName,
			'actorId' => $actorId,
		];
	}//end toEvent()

	/**
	 * Sort key for one audit row: its creation timestamp, then its id.
	 *
	 * The id breaks ties because a batch insert can stamp several rows with the
	 * same second, and an unstable order would make the export non-reproducible.
	 *
	 * @param AuditTrail $row The audit row.
	 *
	 * @return string The comparable key.
	 */
	private static function sortKey(AuditTrail $row): string {
		$created = $row->getCreated();
		$stamp = '0000-00-00 00:00:00';
		if ($created !== null) {
			$stamp = $created->format('Y-m-d H:i:s');
		}

		return $stamp . '|' . str_pad((string)$row->getId(), 20, '0', STR_PAD_LEFT);
	}//end sortKey()
}//end class
