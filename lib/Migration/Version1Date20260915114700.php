<?php

/**
 * Erasure previews — the recorded answer an erasure runs from.
 *
 * Creates openregister_erasure_previews: one row per preview of an AVG erasure,
 * holding the subject it was taken for, the mode it was computed under, the
 * digest of what it said, the report verbatim, and the approval that lets an
 * erasure run from it. Without the row an erasure has nothing to be approved
 * against, and "approved" would mean whatever the caller last claimed.
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
 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/gdpr-data-subject-rights/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create the erasure preview table.
 *
 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/gdpr-data-subject-rights/spec.md
 */
class Version1Date20260915114700 extends SimpleMigrationStep {
	/**
	 * Change the database schema.
	 *
	 * @param IOutput $output Output for the migration process.
	 * @param Closure $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema.
	 *
	 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/gdpr-data-subject-rights/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		if ($schema->hasTable('openregister_erasure_previews') === false) {
			$table = $schema->createTable('openregister_erasure_previews');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
			$table->addColumn('subject', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('subject_type', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('erase_mode', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'pseudonymise']);
			$table->addColumn('request_id', Types::STRING, ['notnull' => false, 'length' => 128]);
			// The fingerprint of what the preview SAID, so an approval given for
			// one reading of the world cannot be spent on another.
			$table->addColumn('digest', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('report', Types::TEXT, ['notnull' => false]);
			$table->addColumn('outcome', Types::TEXT, ['notnull' => false]);
			$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'pending']);
			$table->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('approved_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('approved_at', Types::DATETIME, ['notnull' => false]);
			// The idempotence key of the run. Only a real erasure stamps it, so a
			// second run refuses on the stamp rather than on the status alone.
			$table->addColumn('consumed_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('created', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('updated', Types::DATETIME, ['notnull' => false]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['uuid'], 'idx_or_erasprev_uuid');
			$table->addIndex(['subject'], 'idx_or_erasprev_subject');
			$table->addIndex(['status'], 'idx_or_erasprev_status');
			$table->addIndex(['created_by', 'status'], 'idx_or_erasprev_actor');
			$table->addIndex(['request_id'], 'idx_or_erasprev_request');

			$output->info('Created openregister_erasure_previews table');
		}//end if

		return $schema;
	}//end changeSchema()
}//end class
