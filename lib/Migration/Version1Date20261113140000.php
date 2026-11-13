<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The triggering object of a cascade, as an indexed column on the audit trail.
 *
 * A delete that cascades writes one audit entry per dependant it deleted and
 * per reference it cleared or defaulted, each naming the root object it acted
 * for. That name lived only inside the JSON `changed` column, which nothing
 * indexes, so finding everything one delete took meant scanning audit rows.
 * The column is written in the same insert as the row and is a projection of
 * `changed`, so no existing row is rewritten and no hash chain is re-sealed
 * (openregister ADR-003); rows written before this migration stay null.
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
 * @spec openspec/changes/records-restore-with-cascade/specs/deletion-audit-trail/spec.md#requirement-the-cascade-evidence-is-findable-and-lasts-as-long-as-the-recovery-window
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds `trigger_object` and the (trigger_object, action) index to the audit trail.
 *
 * @spec openspec/changes/records-restore-with-cascade/specs/deletion-audit-trail/spec.md#requirement-the-cascade-evidence-is-findable-and-lasts-as-long-as-the-recovery-window
 */
class Version1Date20261113140000 extends SimpleMigrationStep {

	/**
	 * The audit trail table.
	 */
	private const TABLE = 'openregister_audit_trails';

	/**
	 * The column this migration adds.
	 */
	private const COLUMN = 'trigger_object';

	/**
	 * The index this migration adds.
	 */
	private const INDEX = 'or_audit_trigger_action';

	/**
	 * Add the column and the index unless they are already there.
	 *
	 * @param IOutput              $output        Migration output.
	 * @param Closure              $schemaClosure Returns the schema wrapper.
	 * @param array<string, mixed> $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The schema, changed or not.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Nextcloud's.
	 *
	 * @spec openspec/changes/records-restore-with-cascade/specs/deletion-audit-trail/spec.md#requirement-the-cascade-evidence-is-findable-and-lasts-as-long-as-the-recovery-window
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable(self::TABLE) === false) {
			return $schema;
		}

		$table = $schema->getTable(self::TABLE);
		if ($table->hasColumn(self::COLUMN) === false) {
			$table->addColumn(self::COLUMN, Types::STRING, ['notnull' => false, 'length' => 36]);
			$output->info('Added column '.self::COLUMN.' to '.self::TABLE);
		}

		if ($table->hasIndex(self::INDEX) === false && $table->hasColumn('action') === true) {
			$table->addIndex([self::COLUMN, 'action'], self::INDEX);
			$output->info('Added index '.self::INDEX.' on '.self::TABLE.' (trigger_object, action)');
		}

		return $schema;
	}//end changeSchema()
}//end class
