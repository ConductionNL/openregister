<?php

/**
 * Published file digests: the table that records what a published file was.
 *
 * One row per publication of a file: its SHA-256, the number of bytes it
 * covers, the etag at that moment, when it became public, when that stopped,
 * and the `file.published` audit row that seals it. Publishing again adds a
 * row; nothing is overwritten.
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
 * @spec openspec/changes/published-file-digest/specs/file-actions/spec.md#requirement-a-files-digest-is-recorded-and-sealed-when-it-becomes-public-req-pfd-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create the published file digest table.
 *
 * @spec openspec/changes/published-file-digest/specs/file-actions/spec.md#requirement-a-files-digest-is-recorded-and-sealed-when-it-becomes-public-req-pfd-001
 */
class Version1Date20261113100000 extends SimpleMigrationStep {

	/**
	 * Change the database schema.
	 *
	 * @param IOutput                 $output        Output for the migration process.
	 * @param Closure                 $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema.
	 *
	 * @spec openspec/changes/published-file-digest/specs/file-actions/spec.md#requirement-a-files-digest-is-recorded-and-sealed-when-it-becomes-public-req-pfd-001
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		if ($schema->hasTable('openregister_file_digests') === true) {
			return $schema;
		}

		$table = $schema->createTable('openregister_file_digests');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('file_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('object_uuid', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('sha256', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('size', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('etag', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('published_at', Types::DATETIME, ['notnull' => true]);
		$table->addColumn('unpublished_at', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('audit_trail_uuid', Types::STRING, ['notnull' => false, 'length' => 36]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['uuid'], 'idx_or_fdigest_uuid');
		$table->addIndex(['file_id', 'unpublished_at'], 'idx_or_fdigest_file_open');
		$table->addIndex(['object_uuid'], 'idx_or_fdigest_object');

		$output->info('Created openregister_file_digests table');

		return $schema;

	}//end changeSchema()
}//end class
