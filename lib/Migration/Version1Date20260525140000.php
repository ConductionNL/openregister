<?php

/**
 * Tier-2 bookmarks integration migration.
 *
 * Ensures the `openregister_bookmark_links` table exists with the full
 * Tier-2 schema:
 *
 *  - id (bigint, PK, autoincrement)
 *  - object_uuid (string 36, not null)
 *  - register_id (bigint unsigned, not null)
 *  - schema_id (bigint unsigned, nullable)
 *  - bookmark_id (bigint unsigned, not null)
 *  - title (string 512, nullable)
 *  - url (text, nullable)
 *  - description (text, nullable)
 *  - tags (json, nullable)
 *  - added_at (datetime, nullable)
 *  - linked_by (string 64, not null)
 *  - linked_at (datetime, not null)
 *
 * Composite unique on (object_uuid, bookmark_id). Idempotent: creates the
 * table if missing, otherwise adds any missing Tier-2 columns. Companion
 * to the Tier-2 deck/forms/poll link tables; the wrapping
 * `BookmarkLinkService` replaces the `or:{uuid}` tag-marker convention
 * from the original BookmarksProvider with a proper persistence layer so
 * links survive Bookmarks tag edits and don't pollute Bookmarks' UX.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
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
 * Tier-2 bookmark-links table — create-or-extend.
 */
class Version1Date20260525140000 extends SimpleMigrationStep {
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

		if ($schema->hasTable('openregister_bookmark_links') === false) {
			$this->createBookmarkLinksTable(schema: $schema, output: $output);
			$changed = true;
		}

		if ($schema->hasTable('openregister_bookmark_links') === true
			&& $this->extendBookmarkLinksTable(schema: $schema, output: $output) === true
		) {
			$changed = true;
		}

		if ($changed === false) {
			return null;
		}

		return $schema;
	}//end changeSchema()

	/**
	 * Create the openregister_bookmark_links table at the Tier-2 shape.
	 *
	 * @param ISchemaWrapper $schema The schema wrapper
	 * @param IOutput $output Migration output
	 *
	 * @return void
	 */
	private function createBookmarkLinksTable(ISchemaWrapper $schema, IOutput $output): void {
		$table = $schema->createTable('openregister_bookmark_links');

		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('object_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('register_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('schema_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
		$table->addColumn('bookmark_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('title', Types::STRING, ['notnull' => false, 'length' => 512, 'default' => null]);
		$table->addColumn('url', Types::TEXT, ['notnull' => false, 'default' => null]);
		$table->addColumn('description', Types::TEXT, ['notnull' => false, 'default' => null]);
		$table->addColumn('tags', Types::JSON, ['notnull' => false, 'default' => null]);
		$table->addColumn('added_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
		$table->addColumn('linked_by', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('linked_at', Types::DATETIME, ['notnull' => true]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['object_uuid', 'bookmark_id'], 'idx_bm_object_bm');
		$table->addIndex(['object_uuid'], 'idx_bm_object');
		$table->addIndex(['register_id'], 'idx_bm_register');
		$table->addIndex(['schema_id'], 'idx_bm_schema');
		$table->addIndex(['bookmark_id'], 'idx_bm_bookmark');

		$output->info('Created openregister_bookmark_links table (Tier-2 schema)');
	}//end createBookmarkLinksTable()

	/**
	 * Add any missing Tier-2 columns to an existing bookmark_links table.
	 *
	 * @param ISchemaWrapper $schema The schema wrapper
	 * @param IOutput $output Migration output
	 *
	 * @return bool True when a column was added.
	 */
	private function extendBookmarkLinksTable(ISchemaWrapper $schema, IOutput $output): bool {
		$table = $schema->getTable('openregister_bookmark_links');
		$changed = false;

		if ($table->hasColumn('schema_id') === false) {
			$table->addColumn(
				'schema_id',
				Types::BIGINT,
				['notnull' => false, 'unsigned' => true, 'default' => null]
			);
			$table->addIndex(['schema_id'], 'idx_bm_schema');
			$output->info('Added schema_id column to openregister_bookmark_links');
			$changed = true;
		}

		if ($table->hasColumn('title') === false) {
			$table->addColumn('title', Types::STRING, ['notnull' => false, 'length' => 512, 'default' => null]);
			$output->info('Added title column to openregister_bookmark_links');
			$changed = true;
		}

		if ($table->hasColumn('url') === false) {
			$table->addColumn('url', Types::TEXT, ['notnull' => false, 'default' => null]);
			$output->info('Added url column to openregister_bookmark_links');
			$changed = true;
		}

		if ($table->hasColumn('description') === false) {
			$table->addColumn('description', Types::TEXT, ['notnull' => false, 'default' => null]);
			$output->info('Added description column to openregister_bookmark_links');
			$changed = true;
		}

		if ($table->hasColumn('tags') === false) {
			$table->addColumn('tags', Types::JSON, ['notnull' => false, 'default' => null]);
			$output->info('Added tags column to openregister_bookmark_links');
			$changed = true;
		}

		if ($table->hasColumn('added_at') === false) {
			$table->addColumn('added_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
			$output->info('Added added_at column to openregister_bookmark_links');
			$changed = true;
		}

		return $changed;
	}//end extendBookmarkLinksTable()
}//end class
