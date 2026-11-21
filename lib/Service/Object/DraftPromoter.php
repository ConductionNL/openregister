<?php

/**
 * DraftPromoter — makes a named draft the published version of its object.
 *
 * Per field the draft changes, the value the object had at the draft's base
 * version is compared with the published value. A field changed on both sides
 * to different values is a conflict, and promotion is refused with those
 * fields. Without conflicts the draft's data is saved through the normal save
 * path, so validation, access rules, hooks and the audit trail all run once,
 * and the draft is deleted in the same transaction. An administrator may
 * promote over conflicts; the audit entry then names the overwritten fields.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/content-versioning/spec.md#requirement-drafts-must-be-promotable-to-published-version
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\AuditTrail\RevertOptions;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\DraftVersion;
use OCA\OpenRegister\Db\DraftVersionMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\DraftConflictException;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\IDBConnection;
use OCP\IGroupManager;
use Throwable;

/**
 * Promotion of a draft, with per-field conflict detection.
 *
 * @spec openspec/specs/content-versioning/spec.md#requirement-drafts-must-be-promotable-to-published-version
 */
class DraftPromoter {

	/**
	 * The audit action of a promotion.
	 *
	 * @var string
	 */
	public const AUDIT_ACTION = 'draft.promoted';

	/**
	 * Constructor.
	 *
	 * @param DraftService              $drafts        Who may touch a draft, and the merged data.
	 * @param DraftVersionMapper        $draftVersions The draft store.
	 * @param AuditTrailMapperInterface $auditTrails   The object's state at the base version, and the promotion entry.
	 * @param ObjectOperationsInterface $objects       The normal save path.
	 * @param IDBConnection             $db            The transaction.
	 * @param IGroupManager             $groups        Who is an administrator.
	 */
	public function __construct(
		private readonly DraftService $drafts,
		private readonly DraftVersionMapper $draftVersions,
		private readonly AuditTrailMapperInterface $auditTrails,
		private readonly ObjectOperationsInterface $objects,
		private readonly IDBConnection $db,
		private readonly IGroupManager $groups,
	) {
	}//end __construct()

	/**
	 * Make the draft the published version and delete it.
	 *
	 * @param DraftVersion $draft  The draft.
	 * @param ObjectEntity $object The published object.
	 * @param Schema       $schema The object's schema.
	 * @param string|null  $caller The calling user.
	 * @param bool         $force  Promote over conflicts (administrators only).
	 *
	 * @return ObjectEntity The saved object.
	 *
	 * @throws NotAuthorizedException When the caller may not change the object, or forces without being an administrator.
	 * @throws DraftConflictException When fields the draft changes were changed on the published object too.
	 * @throws Throwable              What the save refuses with (validation, hooks); nothing is changed then.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The force flag is the route's own parameter.
	 *
	 * @spec openspec/specs/content-versioning/spec.md#requirement-drafts-must-be-promotable-to-published-version
	 */
	public function promote(DraftVersion $draft, ObjectEntity $object, Schema $schema, ?string $caller, bool $force=false): ObjectEntity {
		if ($caller === null || $this->drafts->mayCreate(object: $object, schema: $schema, caller: $caller) === false) {
			throw new NotAuthorizedException(message: 'Promoting a draft needs the right to change this object.');
		}

		if ($force === true && $this->groups->isAdmin($caller) !== true) {
			throw new NotAuthorizedException(message: 'Only an administrator may promote a draft over conflicting changes.');
		}

		$delta     = ($draft->getDelta() ?? []);
		$live      = self::dataOf(object: $object);
		$conflicts = self::conflicts(delta: $delta, base: $this->baseData(object: $object, draft: $draft, live: $live), live: $live);
		if ($conflicts !== [] && $force === false) {
			throw new DraftConflictException(conflicts: $conflicts);
		}

		$this->db->beginTransaction();
		try {
			$saved = $this->objects->saveObject(
				object: $this->drafts->merged(object: $object, draft: $draft),
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: $object->getRegister(), schema: $schema),
					uuid: (string)$object->getUuid()
				)
			);
			$this->auditTrails->recordObjectEvent(
				event: new ObjectAuditEvent(
					object: $saved,
					action: self::AUDIT_ACTION,
					context: self::auditContext(draft: $draft, live: $live, conflicts: $conflicts)
				)
			);
			$this->draftVersions->delete(entity: $draft);
			$this->db->commit();
		} catch (HookStoppedException $stopped) {
			// A hook refuses before the object is written; what the refusing
			// hook stored itself (a held change) is kept. The draft stays.
			$this->db->commit();
			throw $stopped;
		} catch (Throwable $failure) {
			$this->db->rollBack();
			throw $failure;
		}

		return $saved;
	}//end promote()

	/**
	 * The fields the draft and the published object both changed, to different values.
	 *
	 * A field is not a conflict when the published value still equals the
	 * base value, or already equals the draft's value. With no known base
	 * (null), every field whose published value differs from the draft's is
	 * a conflict: an unprovable base never lets a change be overwritten silently.
	 *
	 * @param array<string, mixed>      $delta The draft's changed fields.
	 * @param array<string, mixed>|null $base  The object's data at the draft's base version, or null when unknown.
	 * @param array<string, mixed>      $live  The published data.
	 *
	 * @return array<int, array{field: string, draft: mixed, live: mixed}>
	 *
	 * @spec openspec/specs/content-versioning/spec.md#requirement-drafts-must-be-promotable-to-published-version
	 */
	public static function conflicts(array $delta, ?array $base, array $live): array {
		$conflicts = [];
		foreach ($delta as $field => $value) {
			$field     = (string)$field;
			$liveValue = ($live[$field] ?? null);
			if (array_key_exists($field, $live) === true && $liveValue === $value) {
				continue;
			}

			if ($base !== null && self::sameValue(left: $base, right: $live, field: $field) === true) {
				continue;
			}

			$conflicts[] = ['field' => $field, 'draft' => $value, 'live' => $liveValue];
		}

		return $conflicts;
	}//end conflicts()

	/**
	 * The object's data at the draft's base version, or null when it cannot be told.
	 *
	 * @param ObjectEntity         $object The published object.
	 * @param DraftVersion         $draft  The draft.
	 * @param array<string, mixed> $live   The published data.
	 *
	 * @return array<string, mixed>|null
	 */
	private function baseData(ObjectEntity $object, DraftVersion $draft, array $live): ?array {
		$base = $draft->getBaseVersion();
		if ($base === $object->getVersion()) {
			return $live;
		}

		if ($base === null || $base === '') {
			return null;
		}

		try {
			$atBase = $this->auditTrails->revertObjectWith(identifier: clone $object, options: new RevertOptions(until: $base, overwriteVersion: true));
		} catch (Throwable) {
			return null;
		}

		return self::dataOf(object: $atBase);
	}//end baseData()

	/**
	 * What the promotion entry on the audit trail records.
	 *
	 * @param DraftVersion                                                $draft     The draft.
	 * @param array<string, mixed>                                        $live      The published data before promotion.
	 * @param array<int, array{field: string, draft: mixed, live: mixed}> $conflicts The conflicts promoted over.
	 *
	 * @return array<string, mixed>
	 */
	private static function auditContext(DraftVersion $draft, array $live, array $conflicts): array {
		$fields   = array_map('strval', array_keys(($draft->getDelta() ?? [])));
		$previous = [];
		foreach ($fields as $field) {
			$previous[$field] = ($live[$field] ?? null);
		}

		return [
			'draft'       => (string)$draft->getDraftKey(),
			'name'        => (string)$draft->getName(),
			'baseVersion' => $draft->getBaseVersion(),
			'fields'      => $fields,
			'previous'    => $previous,
			'forced'      => ($conflicts !== []),
			'overwritten' => array_column($conflicts, 'field'),
		];
	}//end auditContext()

	/**
	 * Whether a field has the same presence and value on both sides.
	 *
	 * @param array<string, mixed> $left  One side.
	 * @param array<string, mixed> $right The other side.
	 * @param string               $field The field.
	 *
	 * @return bool
	 */
	private static function sameValue(array $left, array $right, string $field): bool {
		$inLeft = array_key_exists($field, $left);
		if ($inLeft !== array_key_exists($field, $right)) {
			return false;
		}

		return $inLeft === false || $left[$field] === $right[$field];
	}//end sameValue()

	/**
	 * An object's own data, without metadata.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return array<string, mixed>
	 */
	private static function dataOf(ObjectEntity $object): array {
		$data = $object->getObject();
		if (is_array($data) === false) {
			return [];
		}

		unset($data['@self']);
		return $data;
	}//end dataOf()
}//end class
