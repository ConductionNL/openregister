<?php

/**
 * Schema change notices — the follower table and the notice record on the changelog.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md#requirement-a-reader-may-follow-a-schemas-breaking-changes
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create `openregister_schema_followers` (unique on schema and user) and add
 * `notice_sent_to` and `notice_sent_at` to `openregister_schema_changelog`.
 *
 * Idempotent: each table and column is added only when absent.
 *
 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md#requirement-a-reader-may-follow-a-schemas-breaking-changes
 */
class Version1Date20261113160000 extends SimpleMigrationStep {

	/**
	 * Change the database schema.
	 *
	 * @param IOutput                 $output        Output for the migration process.
	 * @param Closure                 $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The schema, changed or not.
	 *
	 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md#requirement-a-reader-may-follow-a-schemas-breaking-changes
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */
		$schema = $schemaClosure();

		if ($schema->hasTable('openregister_schema_followers') === false) {
			$table = $schema->createTable('openregister_schema_followers');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('schema_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('created', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['schema_id', 'uid'], 'or_schema_follower_uniq');
			$output->info('Created openregister_schema_followers');
		}

		if ($schema->hasTable('openregister_schema_changelog') === true) {
			$changelog = $schema->getTable('openregister_schema_changelog');
			if ($changelog->hasColumn('notice_sent_to') === false) {
				$changelog->addColumn('notice_sent_to', Types::INTEGER, ['notnull' => false, 'unsigned' => true]);
			}

			if ($changelog->hasColumn('notice_sent_at') === false) {
				$changelog->addColumn('notice_sent_at', Types::DATETIME, ['notnull' => false]);
			}
		}

		return $schema;
	}//end changeSchema()
}//end class
