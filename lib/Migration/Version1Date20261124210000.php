<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The read rule that granted a read, on the audit trail.
 *
 * Two nullable columns: `rule_digest`, the SHA-256 of the canonical JSON of the
 * schema read rule that let the reader see the object, and `rule_version`, the
 * schema version that rule belonged to. Written on new `read` entries only; no
 * existing row is rewritten and no hash chain is re-sealed, because a null value
 * stays out of the sealed form (openregister ADR-003).
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/read-audit-entries-name-the-rule/specs/audit-trail-immutable/spec.md#requirement-a-read-entry-names-the-access-rule-that-let-the-reader-see-the-object
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;

/**
 * Adds `rule_digest` and `rule_version` to the audit trail.
 *
 * @spec openspec/changes/read-audit-entries-name-the-rule/specs/audit-trail-immutable/spec.md#requirement-a-read-entry-names-the-access-rule-that-let-the-reader-see-the-object
 */
class Version1Date20261124210000 extends TargetedMigrationStep {

	/**
	 * The audit trail table.
	 */
	private const TABLE = 'openregister_audit_trails';

	/**
	 * The columns this migration adds, with their length.
	 *
	 * @var array<string, int>
	 */
	private const COLUMNS = [
		'rule_digest'  => 64,
		'rule_version' => 64,
	];

	/**
	 * Add the columns unless they are already there.
	 *
	 * @param IOutput              $output        Migration output.
	 * @param Closure              $schemaClosure Returns the schema wrapper.
	 * @param array<string, mixed> $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The schema, changed or not.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Nextcloud's.
	 *
	 * @spec openspec/changes/read-audit-entries-name-the-rule/specs/audit-trail-immutable/spec.md#requirement-a-read-entry-names-the-access-rule-that-let-the-reader-see-the-object
	 */
	protected function changeTargetedSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable(self::TABLE) === false) {
			return $schema;
		}

		$table = $schema->getTable(self::TABLE);
		foreach (self::COLUMNS as $column => $length) {
			if ($table->hasColumn($column) === false) {
				$table->addColumn($column, Types::STRING, ['notnull' => false, 'length' => $length]);
				$output->info('Added column '.$column.' to '.self::TABLE);
			}
		}

		return $schema;
	}//end changeTargetedSchema()
}//end class
