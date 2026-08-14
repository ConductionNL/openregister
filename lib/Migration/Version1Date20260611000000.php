<?php

/**
 * Anonymisation log table migration.
 *
 * Adds the `openregister_anonymisation_log` table that records anonymisation
 * runs against `OCP\Files\File` nodes. Each row captures the input file id,
 * the originating Object UUID (when applicable), the per-format sanitisation
 * report (as JSON), the engine used, and basic timing data.
 *
 * The `sanitization` column is the JSON payload produced by
 * {@see \OCA\OpenRegister\Service\File\SanitizationReport::jsonSerialize}. It
 * is NULL for non-Office anonymisations (PDF, plain text) and pre-sanitisation
 * legacy runs.
 *
 *  - id (bigint, PK, autoincrement)
 *  - file_id (bigint, not null, indexed) — NC file id of the source
 *  - object_uuid (string 36, nullable, indexed) — register object that owned the file
 *  - register_id (bigint unsigned, nullable, indexed)
 *  - schema_id (bigint unsigned, nullable, indexed)
 *  - mime_type (string 191, not null) — observed input MIME
 *  - engine (string 64, not null) — sanitiser/anonymiser class name
 *  - status (string 32, not null) — `success` | `failure`
 *  - reason (string 64, nullable) — failure reason code (e.g. `encrypted`)
 *  - sanitization (text/JSON, nullable) — serialised SanitizationReport
 *  - replacements (integer, not null, default 0) — distinct replacements applied
 *  - duration_ms (integer, nullable) — wall-clock duration of the run
 *  - created (datetime, not null)
 *
 * Idempotent: creates the table if missing.
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
 * @spec openspec/specs/office-document-sanitization/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create the openregister_anonymisation_log table.
 */
class Version1Date20260611000000 extends SimpleMigrationStep {
	/**
	 * Change the database schema.
	 *
	 * @param IOutput $output Output for the migration process
	 * @param Closure $schemaClosure The schema closure
	 * @param array<array-key, mixed> $options Migration options
	 *
	 * @return ISchemaWrapper|null
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		if ($schema->hasTable('openregister_anonymisation_log') === true) {
			return null;
		}

		$table = $schema->createTable('openregister_anonymisation_log');

		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('file_id', Types::BIGINT, ['notnull' => true]);
		$table->addColumn('object_uuid', Types::STRING, ['notnull' => false, 'length' => 36, 'default' => null]);
		$table->addColumn('register_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
		$table->addColumn('schema_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
		$table->addColumn('mime_type', Types::STRING, ['notnull' => true, 'length' => 191, 'default' => '']);
		$table->addColumn('engine', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
		$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'success']);
		$table->addColumn('reason', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
		$table->addColumn('sanitization', Types::TEXT, ['notnull' => false, 'default' => null]);
		$table->addColumn('replacements', Types::INTEGER, ['notnull' => true, 'default' => 0]);
		$table->addColumn('duration_ms', Types::INTEGER, ['notnull' => false, 'default' => null]);
		$table->addColumn('created', Types::DATETIME, ['notnull' => true]);

		$table->setPrimaryKey(['id']);
		$table->addIndex(['file_id'], 'idx_anonlog_file');
		$table->addIndex(['object_uuid'], 'idx_anonlog_object');
		$table->addIndex(['register_id'], 'idx_anonlog_register');
		$table->addIndex(['schema_id'], 'idx_anonlog_schema');
		$table->addIndex(['created'], 'idx_anonlog_created');

		$output->info('Created openregister_anonymisation_log table');

		return $schema;
	}//end changeSchema()
}//end class
