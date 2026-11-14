<?php

/**
 * Create the text signature table.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-every-extracted-file-carries-a-text-signature-req-ndt-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create `openregister_text_signatures`: one MinHash signature per extracted
 * file, its shingle count and the SHA-256 of its normalised text. Idempotent:
 * created only when absent.
 *
 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-every-extracted-file-carries-a-text-signature-req-ndt-001
 */
class Version1Date20261113200000 extends SimpleMigrationStep {

	/**
	 * Change the database schema.
	 *
	 * @param IOutput                 $output        Output for the migration process.
	 * @param Closure                 $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema.
	 *
	 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-every-extracted-file-carries-a-text-signature-req-ndt-001
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		if ($schema->hasTable('openregister_text_signatures') === true) {
			return $schema;
		}

		$table = $schema->createTable('openregister_text_signatures');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('file_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('signature', Types::TEXT, ['notnull' => false]);
		$table->addColumn('shingle_count', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
		$table->addColumn('text_sha256', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('created', Types::DATETIME, ['notnull' => true]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['file_id'], 'idx_or_tsig_file');

		$output->info('Created openregister_text_signatures table');

		return $schema;

	}//end changeSchema()
}//end class
