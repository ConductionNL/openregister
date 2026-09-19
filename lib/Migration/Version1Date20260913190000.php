<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The object watchers table: one row per (user, object) subscription.
 *
 * A watcher is per-user, per-object state stored OUTSIDE the object, exactly
 * as a favourite is, so that following an object writes no audit entry and no
 * version on it. The unique index on (user_id, object_uuid) is what makes
 * watching idempotent: subscribing twice is one row, and the second call is a
 * no-op rather than a duplicate.
 *
 * `register` and `schema` are carried on the row so the `_watching=true` lens
 * can narrow to one register or schema without joining the object table, and
 * so a purge by register stays a single statement.
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
 * @spec openspec/changes/object-watchers/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use Doctrine\DBAL\Types\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates the object watchers table.
 *
 * @spec openspec/changes/object-watchers/specs/object-interactions/spec.md
 */
class Version1Date20260913190000 extends SimpleMigrationStep {

	/**
	 * The watchers table.
	 */
	private const TABLE = 'openregister_watchers';

	/**
	 * Create the table when absent.
	 *
	 * @param IOutput $output Migration output.
	 * @param Closure $schemaClosure Returns the schema wrapper.
	 * @param array<string, mixed> $options Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema, or null when nothing changed.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Nextcloud's.
	 *
	 * @spec openspec/changes/object-watchers/specs/object-interactions/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if ($schema->hasTable(self::TABLE) === true) {
			return $schema;
		}

		$table = $schema->createTable(self::TABLE);
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
		$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('object_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('register', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('schema', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('created', Types::DATETIME_MUTABLE, ['notnull' => true]);

		$table->setPrimaryKey(['id']);
		// One subscription per (user, object): watching twice is watching once.
		$table->addUniqueIndex(['user_id', 'object_uuid'], 'or_watcher_user_object');
		// The dispatcher's lookup: who watches this object.
		$table->addIndex(['object_uuid'], 'or_watcher_object');
		// The `_watching=true` lens: what does this user watch.
		$table->addIndex(['user_id', 'register', 'schema'], 'or_watcher_user_scope');

		return $schema;
	}//end changeSchema()
}//end class
