<?php

/**
 * An object knows who has it open.
 *
 * Creates `openregister_presence`: one row per (user, object), carrying the
 * moment they arrived and the moment their client last said it was still
 * there. Nothing else. Presence is not a property of the object, not a version
 * and not an audit fact, so it lives beside the object rather than on it
 * (design D-4), and a row holds no data about what anybody is doing.
 *
 * 🔑 THE UNIQUE INDEX IS THE HEARTBEAT. A beat is an UPSERT on (user, object):
 * without the constraint, thirty beats a minute from one tab would be thirty
 * rows, and "who is here" would count one reader many times. The constraint is
 * what makes the write idempotent rather than a branch somebody has to
 * remember.
 *
 * 🔑 `last_seen` IS INDEXED BECAUSE EXPIRY SWEEPS IT. Reading who is present
 * means excluding the stale, and pruning means deleting them; both are range
 * queries over one column on a table every open detail page writes to.
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
 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create the presence table.
 *
 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md
 */
class Version1Date20260918154500 extends SimpleMigrationStep {

	/**
	 * The table this migration creates.
	 *
	 * @var string
	 */
	private const TABLE_PRESENCE = 'openregister_presence';

	/**
	 * Change the database schema.
	 *
	 * @param IOutput $output Output for the migration process.
	 * @param Closure $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options Migration options.
	 *
	 * @return ISchemaWrapper The changed schema.
	 *
	 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		if ($schema->hasTable(tableName: self::TABLE_PRESENCE) === false) {
			$table = $schema->createTable(self::TABLE_PRESENCE);
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('object_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			// When they arrived, kept across beats: a reader who has had the
			// page open for an hour is a different fact from one who just
			// opened it, and the list says which.
			$table->addColumn('arrived_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('last_seen', Types::DATETIME, ['notnull' => false]);

			$table->setPrimaryKey(['id']);
			// THE HEARTBEAT'S CONSTRAINT: one row per reader per object, so a
			// beat is an upsert and thirty beats a minute are one row.
			$table->addUniqueIndex(['user_id', 'object_uuid'], 'idx_or_presence_one');
			// The list reads by object and excludes the stale in one go.
			$table->addIndex(['object_uuid', 'last_seen'], 'idx_or_presence_live');
			// The sweep deletes by age across every object.
			$table->addIndex(['last_seen'], 'idx_or_presence_stale');

			$output->info('Created openregister_presence table');
		}//end if

		return $schema;
	}//end changeSchema()
}//end class
