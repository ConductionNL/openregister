<?php

/**
 * An export profile is an object, and a scheduled report may name one.
 *
 * Creates `openregister_export_profiles`: a named, ordered field set with a
 * value mode, a format and an optional filter, bound to a register and a
 * schema. A field set that lives in a URL cannot be reviewed, cannot be shared
 * and changes whenever somebody edits a screen; as a row it has an owner, a
 * name and a history an administrator can point at (design D-3).
 *
 * Also adds `profile_id` to `openregister_scheduled_reports`, so the recurring
 * runner that already exists can run a profile instead of a format plus a
 * filter map. Nullable, because every existing schedule keeps working exactly
 * as it is.
 *
 * Idempotent: the table is created only when absent, the column added only
 * when missing.
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
 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create the export profile table and let a schedule name a profile.
 *
 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
 */
class Version1Date20260915203000 extends SimpleMigrationStep {
	/**
	 * Change the database schema.
	 *
	 * @param IOutput $output Output for the migration process.
	 * @param Closure $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema.
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		$this->profiles(schema: $schema);
		$this->scheduleProfileLink(schema: $schema);

		return $schema;
	}//end changeSchema()

	/**
	 * The export profile table.
	 *
	 * @param ISchemaWrapper $schema The schema being changed.
	 *
	 * @return void
	 */
	private function profiles(ISchemaWrapper $schema): void {
		if ($schema->hasTable('openregister_export_profiles') === true) {
			return;
		}

		$table = $schema->createTable('openregister_export_profiles');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
		$table->addColumn('owner', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('name', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('description', Types::TEXT, ['notnull' => false]);
		$table->addColumn('register_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
		// Null on a whole-set profile, which is exactly the profile that spans
		// every schema of the register (design D-5).
		$table->addColumn('schema_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
		// The ORDERED field set, as a JSON list. Order is the point: the
		// monthly aanlevering is a fixed shape, not whatever the screen shows.
		$table->addColumn('fields', Types::TEXT, ['notnull' => false]);
		$table->addColumn('value_mode', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'stored']);
		$table->addColumn('format', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'csv']);
		$table->addColumn('filters', Types::TEXT, ['notnull' => false]);
		$table->addColumn('whole_set', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
		$table->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('updated_at', Types::DATETIME, ['notnull' => false]);

		$table->setPrimaryKey(['id']);
		$table->addIndex(['owner'], 'or_exp_prof_owner');
		$table->addIndex(['register_id', 'schema_id'], 'or_exp_prof_scope');
		$table->addUniqueIndex(['uuid'], 'or_exp_prof_uuid');
	}//end profiles()

	/**
	 * The link from a scheduled report to a profile.
	 *
	 * @param ISchemaWrapper $schema The schema being changed.
	 *
	 * @return void
	 */
	private function scheduleProfileLink(ISchemaWrapper $schema): void {
		if ($schema->hasTable('openregister_scheduled_reports') === false) {
			return;
		}

		$table = $schema->getTable('openregister_scheduled_reports');
		if ($table->hasColumn('profile_id') === true) {
			return;
		}

		$table->addColumn('profile_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
	}//end scheduleProfileLink()
}//end class
