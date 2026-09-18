<?php

/**
 * Reported content, and the copy taken when the report was filed.
 *
 * Creates openregister_content_reports: one row per report, carrying the
 * frozen content, its checksum, the reviewer group pinned at filing time, and
 * the copy's OWN expiry.
 *
 * The expiry is the column the requirement turns on. The copy exists so that
 * removing the content does not destroy the evidence, so it cannot inherit the
 * content's retention: the sweep that deletes the content would otherwise take
 * the evidence of it in the same pass.
 *
 * `object_uuid` is a uuid rather than a foreign key on purpose. This row
 * outlives the object it describes by design, and a foreign key would either
 * refuse the delete or cascade the copy away with it.
 *
 * Idempotent: the table is created only when absent.
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
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create the content report table.
 *
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
 */
class Version1Date20260916230800 extends SimpleMigrationStep {
	/**
	 * Change the database schema.
	 *
	 * @param IOutput $output Output for the migration process.
	 * @param Closure $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		if ($schema->hasTable('openregister_content_reports') === true) {
			return null;
		}

		$table = $schema->createTable('openregister_content_reports');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
		$table->addColumn('object_uuid', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('register', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('schema', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('reason', Types::TEXT, ['notnull' => false]);
		$table->addColumn('reported_by', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'open']);
		// The frozen content. TEXT rather than a shorter type: it holds an
		// object's own fields, and a copy truncated at 65k is evidence of part
		// of what was said.
		$table->addColumn('copy', Types::TEXT, ['notnull' => false]);
		$table->addColumn('copy_hash', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('reviewer_group', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('retention_period', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('expires', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('removed_at', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('removal_audit', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('organisation_id', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('created', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('updated', Types::DATETIME, ['notnull' => false]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['uuid'], 'or_creport_uuid_uniq');
		// The lookup a removal makes, on the uuid of an object that no longer
		// exists.
		$table->addIndex(['object_uuid'], 'or_creport_object_idx');
		$table->addIndex(['status'], 'or_creport_status_idx');
		$table->addIndex(['expires'], 'or_creport_expires_idx');

		return $schema;
	}//end changeSchema()
}//end class
