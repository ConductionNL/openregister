<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * An index on the audit trail that leads with the object uuid.
 *
 * Every per-object audit read filters `openregister_audit_trails` on
 * `object_uuid` and orders by `created`: the object history tab, revert,
 * and AuditTrailMapper::findChangesForObject() that a leaf app's flow
 * report calls once per object. No earlier migration indexes that column,
 * so each of those reads scanned the whole table. Measured on the dev
 * instance (3 Oct 2026, 599,162 audit rows): a parallel sequential scan of
 * 788 ms for one object, 47 s for a 200-task planninq flow report.
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
 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-history-of-one-object-is-read-through-an-index
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the (object_uuid, created) index to the audit trail.
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-history-of-one-object-is-read-through-an-index
 */
class Version1Date20261003090000 extends SimpleMigrationStep {

	/**
	 * The audit trail table.
	 */
	private const TABLE = 'openregister_audit_trails';

	/**
	 * The index this migration adds.
	 */
	private const INDEX = 'or_audit_obj_uuid_created';

	/**
	 * The indexed columns, the filter column first and the sort column second.
	 */
	private const COLUMNS = ['object_uuid', 'created'];

	/**
	 * Add the index unless it is already there.
	 *
	 * @param IOutput              $output        Migration output.
	 * @param Closure              $schemaClosure Returns the schema wrapper.
	 * @param array<string, mixed> $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The schema, changed or not.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Nextcloud's.
	 *
	 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-history-of-one-object-is-read-through-an-index
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable(self::TABLE) === false) {
			return $schema;
		}

		$table = $schema->getTable(self::TABLE);
		if ($table->hasIndex(self::INDEX) === true) {
			return $schema;
		}

		foreach (self::COLUMNS as $column) {
			if ($table->hasColumn($column) === false) {
				return $schema;
			}
		}

		$table->addIndex(self::COLUMNS, self::INDEX);
		$output->info('Added index '.self::INDEX.' on '.self::TABLE.' (object_uuid, created)');

		return $schema;
	}//end changeSchema()
}//end class
