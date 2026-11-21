<?php

/**
 * The review marks of anonymisation.
 *
 * `openregister_redaction_review_marks` holds one row per check: the file,
 * the fingerprint of the detection run that was checked, who checked it and
 * when.
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
 * @spec openspec/changes/redaction-release-safeguards/tasks.md#task-2.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates the review mark table.
 */
class Version1Date20261114100200 extends SimpleMigrationStep {

	/**
	 * Create `openregister_redaction_review_marks`.
	 *
	 * @param IOutput $output        The migration output.
	 * @param Closure $schemaClosure Returns the schema wrapper.
	 * @param array   $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema.
	 *
	 * @spec openspec/changes/redaction-release-safeguards/tasks.md#task-2.2
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		if ($schema->hasTable('openregister_redaction_review_marks') === false) {
			$table = $schema->createTable('openregister_redaction_review_marks');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('file_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('run_fingerprint', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('checked_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('checked_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['file_id'], 'idx_or_review_mark_file');
			$output->info('Created openregister_redaction_review_marks table');
		}

		return $schema;

	}//end changeSchema()
}//end class
