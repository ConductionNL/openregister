<?php

/**
 * OpenRegister LinkedRetentionConflictFinder
 *
 * The linked records that make destroying a destruction list's entries a conflict.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use Throwable;

/**
 * Compares each entry of a destruction list with the records linked to it.
 *
 * Outgoing links are the entry's own `relations`, read in one batch. Incoming
 * links come from one batched relation query per referencing schema for the
 * whole list, never one per entry. At most 50 links each way are examined per
 * entry; an entry with more is marked truncated. A linked record on the same
 * list is not a conflict: it goes with the entry. Lookups run without RBAC and
 * multitenancy, as the retention pass does.
 *
 * @spec openspec/changes/retention-linked-destruction-conflict/specs/archival-destruction-workflow/spec.md#requirement-a-destruction-list-names-the-linked-records-its-entries-clash-with
 */
class LinkedRetentionConflictFinder {

	/**
	 * Links examined per entry and direction.
	 *
	 * @var int
	 */
	public const MAX_LINKS = 50;

	public const KEPT_PERMANENTLY = 'linked-kept-permanently';

	public const KEPT_LONGER = 'linked-kept-longer';

	public const DATE_UNKNOWN = 'linked-date-unknown';

	public const ON_HOLD = 'linked-on-hold';

	public const DERIVES_DATE = 'linked-derives-date-from-this';

	/**
	 * Archive configurations by schema id, read once per call.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $archives = [];

	/**
	 * Constructor.
	 *
	 * @param MagicMapperInterface        $objects    Reads linked records, outgoing in one batch, incoming per schema.
	 * @param SchemaMapperInterface       $schemas    Reads a linked record's archive configuration.
	 * @param LegalHoldService            $holds      Says whether a retention block carries an active hold.
	 * @param ArchiveActionDateCalculator $dates      Says which derivation methods follow a relation.
	 */
	public function __construct(
		private readonly MagicMapperInterface $objects,
		private readonly SchemaMapperInterface $schemas,
		private readonly LegalHoldService $holds,
		private readonly ArchiveActionDateCalculator $dates,
	) {
	}//end __construct()

	/**
	 * The conflicts of every entry on a list.
	 *
	 * @param array<int, ObjectEntity>      $entries     The records on the list.
	 * @param array<int, ReferencingSchema> $referencing The schemas whose records can point at them.
	 *
	 * @return array<string, array{conflicts: array<int, array<string, mixed>>, truncated: bool}> By entry uuid.
	 *
	 * @spec openspec/changes/retention-linked-destruction-conflict/specs/archival-destruction-workflow/spec.md#requirement-a-destruction-list-names-the-linked-records-its-entries-clash-with
	 */
	public function forList(array $entries, array $referencing): array {
		$this->archives = [];
		$onList = [];
		foreach ($entries as $entry) {
			$onList[(string) $entry->getUuid()] = true;
		}

		$incoming = $this->incomingLinks(uuids: array_keys($onList), referencing: $referencing);

		$result = [];
		foreach ($entries as $entry) {
			$uuid = (string) $entry->getUuid();
			$incomingOff = $this->offList(links: ($incoming[$uuid] ?? []), onList: $onList);
			$out = array_values(array_filter($this->relationUuids(object: $entry), static fn (string $target): bool => isset($onList[$target]) === false));

			$conflicts = array_merge(
				$this->conflictsOf(entry: $entry, links: array_slice($incomingOff, 0, self::MAX_LINKS), direction: 'incoming'),
				$this->conflictsOf(entry: $entry, links: $this->loaded(uuids: array_slice($out, 0, self::MAX_LINKS)), direction: 'outgoing')
			);

			$result[$uuid] = [
				'conflicts' => $conflicts,
				'truncated' => (count($incomingOff) > self::MAX_LINKS || count($out) > self::MAX_LINKS),
			];
		}

		return $result;
	}//end forList()

	/**
	 * The records pointing at any entry, grouped by the entry they point at; one query per referencing schema.
	 *
	 * @param array<int, string>            $uuids       The entries' uuids.
	 * @param array<int, ReferencingSchema> $referencing The schemas whose records can point at them.
	 *
	 * @return array<string, array<int, ObjectEntity>>
	 */
	private function incomingLinks(array $uuids, array $referencing): array {
		$byEntry = [];
		if ($uuids === []) {
			return $byEntry;
		}

		foreach ($referencing as $source) {
			$found = $this->objects->findByRelationBatchInSchema(
				$uuids,
				(int) $source->schema->getId(),
				$source->registerId,
				$source->fieldName,
				$source->additionalFieldNames
			);
			foreach ($found as $linked) {
				$this->archives[(string) $source->schema->getId()] = $source->schema->getArchive();
				foreach (array_intersect($uuids, $this->relationUuids(object: $linked)) as $target) {
					$byEntry[$target][(string) $linked->getUuid()] = $linked;
				}
			}
		}

		return array_map(static fn (array $links): array => array_values($links), $byEntry);
	}//end incomingLinks()

	/**
	 * The records with the given uuids, in one batch.
	 *
	 * @param array<int, string> $uuids The uuids.
	 *
	 * @return array<int, ObjectEntity>
	 */
	private function loaded(array $uuids): array {
		if ($uuids === []) {
			return [];
		}

		return $this->objects->findMultiple($uuids);
	}//end loaded()

	/**
	 * The links that are not themselves on the list.
	 *
	 * @param array<int, ObjectEntity> $links  The links.
	 * @param array<string, bool>      $onList The uuids on the list.
	 *
	 * @return array<int, ObjectEntity>
	 */
	private function offList(array $links, array $onList): array {
		return array_values(
			array_filter(
				$links,
				static fn (ObjectEntity $link): bool => isset($onList[(string) $link->getUuid()]) === false
			)
		);
	}//end offList()

	/**
	 * The conflicts between an entry and its links in one direction.
	 *
	 * @param ObjectEntity             $entry     The entry.
	 * @param array<int, ObjectEntity> $links     The links, at most the cap.
	 * @param string                   $direction `incoming` or `outgoing`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function conflictsOf(ObjectEntity $entry, array $links, string $direction): array {
		$conflicts = [];
		$entryDate = (($entry->getRetention() ?? [])['archiefactiedatum'] ?? null);
		foreach ($links as $linked) {
			$kind = $this->kindOf(entry: $entry, entryDate: $entryDate, linked: $linked);
			if ($kind === null) {
				continue;
			}

			$retention = ($linked->getRetention() ?? []);
			$conflicts[] = [
				'kind' => $kind,
				'direction' => $direction,
				'uuid' => (string) $linked->getUuid(),
				'title' => $linked->getName(),
				'schema' => $linked->getSchema(),
				'archiefnominatie' => ($retention['archiefnominatie'] ?? null),
				'archiefactiedatum' => ($retention['archiefactiedatum'] ?? null),
			];
		}

		return $conflicts;
	}//end conflictsOf()

	/**
	 * Which conflict a linked record is, if any; the most binding one wins.
	 *
	 * @param ObjectEntity $entry     The entry to be destroyed.
	 * @param mixed        $entryDate The entry's archive date.
	 * @param ObjectEntity $linked    The linked record.
	 *
	 * @return string|null The kind, or null when the link is no conflict.
	 */
	private function kindOf(ObjectEntity $entry, mixed $entryDate, ObjectEntity $linked): ?string {
		$retention = ($linked->getRetention() ?? []);
		if ($this->holds->hasActiveHoldFromRetention($retention) === true) {
			return self::ON_HOLD;
		}

		if (($retention['archiefnominatie'] ?? null) === 'bewaren') {
			return self::KEPT_PERMANENTLY;
		}

		if ($this->derivesDateFrom(linked: $linked, entry: $entry) === true) {
			return self::DERIVES_DATE;
		}

		$linkedDate = ($retention['archiefactiedatum'] ?? null);
		if (is_string($linkedDate) === false || $linkedDate === '') {
			return self::DATE_UNKNOWN;
		}

		if (is_string($entryDate) === true && $entryDate !== '' && strtotime($linkedDate) > strtotime($entryDate)) {
			return self::KEPT_LONGER;
		}

		return null;
	}//end kindOf()

	/**
	 * Whether a linked record's schema derives its date through a relation that points at the entry.
	 *
	 * @param ObjectEntity $linked The linked record.
	 * @param ObjectEntity $entry  The entry.
	 *
	 * @return bool
	 */
	private function derivesDateFrom(ObjectEntity $linked, ObjectEntity $entry): bool {
		$archive = $this->archiveOf(schemaId: (string) $linked->getSchema());
		$method = ($archive['afleidingswijze'] ?? null);
		if (is_string($method) === false || $this->dates->isRelationMethod(afleidingswijze: $method) === false) {
			return false;
		}

		$relation = ($archive['sourceRelation'] ?? null);
		if (is_string($relation) === false || $relation === '') {
			return false;
		}

		$value = (($linked->getObject() ?? [])[$relation] ?? null);
		$pointed = array_map('strval', (array) $value);

		return in_array((string) $entry->getUuid(), $pointed, true);
	}//end derivesDateFrom()

	/**
	 * A schema's archive configuration, read once per call; empty when it cannot be read.
	 *
	 * @param string $schemaId The schema id.
	 *
	 * @return array<string, mixed>
	 */
	private function archiveOf(string $schemaId): array {
		if ($schemaId === '') {
			return [];
		}

		if (array_key_exists($schemaId, $this->archives) === false) {
			try {
				$this->archives[$schemaId] = $this->schemas->find($schemaId, [], false, false)->getArchive();
			} catch (Throwable $e) {
				$this->archives[$schemaId] = [];
			}
		}

		return $this->archives[$schemaId];
	}//end archiveOf()

	/**
	 * The uuids an object's relations column holds.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return array<int, string>
	 */
	private function relationUuids(ObjectEntity $object): array {
		$uuids = [];
		foreach (($object->getRelations() ?? []) as $value) {
			foreach ((array) $value as $uuid) {
				if (is_string($uuid) === true && $uuid !== '') {
					$uuids[$uuid] = true;
				}
			}
		}

		return array_keys($uuids);
	}//end relationUuids()
}//end class
