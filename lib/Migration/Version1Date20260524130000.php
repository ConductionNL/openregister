<?php

/**
 * Tier-2 form-link migration: create `openregister_form_links` table.
 *
 * Backs the Tier-2 forms integration leaf — promotes the FormsProvider
 * from the marker-only LIKE lookup against `forms_v2_forms.title` to a
 * first-class link table so:
 *
 *   * Form-level and submission-level links can both live in one
 *     table, distinguished by `submission_id` (nullable);
 *   * `linked_by` / `linked_at` audit trail is captured outside the
 *     upstream Forms tables (which OR doesn't own);
 *   * Form metadata cached at link time (`form_hash`, `title`,
 *     `status`, `expires_at`) is preserved even if NC Forms goes
 *     away or the form is archived (graceful-degradation per AD-23).
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use Doctrine\DBAL\Schema\Table;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create the openregister_form_links table for Tier-2 forms integration.
 *
 * @package OCA\OpenRegister\Migration
 */
class Version1Date20260524130000 extends SimpleMigrationStep {
	/**
	 * Change the database schema.
	 *
	 * Idempotent: only creates the table if absent.
	 *
	 * @param IOutput $output Migration output
	 * @param Closure $schemaClosure Schema closure
	 * @param array $options Migration options
	 *
	 * @return ISchemaWrapper|null The updated schema or null if no changes
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		if ($schema->hasTable(tableName: 'openregister_form_links') === true) {
			return null;
		}

		$table = $schema->createTable(tableName: 'openregister_form_links');

		$this->addColumns(table: $table);
		$this->addIndexes(table: $table);

		$output->info(message: 'Created openregister_form_links table');

		return $schema;
	}//end changeSchema()

	/**
	 * Declare every column of `openregister_form_links`.
	 *
	 * The columns are driven from a spec list rather than written out as one
	 * `addColumn()` call per column: the literal form ran to 105 lines and
	 * tripped PHPMD's ExcessiveMethodLength. The order and the arguments are
	 * unchanged.
	 *
	 * Note `submission_id` is nullable — a row with submission_id NULL is a
	 * form-level link; a row with a submission_id is a per-submission link.
	 * The composite unique index in addIndexes() allows both shapes for the
	 * same (object, form) pair.
	 *
	 * @param Table $table The table being created
	 *
	 * @return void
	 */
	private function addColumns(Table $table): void {
		$columns = [
			['id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]],
			['object_uuid', Types::STRING, ['notnull' => true, 'length' => 36]],
			['register_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]],
			['schema_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]],
			['form_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]],
			['form_hash', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]],
			['submission_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]],
			['title', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]],
			['status', Types::STRING, ['notnull' => false, 'length' => 32, 'default' => null]],
			['expires_at', Types::DATETIME, ['notnull' => false, 'default' => null]],
			['linked_by', Types::STRING, ['notnull' => true, 'length' => 64]],
			['linked_at', Types::DATETIME, ['notnull' => true]],
		];

		foreach ($columns as [$columnName, $columnType, $columnOptions]) {
			$table->addColumn($columnName, $columnType, $columnOptions);
		}

	}//end addColumns()

	/**
	 * Declare the primary key and every index of `openregister_form_links`.
	 *
	 * @param Table $table The table being created
	 *
	 * @return void
	 */
	private function addIndexes(Table $table): void {
		$table->setPrimaryKey(['id']);
		$table->addIndex(['object_uuid'], 'or_form_links_object_idx');
		$table->addIndex(['form_id'], 'or_form_links_form_idx');
		$table->addIndex(['register_id'], 'or_form_links_register_idx');

		// Composite unique key: (object, form, submission). Allows one
		// form-level link (submission_id NULL) plus N per-submission
		// links for the same form attached to the same object.
		$table->addUniqueIndex(
			['object_uuid', 'form_id', 'submission_id'],
			'or_form_links_unique_idx'
		);

	}//end addIndexes()
}//end class
