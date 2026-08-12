<?php

/**
 * Migration to create the openregister_calendar_links table.
 *
 * Additive Tier-2 link table for the calendar integration leaf. Coexists
 * with the legacy X-OPENREGISTER-* CalDAV custom properties: reads do a
 * UNION (link-table rows ∪ X-OR-* scan results, deduped by
 * (calendarUri, eventUid)); creates write both shapes; link-existing
 * writes only the link-table row (we may not own the VEVENT).
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * Creates the openregister_calendar_links table.
 *
 * @package OCA\OpenRegister\Migration
 */
class Version1Date20260524120000 extends SimpleMigrationStep {
	/**
	 * Change the database schema.
	 *
	 * @param IOutput $output Migration output
	 * @param Closure $schemaClosure Schema closure
	 * @param array $options Migration options
	 *
	 * @return ISchemaWrapper|null Updated schema or null
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		if ($schema->hasTable('openregister_calendar_links') === true) {
			return null;
		}

		$table = $schema->createTable('openregister_calendar_links');

		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('object_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('register_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('schema_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('calendar_uri', Types::STRING, ['notnull' => true, 'length' => 255]);
		$table->addColumn('calendar_id', Types::INTEGER, ['notnull' => false, 'default' => null]);
		$table->addColumn('event_uid', Types::STRING, ['notnull' => true, 'length' => 255]);
		$table->addColumn('event_uri', Types::STRING, ['notnull' => true, 'length' => 255]);
		$table->addColumn('summary', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
		$table->addColumn('dtstart', Types::DATETIME, ['notnull' => false, 'default' => null]);
		$table->addColumn('dtend', Types::DATETIME, ['notnull' => false, 'default' => null]);
		$table->addColumn('location', Types::STRING, ['notnull' => false, 'length' => 512, 'default' => null]);
		$table->addColumn('linked_by', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('linked_at', Types::DATETIME, ['notnull' => true]);
		$table->addColumn('tagged_with_xor', Types::BOOLEAN, ['notnull' => true, 'default' => false]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['object_uuid', 'calendar_uri', 'event_uid'], 'idx_cal_link_unique');
		$table->addIndex(['object_uuid'], 'idx_cal_link_object');
		$table->addIndex(['register_id'], 'idx_cal_link_register');
		$table->addIndex(['schema_id'], 'idx_cal_link_schema');
		$table->addIndex(['event_uid'], 'idx_cal_link_event_uid');

		$output->info('Created openregister_calendar_links table');

		return $schema;
	}//end changeSchema()
}//end class
