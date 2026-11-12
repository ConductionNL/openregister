<?php

/**
 * Masks explicit-reveal values in audit-trail entries, for every reader.
 *
 * The administrator's trail lists (`/api/audit-trails` and an object's logs
 * for an administrator) answer the stored change sets. An explicit-reveal
 * value in them (a BSN in `changed.old/new`) reads masked there too, so the
 * rule "shown only through the logged reveal" holds for administrators.
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
 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use JsonSerializable;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use Throwable;

/**
 * Masks the change sets of audit entries by each entry's schema.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) ExplicitReveal's rules are pure functions.
 *
 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
 */
class ExplicitRevealTrailMasker implements ExplicitRevealTrailMaskerInterface {

	/**
	 * Schemas already read, by id; null when it could not be read.
	 *
	 * @var array<string, Schema|null>
	 */
	private array $schemas = [];

	/**
	 * Constructor.
	 *
	 * @param SchemaMapperInterface $schemaMapper Reads an entry's schema.
	 */
	public function __construct(
		private readonly SchemaMapperInterface $schemaMapper,
	) {
	}//end __construct()

	/**
	 * The entries as arrays, their explicit-reveal values masked.
	 *
	 * @param array<int, mixed> $entries Audit entries (AuditTrail entities or arrays).
	 *
	 * @return array<int, mixed> The entries.
	 *
	 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
	 */
	public function mask(array $entries): array {
		foreach ($entries as $key => $entry) {
			$row = $this->maskedRow(entry: $entry);
			if ($row !== null) {
				$entries[$key] = $row;
			}
		}

		return $entries;
	}//end mask()

	/**
	 * One entry as an array with its explicit-reveal values masked, or null when it is left as it is.
	 *
	 * @param mixed $entry An AuditTrail entity or an array.
	 *
	 * @return array<string, mixed>|null The masked row.
	 */
	private function maskedRow(mixed $entry): ?array {
		$row = self::rowOf(entry: $entry);
		if (is_array($row) === false) {
			return null;
		}

		$changed = self::changesOf(row: $row);
		if (is_array($changed) === false) {
			return null;
		}

		return $this->rowMaskedBySchema(row: $row, changed: $changed);
	}//end maskedRow()

	/**
	 * The entry as its serialised form when it is an entity, else as it is.
	 *
	 * @param mixed $entry An AuditTrail entity or an array.
	 *
	 * @return mixed The row.
	 */
	private static function rowOf(mixed $entry): mixed {
		if ($entry instanceof JsonSerializable) {
			return $entry->jsonSerialize();
		}

		return $entry;
	}//end rowOf()

	/**
	 * The row with its change set masked by its schema, or null when the schema masks nothing.
	 *
	 * @param array<string, mixed> $row     The row.
	 * @param array<string, mixed> $changed The decoded change set.
	 *
	 * @return array<string, mixed>|null The masked row.
	 */
	private function rowMaskedBySchema(array $row, array $changed): ?array {
		$masked = $this->maskChanged(schemaId: ($row['schema'] ?? null), changed: $changed);
		if ($masked === null) {
			return null;
		}

		return self::withChanges(row: $row, masked: $masked);
	}//end rowMaskedBySchema()

	/**
	 * The row's change set, decoded when it is JSON text.
	 *
	 * A raw database row carries `changed` as its JSON text.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return mixed The change set.
	 */
	private static function changesOf(array $row): mixed {
		$changed = $row['changed'] ?? null;
		if (is_string($changed) === true) {
			return json_decode($changed, true);
		}

		return $changed;
	}//end changesOf()

	/**
	 * The row with its masked change set, encoded again when it came as JSON text.
	 *
	 * @param array<string, mixed> $row    The row.
	 * @param array<string, mixed> $masked The masked change set.
	 *
	 * @return array<string, mixed> The row.
	 */
	private static function withChanges(array $row, array $masked): array {
		$encoded = is_string($row['changed'] ?? null);
		$row['changed'] = $masked;
		if ($encoded === true) {
			$row['changed'] = json_encode($masked);
		}

		return $row;
	}//end withChanges()

	/**
	 * One change set, masked by its schema; null when the schema has no
	 * explicit-reveal property (the entry is left as it is).
	 *
	 * @param mixed                $schemaId The entry's schema id.
	 * @param array<string, mixed> $changed  The change set.
	 *
	 * @return array<string, mixed>|null
	 */
	private function maskChanged(mixed $schemaId, array $changed): ?array {
		$schema = $this->schemaOf(id: $schemaId);
		if ($schema === null) {
			// No schema named, or one that can no longer be read (deleted,
			// bad id): which values were explicit-reveal is unknown, so
			// mask them all.
			return self::maskEveryChange(changed: $changed);
		}

		if (ExplicitReveal::explicitProperties(schema: $schema) === []) {
			return null;
		}

		return ExplicitReveal::maskChanges(schema: $schema, changed: $changed);
	}//end maskChanged()

	/**
	 * Mask every value of a change set: `{prop: {old, new}}` or `{prop: value}`.
	 *
	 * @param array<string, mixed> $changed The change set.
	 *
	 * @return array<string, mixed> The change set, masked.
	 *
	 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
	 */
	private static function maskEveryChange(array $changed): array {
		foreach ($changed as $name => $change) {
			$changed[$name] = ExplicitRevealChangeMask::maskChange(change: $change);
		}

		return $changed;
	}//end maskEveryChange()

	/**
	 * The schema with this id, read once.
	 *
	 * @param mixed $id The schema id.
	 *
	 * @return Schema|null
	 */
	private function schemaOf(mixed $id): ?Schema {
		if (is_scalar($id) === false || (string)$id === '') {
			return null;
		}

		$key = (string)$id;
		if (array_key_exists($key, $this->schemas) === false) {
			try {
				$this->schemas[$key] = $this->schemaMapper->find(id: $key, _rbac: false, _multitenancy: false);
			} catch (Throwable) {
				$this->schemas[$key] = null;
			}
		}

		return $this->schemas[$key];
	}//end schemaOf()
}//end class
