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
use OCA\OpenRegister\Db\SchemaMapper;
use Throwable;

/**
 * Masks the change sets of audit entries by each entry's schema.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) ExplicitReveal's rules are pure functions.
 *
 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
 */
class ExplicitRevealTrailMasker {

	/**
	 * Schemas already read, by id; null when it could not be read.
	 *
	 * @var array<string, Schema|null>
	 */
	private array $schemas = [];

	/**
	 * Constructor.
	 *
	 * @param SchemaMapper $schemaMapper Reads an entry's schema.
	 */
	public function __construct(
		private readonly SchemaMapper $schemaMapper,
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
			$row = $entry;
			if ($entry instanceof JsonSerializable) {
				$row = $entry->jsonSerialize();
			}

			if (is_array($row) === false || is_array($row['changed'] ?? null) === false) {
				continue;
			}

			$schema = $this->schemaOf(id: ($row['schema'] ?? null));
			if ($schema === null) {
				// No schema named, or one that can no longer be read (deleted,
				// bad id): which values were explicit-reveal is unknown, so
				// mask them all.
				$row['changed']  = self::maskEveryChange(changed: $row['changed']);
				$entries[$key] = $row;
				continue;
			}

			if (ExplicitReveal::explicitProperties(schema: $schema) === []) {
				continue;
			}

			$row['changed'] = ExplicitReveal::maskChanges(schema: $schema, changed: $row['changed']);
			$entries[$key] = $row;
		}

		return $entries;
	}//end mask()

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
