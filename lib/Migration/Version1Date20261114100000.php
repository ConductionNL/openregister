<?php

/**
 * Document store moves: the mapping from each moved node to its copy.
 *
 * One row per file, folder or earlier version copied by
 * `occ openregister:document-store:move`: the old node id, the new one, the
 * SHA-256 both were checked against, and how far the row got (copied,
 * verified, switched or refused, with the reason). A rerun continues from these
 * rows, and `--purge-source` deletes only files whose row is switched.
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
 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create the document store move table.
 *
 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
 */
class Version1Date20261114100000 extends SimpleMigrationStep {

	/**
	 * Change the database schema.
	 *
	 * @param IOutput                 $output        Output for the migration process.
	 * @param Closure                 $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema.
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		if ($schema->hasTable('openregister_document_moves') === true) {
			return $schema;
		}

		$table = $schema->createTable('openregister_document_moves');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('register_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('old_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('new_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
		$table->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 16]);
		$table->addColumn('version_time', Types::BIGINT, ['notnull' => false]);
		$table->addColumn('sha256', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('state', Types::STRING, ['notnull' => true, 'length' => 16]);
		$table->addColumn('reason', Types::STRING, ['notnull' => false, 'length' => 1024]);
		$table->addColumn('moved_at', Types::DATETIME, ['notnull' => true]);

		$table->setPrimaryKey(['id']);
		$table->addIndex(['register_id', 'state'], 'idx_or_docmove_reg_state');
		$table->addIndex(['old_id'], 'idx_or_docmove_old');

		$output->info('Created openregister_document_moves table');

		return $schema;

	}//end changeSchema()
}//end class
