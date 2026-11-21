<?php

/**
 * Create the `openregister_id_scheme_runs` table.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;

/**
 * Create the `openregister_id_scheme_runs` table.
 *
 * `active_sequence` carries the sequence name only while a run runs; its
 * unique index lets one run per sequence exist at a time (NULLs do not
 * collide on either database).
 *
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-second-human-identifier-reserved-values-and-a-scheme-change-as-a-migration-req-gid-006
 */
class Version1Date20261114181600 extends TargetedMigrationStep {
	/**
	 * Change the database schema.
	 *
	 * @param IOutput                 $output        Output for the migration process.
	 * @param Closure                 $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The updated schema, or null when no change.
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-second-human-identifier-reserved-values-and-a-scheme-change-as-a-migration-req-gid-006
	 */
	protected function changeTargetedSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		if ($schema->hasTable('openregister_id_scheme_runs') === true) {
			return $schema;
		}

		$table = $schema->createTable('openregister_id_scheme_runs');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('sequence', Types::STRING, ['notnull' => true, 'length' => 128]);
		$table->addColumn('active_sequence', Types::STRING, ['notnull' => false, 'length' => 128]);
		$table->addColumn('register_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('schema_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('property', Types::STRING, ['notnull' => true, 'length' => 255]);
		$table->addColumn('previous_format', Types::STRING, ['notnull' => true, 'length' => 255]);
		$table->addColumn('state', Types::STRING, ['notnull' => true, 'length' => 16]);
		foreach (['total', 'processed', 'rewritten', 'skipped', 'failed'] as $counter) {
			$table->addColumn($counter, Types::INTEGER, ['notnull' => true, 'default' => 0]);
		}

		$table->addColumn('started_by', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('started', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('finished', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('last_error', Types::TEXT, ['notnull' => false]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['active_sequence'], 'idx_or_idsr_active');
		$table->addIndex(['sequence'], 'idx_or_idsr_sequence');

		$output->info('Created openregister_id_scheme_runs table');

		return $schema;
	}//end changeTargetedSchema()
}//end class
