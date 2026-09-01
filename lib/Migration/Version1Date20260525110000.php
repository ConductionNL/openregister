<?php

/**
 * Tier-2 talk integration migration.
 *
 * Ensures the `openregister_talk_links` table exists with the full
 * Tier-2 schema:
 *
 *  - id (bigint, PK, autoincrement)
 *  - object_uuid (string 36, not null)
 *  - register_id (bigint unsigned, not null)
 *  - schema_id (bigint unsigned, nullable)
 *  - room_token (string 64, not null) — Talk room token (canonical id)
 *  - room_id (bigint, nullable) — Talk Room::id (legacy numeric id)
 *  - room_name (string 255, nullable) — cached display name
 *  - room_type (int, nullable) — Talk Room::TYPE_* (1..6)
 *  - subtitle (string 255, nullable) — cached human-readable type label
 *  - participant_count (int, nullable) — cached participant count
 *  - last_message_data (text JSON, nullable) — cached {actor,text,timestamp}
 *  - last_activity (datetime, nullable) — cached last-activity timestamp
 *  - linked_by (string 64, not null)
 *  - linked_at (datetime, not null)
 *
 * Composite unique `(object_uuid, room_token)` so a single Talk room can be
 * linked to multiple OR objects but only once per object.
 *
 * Idempotent: creates the table if missing, otherwise adds any
 * missing Tier-2 columns. Cached fields are best-effort — they're
 * refreshed by TalkLinkService on read (>5min staleness) so a
 * persistent link row alone surfaces a usable sidebar tab even when
 * Talk is briefly unavailable.
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
 * Tier-2 talk-links table — create-or-extend.
 */
class Version1Date20260525110000 extends SimpleMigrationStep {
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

		if ($schema->hasTable('openregister_talk_links') === false) {
			$this->createTalkLinksTable(schema: $schema, output: $output);
			$changed = true;
		}

		if ($schema->hasTable('openregister_talk_links') === true
			&& $this->extendTalkLinksTable(schema: $schema, output: $output) === true
		) {
			$changed = true;
		}

		if ($changed === false) {
			return null;
		}

		return $schema;
	}//end changeSchema()

	/**
	 * Create the openregister_talk_links table at the Tier-2 shape.
	 *
	 * @param ISchemaWrapper $schema The schema wrapper
	 * @param IOutput $output Migration output
	 *
	 * @return void
	 */
	private function createTalkLinksTable(ISchemaWrapper $schema, IOutput $output): void {
		$table = $schema->createTable('openregister_talk_links');

		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('object_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('register_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('schema_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
		$table->addColumn('room_token', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('room_id', Types::BIGINT, ['notnull' => false, 'default' => null]);
		$table->addColumn('room_name', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
		$table->addColumn('room_type', Types::INTEGER, ['notnull' => false, 'default' => null]);
		$table->addColumn('subtitle', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
		$table->addColumn('participant_count', Types::INTEGER, ['notnull' => false, 'default' => null]);
		$table->addColumn('last_message_data', Types::TEXT, ['notnull' => false, 'default' => null]);
		$table->addColumn('last_activity', Types::DATETIME, ['notnull' => false, 'default' => null]);
		$table->addColumn('linked_by', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('linked_at', Types::DATETIME, ['notnull' => true]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['object_uuid', 'room_token'], 'idx_talk_object_room');
		$table->addIndex(['object_uuid'], 'idx_talk_object');
		$table->addIndex(['room_token'], 'idx_talk_room_token');
		$table->addIndex(['schema_id'], 'idx_talk_schema');

		$output->info('Created openregister_talk_links table (Tier-2 schema)');
	}//end createTalkLinksTable()

	/**
	 * Add any missing Tier-2 columns to an existing talk_links table.
	 *
	 * @param ISchemaWrapper $schema The schema wrapper
	 * @param IOutput $output Migration output
	 *
	 * @return bool True when a column was added.
	 */
	private function extendTalkLinksTable(ISchemaWrapper $schema, IOutput $output): bool {
		$table = $schema->getTable('openregister_talk_links');
		$changed = false;

		if ($table->hasColumn('schema_id') === false) {
			$table->addColumn(
				'schema_id',
				Types::BIGINT,
				['notnull' => false, 'unsigned' => true, 'default' => null]
			);
			$table->addIndex(['schema_id'], 'idx_talk_schema');
			$output->info('Added schema_id column to openregister_talk_links');
			$changed = true;
		}

		if ($table->hasColumn('subtitle') === false) {
			$table->addColumn('subtitle', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
			$output->info('Added subtitle column to openregister_talk_links');
			$changed = true;
		}

		if ($table->hasColumn('participant_count') === false) {
			$table->addColumn('participant_count', Types::INTEGER, ['notnull' => false, 'default' => null]);
			$output->info('Added participant_count column to openregister_talk_links');
			$changed = true;
		}

		if ($table->hasColumn('last_message_data') === false) {
			$table->addColumn('last_message_data', Types::TEXT, ['notnull' => false, 'default' => null]);
			$output->info('Added last_message_data column to openregister_talk_links');
			$changed = true;
		}

		if ($table->hasColumn('last_activity') === false) {
			$table->addColumn('last_activity', Types::DATETIME, ['notnull' => false, 'default' => null]);
			$output->info('Added last_activity column to openregister_talk_links');
			$changed = true;
		}

		return $changed;
	}//end extendTalkLinksTable()
}//end class
