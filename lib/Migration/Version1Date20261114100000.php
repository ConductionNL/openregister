<?php

/**
 * Deduplicate calendar link rows and guarantee the unique index on their triple.
 *
 * Linking is idempotent at the service (REQ-CAL-INB-003); the database makes it
 * hold under concurrency, when an inbound calendar listener and an API call
 * link the same event at once. The create migration declares the unique index,
 * but an instance whose table predates it, or lost it, has neither the index
 * nor any guarantee that its rows are unique. On such an instance the surplus
 * rows of each (object uuid, event uid, calendar uri) triple are removed first,
 * keeping the oldest, so the index can be created; the read path already
 * deduplicates, so collapsing them changes no observable read. Where the index
 * is present this step does nothing.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/calendar-leaf-inbound/specs/integration-calendar/spec.md#requirement-linking-is-idempotent-req-cal-inb-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCA\OpenRegister\Db\CalendarLinkDuplicates;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Schema\ITable;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Collapse duplicate calendar link rows, then add the unique index on the triple.
 *
 * @spec openspec/changes/calendar-leaf-inbound/specs/integration-calendar/spec.md#requirement-linking-is-idempotent-req-cal-inb-003
 */
class Version1Date20261114100000 extends SimpleMigrationStep {

	/**
	 * The link table.
	 *
	 * @var string
	 */
	public const TABLE = 'openregister_calendar_links';

	/**
	 * The unique index the create migration declares.
	 *
	 * @var string
	 */
	public const INDEX = 'idx_cal_link_unique';

	/**
	 * The triple that identifies a link.
	 *
	 * @var array<int, string>
	 */
	public const COLUMNS = ['object_uuid', 'calendar_uri', 'event_uid'];

	/**
	 * How many ids one DELETE names.
	 *
	 * @var int
	 */
	private const CHUNK = 500;

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $connection The database connection.
	 */
	public function __construct(private readonly IDBConnection $connection) {
	}//end __construct()

	/**
	 * Remove the surplus rows of every triple when the unique index is missing.
	 *
	 * @param IOutput $output        Migration output.
	 * @param Closure $schemaClosure Returns the current schema.
	 * @param array   $options       Migration options.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/calendar-leaf-inbound/specs/integration-calendar/spec.md#requirement-linking-is-idempotent-req-cal-inb-003
	 */
	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		if ($this->needsIndex(schema: $schemaClosure()) === false) {
			return;
		}

		$duplicates = new CalendarLinkDuplicates();
		$select     = $this->connection->getQueryBuilder();
		$result     = $select->select('id', 'object_uuid', 'event_uid', 'calendar_uri', 'linked_at')
			->from(self::TABLE)
			->executeQuery();
		while (($row = $result->fetch()) !== false) {
			$duplicates->add(row: $row);
		}

		$result->closeCursor();

		$surplus = $duplicates->surplusIds();
		foreach (array_chunk($surplus, self::CHUNK) as $chunk) {
			$delete = $this->connection->getQueryBuilder();
			$delete->delete(self::TABLE)
				->where($delete->expr()->in('id', $delete->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		}

		$output->info('Removed ' . count($surplus) . ' duplicate calendar link row(s), keeping the oldest of each.');
	}//end preSchemaChange()

	/**
	 * Add the unique index on the triple when it is missing.
	 *
	 * @param IOutput $output        Migration output.
	 * @param Closure $schemaClosure Returns the current schema.
	 * @param array   $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The schema, changed or not.
	 *
	 * @spec openspec/changes/calendar-leaf-inbound/specs/integration-calendar/spec.md#requirement-linking-is-idempotent-req-cal-inb-003
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($this->needsIndex(schema: $schema) === false) {
			return $schema;
		}

		$schema->getTable(self::TABLE)->addUniqueIndex(self::COLUMNS, self::INDEX);
		$output->info('Added ' . self::INDEX . ' on ' . self::TABLE . '.');

		return $schema;
	}//end changeSchema()

	/**
	 * Whether the table exists and lacks a unique index over exactly the triple.
	 *
	 * @param ISchemaWrapper $schema The current schema.
	 *
	 * @return bool
	 */
	private function needsIndex(ISchemaWrapper $schema): bool {
		if ($schema->hasTable(self::TABLE) === false) {
			return false;
		}

		return self::hasUniqueTriple(table: $schema->getTable(self::TABLE)) === false;
	}//end needsIndex()

	/**
	 * Whether any unique index on the table covers exactly the triple, under any name.
	 *
	 * @param ITable $table The link table.
	 *
	 * @return bool
	 */
	private static function hasUniqueTriple(ITable $table): bool {
		$wanted = self::COLUMNS;
		sort($wanted);

		foreach ($table->getIndexes() as $index) {
			$columns = array_map('strtolower', $index->getColumns());
			sort($columns);
			if ($index->isUnique() === true && $columns === $wanted) {
				return true;
			}
		}

		return false;
	}//end hasUniqueTriple()
}//end class
