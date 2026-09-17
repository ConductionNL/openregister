<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The favourites and object-view tables: a star and a look, both per user.
 *
 * Per-user, per-object state stored OUTSIDE the object, exactly as a watcher
 * and a read state are, so that starring an object or opening it writes no
 * audit entry and cuts no version on it. That is the whole reason these are
 * two tables and not two properties.
 *
 * Both carry a unique index on (user_id, object_uuid). For favourites that
 * makes starring idempotent. For views it is what makes "recent" a list of
 * objects rather than a log of openings: a second look refreshes one row, so
 * the hundred-row cap is a hundred DISTINCT objects and not one object read a
 * hundred times.
 *
 * `register` and `schema` ride along on both rows so a narrowed lens and a
 * purge by register stay a single statement, without joining the per-schema
 * object table.
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
 * @spec openspec/changes/favourites-and-recent/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use Doctrine\DBAL\Types\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates the favourites table and the object-view history table.
 *
 * @spec openspec/changes/favourites-and-recent/specs/object-interactions/spec.md
 */
class Version1Date20260915061500 extends SimpleMigrationStep {

	/**
	 * The favourites table.
	 */
	private const FAVOURITES_TABLE = 'openregister_favourites';

	/**
	 * The per-user view history table.
	 */
	private const VIEWS_TABLE = 'openregister_object_views';

	/**
	 * Create both tables when they are absent.
	 *
	 * Add-only: each table is created only when `hasTable()` says it is
	 * missing. Nothing existing is altered, renamed or dropped.
	 *
	 * @param IOutput $output Migration output.
	 * @param Closure $schemaClosure Returns the schema wrapper.
	 * @param array<string, mixed> $options Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema, or null when nothing changed.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Nextcloud's.
	 *
	 * @spec openspec/changes/favourites-and-recent/specs/object-interactions/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$changed = 0;

		$changed += $this->createFavourites(schema: $schema);
		$changed += $this->createViews(schema: $schema);

		if ($changed === 0) {
			$output->info('favourites and recent: both tables already present, nothing to do');
			return null;
		}

		return $schema;

	}//end changeSchema()

	/**
	 * Create the favourites table.
	 *
	 * @param ISchemaWrapper $schema The schema being changed.
	 *
	 * @return integer 1 when the table was created, 0 when it was already there.
	 *
	 * @spec openspec/changes/favourites-and-recent/specs/object-interactions/spec.md#requirement-a-user-can-star-an-object-without-changing-it
	 */
	private function createFavourites(ISchemaWrapper $schema): int {
		if ($schema->hasTable(self::FAVOURITES_TABLE) === true) {
			return 0;
		}

		$table = $schema->createTable(self::FAVOURITES_TABLE);
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
		$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('object_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('register', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('schema', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('created', Types::DATETIME_MUTABLE, ['notnull' => true]);

		$table->setPrimaryKey(['id']);
		// One star per (user, object): starring twice is starring once.
		$table->addUniqueIndex(['user_id', 'object_uuid'], 'or_fav_user_object');
		// The cascade on object deletion: every star on this object.
		$table->addIndex(['object_uuid'], 'or_fav_object');
		// The narrowed `_favourite` lens: what has this user starred, here.
		$table->addIndex(['user_id', 'register', 'schema'], 'or_fav_user_scope');

		return 1;

	}//end createFavourites()

	/**
	 * Create the per-user view history table.
	 *
	 * `viewed_at` is indexed with the user because that pair answers both
	 * questions this table exists for: order my recent objects, and find the
	 * oldest rows to drop when I pass the cap.
	 *
	 * @param ISchemaWrapper $schema The schema being changed.
	 *
	 * @return integer 1 when the table was created, 0 when it was already there.
	 *
	 * @spec openspec/changes/favourites-and-recent/specs/object-interactions/spec.md#requirement-opening-an-object-records-a-per-user-view
	 */
	private function createViews(ISchemaWrapper $schema): int {
		if ($schema->hasTable(self::VIEWS_TABLE) === true) {
			return 0;
		}

		$table = $schema->createTable(self::VIEWS_TABLE);
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
		$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('object_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('register', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('schema', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('viewed_at', Types::DATETIME_MUTABLE, ['notnull' => true]);
		$table->addColumn('created', Types::DATETIME_MUTABLE, ['notnull' => true]);

		$table->setPrimaryKey(['id']);
		// One row per (user, object), so the cap counts objects and not opens.
		$table->addUniqueIndex(['user_id', 'object_uuid'], 'or_objview_user_object');
		// The cascade on object deletion: every view of this object.
		$table->addIndex(['object_uuid'], 'or_objview_object');
		// The `_recent` ordering and the trim to the most recent hundred.
		$table->addIndex(['user_id', 'viewed_at'], 'or_objview_user_time');

		return 1;

	}//end createViews()
}//end class
