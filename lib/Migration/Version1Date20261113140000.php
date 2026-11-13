<?php

/**
 * The text disclosure table and the per-step outcomes of an anonymisation run.
 *
 * `openregister_text_disclosures` holds one row per external call that
 * carried a document's text (service, host, moment, byte count; never the
 * text). `openregister_anonymisation_log.steps` holds, per run, the outcome
 * of every content-altering step as JSON.
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
 * @spec openspec/changes/anonymisation-discloses-itself-pipeline/specs/anonymisation-disclosure/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create the text disclosure table and the anonymisation log's steps column.
 *
 * @spec openspec/changes/anonymisation-discloses-itself-pipeline/specs/anonymisation-disclosure/spec.md
 */
class Version1Date20261113140000 extends SimpleMigrationStep {

	/**
	 * Change the database schema.
	 *
	 * @param IOutput                 $output        Output for the migration process.
	 * @param Closure                 $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema.
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself-pipeline/specs/anonymisation-disclosure/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		if ($schema->hasTable('openregister_text_disclosures') === false) {
			$table = $schema->createTable('openregister_text_disclosures');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('file_id', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('source_type', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->addColumn('source_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('service', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('host', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('disclosed_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('bytes', Types::BIGINT, ['notnull' => true, 'default' => 0]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['file_id'], 'idx_or_txtdisc_file');
			$table->addIndex(['source_type', 'source_id'], 'idx_or_txtdisc_source');

			$output->info('Created openregister_text_disclosures table');
		}

		if ($schema->hasTable('openregister_anonymisation_log') === true) {
			$log = $schema->getTable('openregister_anonymisation_log');
			if ($log->hasColumn('steps') === false) {
				$log->addColumn('steps', Types::TEXT, ['notnull' => false]);
				$output->info('Added steps to openregister_anonymisation_log');
			}
		}

		return $schema;

	}//end changeSchema()
}//end class
