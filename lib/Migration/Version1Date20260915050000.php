<?php

/**
 * Import preview and conflict policy — the preview record and its row outcomes.
 *
 * Creates two tables backing the previewed import:
 *
 *  - openregister_import_previews: one row per previewed import (target
 *    register and schema, the declared conflict policy and match key, the
 *    source file's name, format and hash, the state machine, the four counts
 *    and a summary report).
 *  - openregister_import_preview_rows: the per-row decision, so a preview row
 *    stays small whether the file holds twelve rows or twelve thousand, and so
 *    the commit can apply exactly the decisions the preview made.
 *
 * Idempotent: each table is created only when absent.
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
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create the import preview and import preview row tables.
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */
class Version1Date20260915050000 extends SimpleMigrationStep {
	/**
	 * Change the database schema.
	 *
	 * @param IOutput $output Output for the migration process.
	 * @param Closure $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		if ($schema->hasTable('openregister_import_previews') === false) {
			$table = $schema->createTable('openregister_import_previews');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
			$table->addColumn('register_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('schema_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('pack_slug', Types::STRING, ['notnull' => false, 'length' => 128]);
			$table->addColumn('policy', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'upsert']);
			$table->addColumn('match_key', Types::TEXT, ['notnull' => false]);
			$table->addColumn('source_name', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('source_format', Types::STRING, ['notnull' => false, 'length' => 16]);
			// The identity of the file the decisions describe. A commit that
			// names a different hash is refused, because the decisions no
			// longer describe what is being written (D-1).
			$table->addColumn('source_hash', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('source_path', Types::STRING, ['notnull' => false, 'length' => 512]);
			$table->addColumn('state', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'pending']);
			$table->addColumn('total', Types::INTEGER, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
			$table->addColumn('processed', Types::INTEGER, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
			$table->addColumn('to_create', Types::INTEGER, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
			$table->addColumn('to_update', Types::INTEGER, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
			$table->addColumn('to_skip', Types::INTEGER, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
			$table->addColumn('to_refuse', Types::INTEGER, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
			$table->addColumn('applied', Types::INTEGER, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
			$table->addColumn('failed', Types::INTEGER, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
			$table->addColumn('report', Types::TEXT, ['notnull' => false]);
			$table->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('created', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('updated', Types::DATETIME, ['notnull' => false]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['uuid'], 'idx_or_imprev_uuid');
			$table->addIndex(['created_by', 'state'], 'idx_or_imprev_actor');
			$table->addIndex(['state'], 'idx_or_imprev_state');

			$output->info('Created openregister_import_previews table');
		}//end if

		if ($schema->hasTable('openregister_import_preview_rows') === false) {
			$table = $schema->createTable('openregister_import_preview_rows');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('preview_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('row_number', Types::INTEGER, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
			$table->addColumn('decision', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'create']);
			$table->addColumn('reason', Types::TEXT, ['notnull' => false]);
			$table->addColumn('target_uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
			// Every object the match key hit, not just the one that would be
			// written: a row matching two objects is refused naming both (D-3).
			$table->addColumn('candidates', Types::TEXT, ['notnull' => false]);
			$table->addColumn('payload', Types::TEXT, ['notnull' => false]);
			// The idempotence key. Only a real write stamps it, so a retried
			// commit walks every row and writes only the ones that were not.
			$table->addColumn('applied_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('created', Types::DATETIME, ['notnull' => false]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['preview_id'], 'idx_or_improw_preview');
			$table->addIndex(['preview_id', 'decision'], 'idx_or_improw_decision');
			$table->addUniqueIndex(['preview_id', 'row_number'], 'idx_or_improw_unique');

			$output->info('Created openregister_import_preview_rows table');
		}//end if

		return $schema;
	}//end changeSchema()
}//end class
