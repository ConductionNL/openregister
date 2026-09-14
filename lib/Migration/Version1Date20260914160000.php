<?php

/**
 * Create the calendar-feed token table.
 *
 * One row per subscribable calendar feed: an opaque token bound to a
 * principal and a scope (one calendar-enabled schema, or one saved view),
 * with a lifecycle (created / expires / revoked) so the public feed endpoint
 * fails closed rather than answering a reduced calendar.
 *
 * Idempotent and purely additive: the table is created only when absent and
 * no existing table is touched.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-dates-as-a-calendar-feed/specs/calendar-provider/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create openregister_calendar_feeds.
 *
 * @spec openspec/changes/object-dates-as-a-calendar-feed/specs/calendar-provider/spec.md
 */
class Version1Date20260914160000 extends SimpleMigrationStep {

	/**
	 * The table this migration creates.
	 *
	 * @var string
	 */
	private const TABLE = 'openregister_calendar_feeds';

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
	 * @spec openspec/changes/object-dates-as-a-calendar-feed/specs/calendar-provider/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if ($schema->hasTable(self::TABLE) === true) {
			return null;
		}

		$table = $schema->createTable(self::TABLE);
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('token', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('scope_type', Types::STRING, ['notnull' => true, 'length' => 16]);
		$table->addColumn('scope_id', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('label', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('expires_at', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('revoked_at', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('last_read_at', Types::DATETIME, ['notnull' => false]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['token'], 'idx_or_calfeed_token');
		$table->addIndex(['user_id'], 'idx_or_calfeed_user');

		$output->info('Created ' . self::TABLE . ' table');

		return $schema;
	}//end changeSchema()
}//end class
