<?php

/**
 * The export runs table.
 *
 * `expires_at` is NULLABLE and indexed, and both facts carry meaning. Null is
 * a run produced to be kept, which the sweep's predicate excludes by itself
 * rather than by a later check. A value is the deadline the run was produced
 * under, read by the sweep and by the listing's expired flag, never inferred
 * from a file's timestamp: that inference is what once made every export in
 * this fleet arrive already expired.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates openregister_export_runs.
 */
class Version1Date20260922123000 extends SimpleMigrationStep {

	/**
	 * The table this step creates.
	 *
	 * @var string
	 */
	private const TABLE = 'openregister_export_runs';

	/**
	 * Change the database schema.
	 *
	 * @param IOutput                   $output        Migration output.
	 * @param Closure(): ISchemaWrapper $schemaClosure The schema.
	 * @param array<string, mixed>      $options       Migration options.
	 *
	 * @return ISchemaWrapper The schema, changed or not.
	 *
	 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		// Idempotent, like every other step here: a re-run on an instance that
		// already has the table must not fail the upgrade.
		if ($schema->hasTable(tableName: self::TABLE) === true) {
			return $schema;
		}

		$table = $schema->createTable(self::TABLE);
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('source', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('profile', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('actor', Types::STRING, ['notnull' => false, 'length' => 64]);
		// `register` and `schema` are reserved words in more than one engine,
		// so the columns carry the `_name` suffix and the entity maps them.
		$table->addColumn('register_name', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('schema_name', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('format', Types::STRING, ['notnull' => false, 'length' => 32]);
		$table->addColumn('filename', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('row_count', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => 0]);
		$table->addColumn('file_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
		$table->addColumn('file_path', Types::STRING, ['notnull' => false, 'length' => 512]);
		$table->addColumn('download_count', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
		// Null means the run was produced to be kept. It is a declaration, and
		// the row says so rather than showing an empty expiry.
		$table->addColumn('retention_seconds', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
		$table->addColumn('status', Types::STRING, ['notnull' => false, 'length' => 32, 'default' => 'available']);
		$table->addColumn('produced_at', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('expires_at', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('created', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('updated', Types::DATETIME, ['notnull' => false]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['uuid'], 'idx_or_exprun_uuid');
		// The area's own listing: one actor, newest first.
		$table->addIndex(['actor', 'produced_at'], 'idx_or_exprun_actor');
		// The sweep's predicate, in the order it narrows: a deadline that has
		// passed, on a run whose file is still there.
		$table->addIndex(['expires_at', 'status'], 'idx_or_exprun_due');

		$output->info('Created ' . self::TABLE);

		return $schema;
	}//end changeSchema()
}//end class
