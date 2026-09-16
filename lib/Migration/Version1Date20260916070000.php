<?php

/**
 * Administered purposes, and the purpose an audit row was written under.
 *
 * Creates openregister_processing_purposes: one row per administered purpose,
 * each naming the verwerkingsactiviteit it is bound to. A purpose that names
 * no activity is not a purpose anybody can report on, so the binding is the
 * column the resolver refuses on.
 *
 * Adds `purpose` to openregister_audit_trails as the INDEXED, COUNTABLE
 * projection of the purpose a read ran under. The sealed copy lives in
 * `result_summary`, which is inside the canonical JSON; this column is
 * deliberately outside it, because any key added to AuditTrail::jsonSerialize()
 * changes the canonical form of every row ever written (ADR-003 Rule 4, and the
 * same reason `purged_at` sits outside it). The two are written together and a
 * disagreement between them is detectable.
 *
 * Idempotent: the table and the column are created only when absent.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create the administered purpose table and the audit trail's purpose column.
 *
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
 */
class Version1Date20260916070000 extends SimpleMigrationStep {
	/**
	 * Change the database schema.
	 *
	 * @param IOutput $output Output for the migration process.
	 * @param Closure $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		if ($schema->hasTable('openregister_processing_purposes') === false) {
			$table = $schema->createTable('openregister_processing_purposes');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
			// The code a caller names. Short, readable, and the value that
			// travels on the request, so it is what the refusal quotes back.
			$table->addColumn('code', Types::STRING, ['notnull' => true, 'length' => 128]);
			$table->addColumn('name', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('description', Types::TEXT, ['notnull' => false]);
			// The verwerkingsactiviteit this purpose is bound to, as the
			// administrator wrote it (code or uuid), and as resolved. Both are
			// kept: the reference survives a rename, the uuid is what the audit
			// row is attributed to.
			$table->addColumn('activity', Types::STRING, ['notnull' => false, 'length' => 128]);
			$table->addColumn('activity_uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
			$table->addColumn('organisation_id', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'active']);
			$table->addColumn('created', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('updated', Types::DATETIME, ['notnull' => false]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['code'], 'or_purpose_code_uniq');
			$table->addIndex(['uuid'], 'or_purpose_uuid_idx');
			$table->addIndex(['activity_uuid'], 'or_purpose_activity_idx');
			$table->addIndex(['status'], 'or_purpose_status_idx');
		}

		if ($schema->hasTable('openregister_audit_trails') === true) {
			$audit = $schema->getTable('openregister_audit_trails');
			if ($audit->hasColumn('purpose') === false) {
				$audit->addColumn('purpose', Types::STRING, ['notnull' => false, 'length' => 128]);
			}

			// "Countable per purpose" is a reporting query over millions of
			// rows; without the index it is a table scan on the largest table
			// this app has.
			if ($audit->hasIndex('or_audit_purpose_idx') === false) {
				$audit->addIndex(['purpose'], 'or_audit_purpose_idx');
			}
		}

		return $schema;
	}//end changeSchema()
}//end class
