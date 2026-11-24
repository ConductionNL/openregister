<?php

/**
 * DestroyedTombstones — destroyed objects on the deleted list (incremental-sync-for-api-consumers D-3).
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapper\DeletedFilter;
use OCA\OpenRegister\Kernel\Contracts\Core\AuditRecordInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\DestructionQuery;
use OCA\OpenRegister\Service\Deletion\DeletedObjectAuthorizerInterface;
use OCP\AppFramework\Db\Entity;

/**
 * One tombstone per destruction entry in the audit trail, after the bin rows.
 *
 * Once the bin window has passed the object row is gone, but its destruction
 * is on record. A tombstone carries the object id, register, schema and the
 * destruction moment, and nothing of the object's data: repeating the data
 * here would undo the destruction.
 *
 * A caller sees a tombstone only where it may read the destruction record:
 * the entries are narrowed to the caller's own in the query (so `total` is
 * exact) and then passed through the same check the per-object destruction
 * record endpoint uses.
 *
 * Tombstones follow the bin rows in one list: on a page the bin rows come
 * first, and the tombstones fill the rest of the page once the bin is used up.
 *
 * @spec openspec/changes/incremental-sync-for-api-consumers/specs/incremental-sync/spec.md#requirement-destroyed-objects-are-reported-as-tombstones-req-sync-003
 */
final class DestroyedTombstones {

	/**
	 * Constructor.
	 *
	 * @param AuditTrailMapperInterface        $auditTrailMapper Reads the destruction entries.
	 * @param DeletedObjectAuthorizerInterface $authorizer       Decides which destruction records the caller may read.
	 *
	 * @spec openspec/changes/incremental-sync-for-api-consumers/specs/incremental-sync/spec.md#requirement-destroyed-objects-are-reported-as-tombstones-req-sync-003
	 */
	public function __construct(
		private readonly AuditTrailMapperInterface $auditTrailMapper,
		private readonly DeletedObjectAuthorizerInterface $authorizer,
	) {
	}//end __construct()

	/**
	 * The destruction query of a deleted-list filter for a caller.
	 *
	 * @param DeletedFilter $filter The deleted list's filters; `deletedSince` must be set.
	 * @param string|null   $actor  The caller's user id when read-scoped; null for an administrator.
	 *
	 * @return DestructionQuery|null The query, or null when the filter has no `deletedSince`.
	 *
	 * @spec openspec/changes/incremental-sync-for-api-consumers/specs/incremental-sync/spec.md#requirement-destroyed-objects-are-reported-as-tombstones-req-sync-003
	 */
	public function queryFor(DeletedFilter $filter, ?string $actor): ?DestructionQuery {
		if ($filter->deletedSince === null) {
			return null;
		}

		return new DestructionQuery(
			since: $filter->deletedSince,
			registerId: $filter->registerId,
			schemaId: $filter->schemaId,
			actor: $actor
		);
	}//end queryFor()

	/**
	 * The tombstones on one page of the combined list, their total, and the coverage.
	 *
	 * @param DestructionQuery $query        The destruction entries asked for.
	 * @param int              $deletedTotal How many bin rows precede the tombstones.
	 * @param int              $limit        The page size; no maximum when zero or less.
	 * @param int              $offset       The page's offset in the combined list.
	 *
	 * @return array{results: array<int, array<string, mixed>>, total: int, destroyedCoverageFrom: string|null}
	 *
	 * @spec openspec/changes/incremental-sync-for-api-consumers/specs/incremental-sync/spec.md#requirement-destroyed-objects-are-reported-as-tombstones-req-sync-003
	 */
	public function listing(DestructionQuery $query, int $deletedTotal, int $limit, int $offset): array {
		$offset = max(0, $offset);
		$start  = max($offset, $deletedTotal) - $deletedTotal;

		$results = [];
		$take    = null;
		if ($limit > 0) {
			$take = ($offset + $limit) - max($offset, $deletedTotal);
		}

		if ($take === null || $take > 0) {
			$records = $this->authorizer->readableDestructionRecords(
				records: $this->auditTrailMapper->findDestructions(query: $query->page(limit: $take, offset: $start))
			);
			// The authorizer answers in the Core audit-record port; the destruction
			// rows it was handed are audit trail entities (their column accessors are
			// the port's tags), and only those carry a tombstone.
			$entries = array_filter($records, static fn (AuditRecordInterface $record): bool => $record instanceof Entity);
			$results = array_map(static fn (AuditRecordInterface $record): array => self::tombstone(record: $record), $entries);
		}

		$oldest = $this->auditTrailMapper->oldestDestruction();

		return [
			'results' => array_values($results),
			'total' => $this->auditTrailMapper->countDestructions(query: $query),
			'destroyedCoverageFrom' => $oldest?->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM),
		];
	}//end listing()

	/**
	 * The tombstone of one destruction entry: identity and moment, no object data.
	 *
	 * @param AuditRecordInterface $record The destruction entry.
	 *
	 * @return array{id: string|null, register: int|null, schema: int|null, destroyedAt: string|null, tombstone: true}
	 *
	 * @spec openspec/changes/incremental-sync-for-api-consumers/specs/incremental-sync/spec.md#requirement-destroyed-objects-are-reported-as-tombstones-req-sync-003
	 */
	public static function tombstone(AuditRecordInterface $record): array {
		$created     = $record->getCreated();
		$destroyedAt = null;
		if ($created instanceof DateTimeInterface) {
			$destroyedAt = DateTimeImmutable::createFromInterface($created)
				->setTimezone(new DateTimeZone('UTC'))
				->format(DateTimeInterface::ATOM);
		}

		return [
			'id' => $record->getObjectUuid(),
			'register' => $record->getRegister(),
			'schema' => $record->getSchema(),
			'destroyedAt' => $destroyedAt,
			'tombstone' => true,
		];
	}//end tombstone()
}//end class
