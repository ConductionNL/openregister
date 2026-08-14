<?php

/**
 * Tier-2 photos (NC Photos) integration migration.
 *
 * Ensures the `openregister_photo_links` table exists with the full
 * Tier-2 schema:
 *
 *  - id (bigint, PK, autoincrement)
 *  - object_uuid (string 36, not null, indexed)
 *  - register_id (bigint unsigned, not null, indexed)
 *  - schema_id (bigint unsigned, nullable, indexed)
 *  - album_id (bigint unsigned, not null) — `photos_albums` row id
 *  - album_name (string 255, not null) — cached album title
 *  - cover_photo_url (string 512, nullable) — cached cover thumbnail href
 *  - photo_count (integer, nullable) — cached photo count
 *  - last_edited (datetime, nullable) — cached album last-added timestamp
 *  - linked_by (string 64, not null)
 *  - linked_at (datetime, not null)
 *
 * Idempotent: creates the table if missing, otherwise adds any missing
 * Tier-2 columns. Companion to the Tier-2 talk/polls/email/forms/deck/flow
 * link tables; the wrapping `PhotoLinkService` replaces the `[or:{uuid}]`
 * album-name marker convention used by the Tier-1 `PhotosProvider` with a
 * proper persistence layer so links survive album renames.
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
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Tier-2 photo-links table — create-or-extend.
 */
class Version1Date20260525170000 extends SimpleMigrationStep {
	/**
	 * Change the database schema.
	 *
	 * @param IOutput $output Output for the migration process
	 * @param Closure $schemaClosure The schema closure
	 * @param array<array-key, mixed> $options Migration options
	 *
	 * @return ISchemaWrapper|null
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();
		$changed = false;

		if ($schema->hasTable('openregister_photo_links') === false) {
			$this->createPhotoLinksTable(schema: $schema, output: $output);
			$changed = true;
		}

		if ($schema->hasTable('openregister_photo_links') === true
			&& $this->extendPhotoLinksTable(schema: $schema, output: $output) === true
		) {
			$changed = true;
		}

		if ($changed === false) {
			return null;
		}

		return $schema;
	}//end changeSchema()

	/**
	 * Create the openregister_photo_links table at the Tier-2 shape.
	 *
	 * @param ISchemaWrapper $schema The schema wrapper
	 * @param IOutput $output Migration output
	 *
	 * @return void
	 */
	private function createPhotoLinksTable(ISchemaWrapper $schema, IOutput $output): void {
		$table = $schema->createTable('openregister_photo_links');

		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('object_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('register_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('schema_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
		$table->addColumn('album_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('album_name', Types::STRING, ['notnull' => true, 'length' => 255]);
		$table->addColumn('cover_photo_url', Types::STRING, ['notnull' => false, 'length' => 512, 'default' => null]);
		$table->addColumn('photo_count', Types::INTEGER, ['notnull' => false, 'default' => null]);
		$table->addColumn('last_edited', Types::DATETIME, ['notnull' => false, 'default' => null]);
		$table->addColumn('linked_by', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('linked_at', Types::DATETIME, ['notnull' => true]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['object_uuid', 'album_id'], 'idx_photo_object_album');
		$table->addIndex(['object_uuid'], 'idx_photo_object');
		$table->addIndex(['register_id'], 'idx_photo_register');
		$table->addIndex(['schema_id'], 'idx_photo_schema');

		$output->info('Created openregister_photo_links table (Tier-2 schema)');
	}//end createPhotoLinksTable()

	/**
	 * Add any missing Tier-2 columns to an existing photo_links table.
	 *
	 * @param ISchemaWrapper $schema The schema wrapper
	 * @param IOutput $output Migration output
	 *
	 * @return bool True when a column was added.
	 */
	private function extendPhotoLinksTable(ISchemaWrapper $schema, IOutput $output): bool {
		$table = $schema->getTable('openregister_photo_links');
		$changed = false;

		if ($table->hasColumn('schema_id') === false) {
			$table->addColumn(
				'schema_id',
				Types::BIGINT,
				['notnull' => false, 'unsigned' => true, 'default' => null]
			);
			$table->addIndex(['schema_id'], 'idx_photo_schema');
			$output->info('Added schema_id column to openregister_photo_links');
			$changed = true;
		}

		if ($table->hasColumn('cover_photo_url') === false) {
			$table->addColumn('cover_photo_url', Types::STRING, ['notnull' => false, 'length' => 512, 'default' => null]);
			$output->info('Added cover_photo_url column to openregister_photo_links');
			$changed = true;
		}

		if ($table->hasColumn('photo_count') === false) {
			$table->addColumn('photo_count', Types::INTEGER, ['notnull' => false, 'default' => null]);
			$output->info('Added photo_count column to openregister_photo_links');
			$changed = true;
		}

		if ($table->hasColumn('last_edited') === false) {
			$table->addColumn('last_edited', Types::DATETIME, ['notnull' => false, 'default' => null]);
			$output->info('Added last_edited column to openregister_photo_links');
			$changed = true;
		}

		return $changed;
	}//end extendPhotoLinksTable()
}//end class
