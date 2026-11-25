<?php

/**
 * Restore a deleted object together with what its delete cascaded to.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Deletion
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/records-restore-with-cascade/specs/deletion-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Deletion;

use DateTimeImmutable;
use OCA\OpenRegister\Db\AuditTrail\ActionLookupOptions;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Core\AuditRecordInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectPatch;
use OCP\IDBConnection;
use Throwable;

/**
 * Reads back what a delete wrote down and undoes it, without overwriting later work.
 *
 * A delete leaves one audit entry per object it acted on, keyed by the
 * deleted object in the indexed `trigger_object` column: a
 * `referential_integrity.cascade_delete` for every dependant it soft-deleted,
 * and a `set_null` or `set_default` for every surviving object whose
 * reference it cleared. {@see self::preview()} classifies each of those
 * objects as it is now; {@see self::restore()} runs the restores and relinks
 * in one transaction, and refuses the whole act when the caller may not write
 * one of them.
 *
 * Two classes protect other people's work: an object deleted again later, or
 * restored since, is `already`; a reference someone has set to another value
 * since is `changed`. Neither is touched.
 *
 * @spec openspec/changes/records-restore-with-cascade/specs/deletion-audit-trail/spec.md
 */
class CascadeRestoreService {
	public const CLASS_RESTORE   = 'restore';
	public const CLASS_RELINK    = 'relink';
	public const CLASS_CHANGED   = 'changed';
	public const CLASS_GONE      = 'gone';
	public const CLASS_ALREADY   = 'already';
	public const CLASS_FORBIDDEN = 'forbidden';

	/**
	 * Every class an item can be in.
	 *
	 * @var list<string>
	 */
	public const CLASSES = [
		self::CLASS_RESTORE,
		self::CLASS_RELINK,
		self::CLASS_CHANGED,
		self::CLASS_GONE,
		self::CLASS_ALREADY,
		self::CLASS_FORBIDDEN,
	];

	/**
	 * The classes a restore leaves alone and names in its response.
	 *
	 * @var list<string>
	 */
	public const SKIPPED_CLASSES = [self::CLASS_CHANGED, self::CLASS_GONE, self::CLASS_ALREADY];

	/**
	 * The most audit rows one restore takes; a larger cascade is refused, never halved.
	 */
	public const MAX_ROWS = 5000;

	public const ACTION_CASCADE_DELETE = 'referential_integrity.cascade_delete';
	public const ACTION_SET_NULL       = 'referential_integrity.set_null';
	public const ACTION_SET_DEFAULT    = 'referential_integrity.set_default';

	/**
	 * How long before the root's own deletion a dependant's deletion may lie and
	 * still count as the same act: the cascade runs before the root is stamped,
	 * and a large cascade takes longer than a second.
	 */
	public const CASCADE_SPAN_SECONDS = 300;

	/**
	 * Marker for "the field no longer holds what the delete wrote".
	 */
	private const NOT_RELINKABLE = "\0not-relinkable";

	/**
	 * Constructor.
	 *
	 * @param AuditTrailMapperInterface        $auditTrails The trail the delete wrote to.
	 * @param MagicMapperInterface             $objects     The object store, for the lookup and the restore.
	 * @param DeletedObjectAuthorizerInterface $authorizer  The caller's rights on each object.
	 * @param DeletionWindowService            $windows     The recovery window of each dependant.
	 * @param ObjectOperationsInterface        $operations  The patch path a relink writes through.
	 * @param IDBConnection                    $db          The transaction the act runs in.
	 */
	public function __construct(
		private readonly AuditTrailMapperInterface $auditTrails,
		private readonly MagicMapperInterface $objects,
		private readonly DeletedObjectAuthorizerInterface $authorizer,
		private readonly DeletionWindowService $windows,
		private readonly ObjectOperationsInterface $operations,
		private readonly IDBConnection $db,
	) {
	}//end __construct()

	/**
	 * What restoring this object with its cascade would do, written by nothing.
	 *
	 * @param ObjectEntity           $root The soft-deleted object.
	 * @param DateTimeImmutable|null $now  The moment windows are judged at (now when null).
	 *
	 * @return CascadeRestorePlan Every object the delete acted on, classified.
	 *
	 * @throws CascadeRestoreRefusedException 409 when the cascade is over {@see self::MAX_ROWS}.
	 *
	 * @spec openspec/changes/records-restore-with-cascade/specs/deletion-audit-trail/spec.md
	 */
	public function preview(ObjectEntity $root, ?DateTimeImmutable $now=null): CascadeRestorePlan {
		$rootUuid = (string)$root->getUuid();
		$rows = $this->auditTrails->findTriggeredActions(
			triggerObject: $rootUuid,
			options: new ActionLookupOptions(
				actions: [self::ACTION_CASCADE_DELETE, self::ACTION_SET_NULL, self::ACTION_SET_DEFAULT],
				limit: (self::MAX_ROWS + 1)
			)
		);
		if (count($rows) > self::MAX_ROWS) {
			throw CascadeRestoreRefusedException::tooLarge(cap: self::MAX_ROWS);
		}

		$found = $this->foundObjects(rows: $rows, rootUuid: $rootUuid);
		$items = [];
		$seen  = [];
		foreach ($rows as $row) {
			$uuid = (string)$row->getObjectUuid();
			$key  = $uuid.'|'.$row->getAction().'|'.(string)(($row->getChanged() ?? [])['property'] ?? '');
			if ($uuid === '' || $uuid === $rootUuid || isset($seen[$key]) === true) {
				continue;
			}

			$seen[$key] = true;
			$items[]    = $this->classify(row: $row, object: ($found[$uuid] ?? null), root: $root, now: ($now ?? new DateTimeImmutable()));
		}

		return new CascadeRestorePlan(root: $rootUuid, cascadeKnown: $rows !== [], items: $items);
	}//end preview()

	/**
	 * Restore the object and, unless told not to, what its delete cascaded to, as one act.
	 *
	 * Every restore and relink runs in one transaction: a failure in any of
	 * them rolls back the root as well, so nobody is left with half a tree.
	 *
	 * @param ObjectEntity $root    The soft-deleted object; the caller has checked `update` on it.
	 * @param bool         $cascade False restores the object alone, as before this change.
	 *
	 * @return CascadeRestoreResult The dependants brought back and the references put back.
	 *
	 * @throws CascadeRestoreRefusedException 403 when one object may not be restored, 409 over the cap.
	 * @throws Throwable When a write fails; nothing is kept.
	 *
	 * @spec openspec/changes/records-restore-with-cascade/specs/deletion-audit-trail/spec.md
	 */
	public function restore(ObjectEntity $root, bool $cascade=true): CascadeRestoreResult {
		if ($cascade === false) {
			$this->objects->restoreObject(uuid: (string)$root->getUuid());

			return new CascadeRestoreResult(dependants: [], relinked: 0);
		}

		$plan = $this->preview(root: $root);
		if ($plan->isForbidden() === true) {
			throw CascadeRestoreRefusedException::forbidden(plan: $plan);
		}

		$this->db->beginTransaction();
		try {
			$this->objects->restoreObject(uuid: (string)$root->getUuid());
			$dependants = [];
			foreach ($plan->ofClass(class: self::CLASS_RESTORE) as $item) {
				$dependants[] = $this->objects->restoreObject(uuid: $item->uuid);
			}

			$relinks = $plan->ofClass(class: self::CLASS_RELINK);
			foreach ($relinks as $item) {
				$this->relink(item: $item);
			}

			$this->db->commit();
		} catch (Throwable $failure) {
			$this->db->rollBack();
			throw $failure;
		}

		return new CascadeRestoreResult(dependants: $dependants, relinked: count($relinks), plan: $plan);
	}//end restore()

	/**
	 * Put one cleared reference back through the patch path, so validation, events and the audit apply.
	 *
	 * @param CascadeRestoreItem $item A relink item.
	 *
	 * @return void
	 */
	private function relink(CascadeRestoreItem $item): void {
		$this->operations->patchObject(
			patch: new ObjectPatch(
				objectId: $item->uuid,
				data: [(string)$item->property => $item->relinkTo],
				target: new ObjectFindTarget(register: $item->register, schema: $item->schemaRef)
			)
		);
	}//end relink()

	/**
	 * The objects the rows name, found in one batched lookup across the magic tables, keyed by uuid.
	 *
	 * @param list<AuditRecordInterface> $rows     The cascade evidence.
	 * @param string           $rootUuid The root, which is not looked up again.
	 *
	 * @return array<string, ObjectEntity> The objects that still exist, deleted or not.
	 */
	private function foundObjects(array $rows, string $rootUuid): array {
		$uuids = [];
		foreach ($rows as $row) {
			$uuid = (string)$row->getObjectUuid();
			if ($uuid !== '' && $uuid !== $rootUuid) {
				$uuids[$uuid] = true;
			}
		}

		if ($uuids === []) {
			return [];
		}

		$found = [];
		foreach ($this->objects->findMultipleAcrossAllMagicTables(uuids: array_keys($uuids), includeDeleted: true) as $object) {
			$found[(string)$object->getUuid()] = $object;
		}

		return $found;
	}//end foundObjects()

	/**
	 * The class of one object the delete acted on.
	 *
	 * @param AuditRecordInterface        $row    The audit entry the delete wrote for it.
	 * @param ObjectEntity|null $object The object as it is now, null when destroyed.
	 * @param ObjectEntity      $root   The object being restored.
	 * @param DateTimeImmutable $now    The moment windows are judged at.
	 *
	 * @return CascadeRestoreItem The classified item.
	 */
	private function classify(AuditRecordInterface $row, ?ObjectEntity $object, ObjectEntity $root, DateTimeImmutable $now): CascadeRestoreItem {
		$uuid = (string)$row->getObjectUuid();
		if ($object === null) {
			return new CascadeRestoreItem(uuid: $uuid, class: self::CLASS_GONE, reason: 'destroyed', schema: self::schemaOfRow(row: $row));
		}

		if ($row->getAction() === self::ACTION_CASCADE_DELETE) {
			return $this->classifyDependant(object: $object, root: $root, now: $now);
		}

		return $this->classifySurvivor(row: $row, object: $object);
	}//end classify()

	/**
	 * The class of an object the delete soft-deleted with the root.
	 *
	 * @param ObjectEntity      $object The dependant as it is now.
	 * @param ObjectEntity      $root   The object being restored.
	 * @param DateTimeImmutable $now    The moment its window is judged at.
	 *
	 * @return CascadeRestoreItem Restore, or why not.
	 */
	private function classifyDependant(ObjectEntity $object, ObjectEntity $root, DateTimeImmutable $now): CascadeRestoreItem {
		if ($object->isSoftDeleted() === false) {
			return $this->item(object: $object, class: self::CLASS_ALREADY, reason: 'restored or recreated since');
		}

		if (self::deletedWith(object: $object, root: $root) === false) {
			return $this->item(object: $object, class: self::CLASS_ALREADY, reason: 'deleted again later, by another act');
		}

		$window = $this->windows->windowOf(
			query: new DeletionWindowQuery(object: $object, schema: $this->authorizer->resolveSchema(object: $object), now: $now)
		);
		if ($window !== null && $window->destroyableFrom() <= $now) {
			return $this->item(object: $object, class: self::CLASS_GONE, reason: 'its recovery window has passed');
		}

		return $this->writable(item: $this->item(object: $object, class: self::CLASS_RESTORE, reason: 'deleted with this object'), object: $object);
	}//end classifyDependant()

	/**
	 * The class of a surviving object whose reference the delete cleared or defaulted.
	 *
	 * The rule a reversal follows everywhere: a value is written back only
	 * where the field still holds what the delete wrote, and an array only
	 * gains the uuid back when it is absent.
	 *
	 * @param AuditRecordInterface   $row    The set_null or set_default entry.
	 * @param ObjectEntity $object The survivor as it is now.
	 *
	 * @return CascadeRestoreItem Relink, or why not.
	 */
	private function classifySurvivor(AuditRecordInterface $row, ObjectEntity $object): CascadeRestoreItem {
		$changed  = ($row->getChanged() ?? []);
		$property = (string)($changed['property'] ?? '');
		$previous = ($changed['previousValue'] ?? null);
		if ($property === '' || $previous === null) {
			return $this->item(object: $object, class: self::CLASS_GONE, reason: 'the entry does not record the reference', property: $property);
		}

		if ($object->isSoftDeleted() === true) {
			return $this->item(object: $object, class: self::CLASS_CHANGED, reason: 'deleted since', property: $property);
		}

		$current = (($object->getObject() ?? [])[$property] ?? null);
		$relinkTo = self::relinkValue(action: (string)$row->getAction(), changed: $changed, current: $current);
		if ($relinkTo === self::NOT_RELINKABLE) {
			if (self::alreadyLinked(current: $current, previous: $previous) === true) {
				return $this->item(object: $object, class: self::CLASS_ALREADY, reason: 'the reference is back already', property: $property);
			}

			return $this->item(object: $object, class: self::CLASS_CHANGED, reason: 'the field was changed since the delete', property: $property);
		}

		$item = $this->item(
			object: $object,
			class: self::CLASS_RELINK,
			reason: 'the field still holds what the delete wrote',
			property: $property,
			relinkTo: $relinkTo
		);

		return $this->writable(item: $item, object: $object);
	}//end classifySurvivor()

	/**
	 * The value a relink writes, or {@see self::NOT_RELINKABLE}.
	 *
	 * @param string               $action  set_null or set_default.
	 * @param array<string, mixed> $changed The entry's recorded change.
	 * @param mixed                $current The field's value now.
	 *
	 * @return mixed The value to write.
	 */
	private static function relinkValue(string $action, array $changed, mixed $current): mixed {
		$previous = $changed['previousValue'];
		if (is_array($current) === true) {
			if (in_array($previous, $current, true) === true) {
				return self::NOT_RELINKABLE;
			}

			return array_merge(array_values($current), [$previous]);
		}

		$written = null;
		if ($action === self::ACTION_SET_DEFAULT) {
			$written = ($changed['defaultValue'] ?? null);
		}

		if ($current === $written) {
			return $previous;
		}

		return self::NOT_RELINKABLE;
	}//end relinkValue()

	/**
	 * Whether the field already holds the reference the delete took out.
	 *
	 * @param mixed $current  The field's value now.
	 * @param mixed $previous The reference the delete took out.
	 *
	 * @return bool True when nothing is left to put back.
	 */
	private static function alreadyLinked(mixed $current, mixed $previous): bool {
		if (is_array($current) === true) {
			return in_array($previous, $current, true);
		}

		return $current === $previous;
	}//end alreadyLinked()

	/**
	 * Whether a dependant was soft-deleted by the same act as the root.
	 *
	 * The cascade stamps its dependants just before the root, so a dependant
	 * deleted by this act carries a deletion moment no later than the root's
	 * (one second of slack for the clock) and no earlier than
	 * {@see self::CASCADE_SPAN_SECONDS} before it. An object restored and
	 * deleted again by someone else afterwards falls outside that span.
	 *
	 * @param ObjectEntity $object The dependant.
	 * @param ObjectEntity $root   The object being restored.
	 *
	 * @return bool True when both deletions belong to one act; true as well when either moment is unrecorded.
	 *
	 * @spec openspec/changes/records-restore-with-cascade/specs/deletion-audit-trail/spec.md
	 */
	public static function deletedWith(ObjectEntity $object, ObjectEntity $root): bool {
		$dependantAt = self::deletedAt(object: $object);
		$rootAt      = self::deletedAt(object: $root);
		if ($dependantAt === null || $rootAt === null) {
			return true;
		}

		$offset = ($rootAt->getTimestamp() - $dependantAt->getTimestamp());

		return $offset >= -1 && $offset <= self::CASCADE_SPAN_SECONDS;
	}//end deletedWith()

	/**
	 * The moment an object was soft-deleted, as its deletion metadata records it.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return DateTimeImmutable|null The moment, or null when unrecorded or unreadable.
	 */
	private static function deletedAt(ObjectEntity $object): ?DateTimeImmutable {
		$value = (($object->getDeleted() ?? [])['deletedAt'] ?? null);
		if (is_string($value) === false || $value === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Throwable $e) {
			return null;
		}
	}//end deletedAt()

	/**
	 * An item for an object that exists, named the way the caller may see it.
	 *
	 * @param ObjectEntity $object   The object.
	 * @param string       $class    The class.
	 * @param string       $reason   Why.
	 * @param string|null  $property The reference property, for a survivor.
	 * @param mixed        $relinkTo The value a relink writes.
	 *
	 * @return CascadeRestoreItem The item.
	 */
	private function item(ObjectEntity $object, string $class, string $reason, ?string $property=null, mixed $relinkTo=null): CascadeRestoreItem {
		$schema = $this->authorizer->resolveSchema(object: $object);
		$name   = null;
		if ($schema !== null) {
			$name = (string)($schema->getSlug() ?? $schema->getId());
		} else if ($object->getSchema() !== null) {
			$name = (string)$object->getSchema();
		}

		return new CascadeRestoreItem(
			uuid: (string)$object->getUuid(),
			class: $class,
			reason: $reason,
			schema: $name,
			visible: $this->authorizer->userMayActOnDeletedObject(object: $object, action: 'read'),
			property: $property,
			relinkTo: $relinkTo,
			register: $object->getRegister(),
			schemaRef: $object->getSchema()
		);
	}//end item()

	/**
	 * The item, or a forbidden one when the caller may not write the object.
	 *
	 * @param CascadeRestoreItem $item   A restore or relink item.
	 * @param ObjectEntity       $object The object it writes.
	 *
	 * @return CascadeRestoreItem The item as it stands, or forbidden.
	 */
	private function writable(CascadeRestoreItem $item, ObjectEntity $object): CascadeRestoreItem {
		if ($this->authorizer->userMayActOnDeletedObject(object: $object, action: 'update') === true) {
			return $item;
		}

		return new CascadeRestoreItem(
			uuid: $item->uuid,
			class: self::CLASS_FORBIDDEN,
			reason: 'you may not update it',
			schema: $item->schema,
			visible: false
		);
	}//end writable()

	/**
	 * The schema of an object that no longer exists, as its audit entry names it.
	 *
	 * @param AuditRecordInterface $row The entry.
	 *
	 * @return string|null The schema id, or null.
	 */
	private static function schemaOfRow(AuditRecordInterface $row): ?string {
		$schema = $row->getSchema();
		if ($schema === null) {
			return null;
		}

		return (string)$schema;
	}//end schemaOfRow()
}//end class
