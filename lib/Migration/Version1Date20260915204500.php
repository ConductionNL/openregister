<?php

/**
 * Subject exports — a data subject's own copy, its lifecycle and its expiry.
 *
 * Creates openregister_subject_exports: one row per request for everything the
 * instance holds about one person. The row is the request, not the file. The
 * bytes are re-assembled under the requester's own scope at download time and
 * never written to rest, so what the table keeps is who asked, about whom,
 * when it became ready, what it contained and when the delivery stops working.
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
 * Create the subject export table.
 *
 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/gdpr-data-subject-rights/spec.md
 */
class Version1Date20260915204500 extends SimpleMigrationStep {
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

		if ($schema->hasTable('openregister_subject_exports') === false) {
			$table = $schema->createTable('openregister_subject_exports');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
			$table->addColumn('subject', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('subject_type', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'pending']);
			$table->addColumn('requested_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('request_id', Types::STRING, ['notnull' => false, 'length' => 128]);
			$table->addColumn('object_count', Types::INTEGER, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
			$table->addColumn('content_hash', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('error', Types::TEXT, ['notnull' => false]);
			$table->addColumn('ready_at', Types::DATETIME, ['notnull' => false]);
			// The moment the delivery stops working. Not decoration: the
			// download asks this column before it assembles anything.
			$table->addColumn('expires_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('delivered_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('created', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('updated', Types::DATETIME, ['notnull' => false]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['uuid'], 'idx_or_subjexp_uuid');
			$table->addIndex(['subject'], 'idx_or_subjexp_subject');
			$table->addIndex(['requested_by', 'status'], 'idx_or_subjexp_actor');
			$table->addIndex(['status', 'expires_at'], 'idx_or_subjexp_expiry');

			$output->info('Created openregister_subject_exports table');
		}//end if

		return $schema;
	}//end changeSchema()
}//end class
