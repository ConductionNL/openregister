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

use ReflectionClass;

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
	 * Helper function to revert changes from an audit trail entry
	 *
	 * @param ObjectEntity $object The object to apply reversions to
	 * @param AuditTrail $audit The audit trail entry
	 *
	 * @return void
	 */
	public function revertChanges(ObjectEntity $object, AuditTrail $audit): void {
		$changes = $audit->getChanged();

		// Iterate through each change and apply the reverse.
		foreach ($changes as $field => $change) {
			if (($change['old'] ?? null) !== null) {
				// Use reflection to set the value if it's a protected property.
				$reflection = new ReflectionClass($object);
				$property = $reflection->getProperty($field);

				// Note: setAccessible() is no longer needed in PHP 8.1+ for same-class properties.
				$property->setValue($object, $change['old']);
			}
		}
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
