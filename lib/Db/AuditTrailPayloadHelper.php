<?php

/**
 * OpenRegister Audit Trail Payload Helper
 *
 * This file contains dependency-free helper routines extracted from
 * AuditTrailMapper for building and reverting audit-trail payloads.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;


/**
 * Dependency-free helpers for audit-trail payload conversion and reversion.
 *
 * These routines were extracted verbatim from {@see AuditTrailMapper} to keep
 * that class below the method-count threshold. They hold no state and take no
 * dependencies, so the mapper instantiates one directly.
 *
 * @package OCA\OpenRegister\Db
 */
class AuditTrailPayloadHelper {

	/**
	 * Largest a single `changed` property value may be, in bytes.
	 *
	 * 64 KB is far above any real field-level change and far below the 62 MB
	 * single entry measured on the dev instance. An audit trail records THAT a
	 * property changed and by whom; storing a multi-megabyte copy of the value
	 * is a backup of the object filed under a different name.
	 *
	 * @var integer
	 */
	private const MAX_CHANGED_VALUE_BYTES = 65536;


	/**
	 * Convert an entity property value to its database representation.
	 *
	 * Mirrors the conversions QBMapper::insert() applies through parameter
	 * types: json fields are json_encode'd and datetime fields are formatted
	 * with the platform datetime format (`Y-m-d H:i:s`).
	 *
	 * @param mixed $value The property value
	 * @param string $type The declared entity field type
	 *
	 * @return mixed The database-ready value
	 */
	public function toDatabaseValue(mixed $value, string $type): mixed {
		if ($value === null) {
			return null;
		}

		if ($type === 'json') {
			return json_encode($value);
		}

		if ($type === 'datetime' && $value instanceof \DateTimeInterface) {
			return $value->format('Y-m-d H:i:s');
		}

		if ($type === 'boolean' || $type === 'bool') {
			return (int)$value;
		}

		return $value;
	}//end toDatabaseValue()

	/**
	 * Check if a string is a semantic version
	 *
	 * @param string $version The version string to check
	 *
	 * @return bool True if string is a semantic version
	 */
	public function isSemanticVersion(string $version): bool {
		return (preg_match('/^\d+\.\d+\.\d+$/', $version) === 1);
	}//end isSemanticVersion()

	/**
	 * Undo one audit trail entry on an object's data
	 *
	 * The change set is keyed by the object's data properties (it is a diff of
	 * two `jsonSerialize()` outputs), so the old values go back into the data,
	 * not onto entity properties: a reflection write to a property called
	 * `title` threw on every revert (#4161). A property the entry added (old
	 * value null) is removed again. The `@self` metadata and the top-level `id`
	 * are not data and are left alone, and a create entry is never undone,
	 * since that would empty the object instead of restoring a state of it.
	 *
	 * @param ObjectEntity $object The object to apply reversions to
	 * @param AuditTrail   $audit  The audit trail entry
	 *
	 * @return void
	 *
	 * @spec openspec/specs/content-versioning/spec.md
	 */
	public function revertChanges(ObjectEntity $object, AuditTrail $audit): void {
		$changes = $audit->getChanged();
		if (is_array($changes) === false || $audit->getAction() === 'create') {
			return;
		}

		$data = ($object->getObject() ?? []);
		foreach ($changes as $field => $change) {
			if ($field === '@self' || $field === 'id' || is_array($change) === false
				|| array_key_exists('old', $change) === false
			) {
				continue;
			}

			if ($change['old'] === null) {
				unset($data[$field]);
				continue;
			}

			$data[$field] = $change['old'];
		}

		$object->setObject($data);
	}//end revertChanges()

	/**
	 * Bound what a single audit entry's `changed` payload may hold.
	 *
	 * MEASURED 2026-08-14: `oc_openregister_audit_trails` was 3,404 MB — 28% of
	 * a 12 GB database — and ONE row's `changed` payload was **61,910,691 bytes**.
	 * A 62 MB audit entry is a copy of an object, not a record of a change, and
	 * it is charged to every backup, every replica and every TOAST read.
	 *
	 * A per-property value over the threshold is replaced by a descriptor
	 * recording what was elided and how large it was, so the entry still says
	 * THAT the property changed and roughly how much — only the bytes go. The
	 * property list, the action, the actor and the hash chain are untouched,
	 * which is what an audit trail is actually for.
	 *
	 * ⚠️ Retention alone does not solve this: {@see AuditTrailMapper::clearLogs()} only prunes rows
	 * that have EXPIRED, so an unexpired 62 MB row sits there for its full
	 * retention period regardless.
	 *
	 * @param array|null $changed The changed-properties map.
	 *
	 * @return array|null The map with oversized values replaced by descriptors.
	 */
	public function capChangedPayload(?array $changed): ?array {
		if ($changed === null) {
			return null;
		}

		foreach ($changed as $property => $value) {
			$encoded = json_encode($value);
			if ($encoded === false || strlen($encoded) <= self::MAX_CHANGED_VALUE_BYTES) {
				continue;
			}

			$changed[$property] = [
				'elided' => true,
				'reason' => 'value exceeded the ' . self::MAX_CHANGED_VALUE_BYTES
					. '-byte audit payload ceiling',
				'bytes' => strlen($encoded),
			];
		}

		return $changed;
	}//end capChangedPayload()

}//end class
