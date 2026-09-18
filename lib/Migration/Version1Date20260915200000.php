<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Notification routing: the event id, the group defaults and the broadcast.
 *
 * Three add-only pieces of one change.
 *
 * `event_id` on the notification history is what makes one firing readable as
 * one thing. A rule that notifies a caseworker and calls an integration writes
 * a row per transport, each with its own outcome, and until now the only way to
 * tell they belonged together was to compare timestamps and hope.
 *
 * The broadcast tables hold the loudest act the system has: one message to
 * every user. Who sent it, what it said and the period it is shown for live on
 * the broadcast row, and the receipt row is what makes "once" true per person
 * rather than per page load.
 *
 * Group defaults are deliberately NOT a table here: they are app config, the
 * same store the per-user overrides already use, so a group with no default
 * costs nothing and adding a schema needs no migration.
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
 * @spec openspec/changes/notification-routing-per-group-and-scope/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use Doctrine\DBAL\Types\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the dispatch event id and the broadcast tables.
 *
 * @spec openspec/changes/notification-routing-per-group-and-scope/specs/notificatie-engine/spec.md
 */
class Version1Date20260915200000 extends SimpleMigrationStep {

	/**
	 * The notification history, which gains the event id.
	 */
	private const HISTORY_TABLE = 'openregister_notification_history';

	/**
	 * One administered message to every user.
	 */
	private const BROADCAST_TABLE = 'openregister_notif_broadcast';

	/**
	 * One row per user who has seen a broadcast.
	 */
	private const RECEIPT_TABLE = 'openregister_notif_bc_receipt';

	/**
	 * Add the event id and create the broadcast tables when absent.
	 *
	 * Add-only throughout: nothing existing is altered, renamed or dropped, and
	 * every piece is guarded by its own `hasTable()` / `hasColumn()` so a rerun
	 * is a no-op.
	 *
	 * @param IOutput $output Migration output.
	 * @param Closure $schemaClosure Returns the schema wrapper.
	 * @param array<string, mixed> $options Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema, or null when nothing changed.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Nextcloud's.
	 *
	 * @spec openspec/changes/notification-routing-per-group-and-scope/specs/notificatie-engine/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$changed = 0;

		$changed += $this->addEventId(schema: $schema);
		$changed += $this->createBroadcastTables(schema: $schema);

		if ($changed === 0) {
			$output->info('notification routing: event id and broadcast tables already present, nothing to do');
			return $schema;
		}

		return $schema;

	}//end changeSchema()

	/**
	 * Add `event_id` to the notification history, and the index that reads it.
	 *
	 * Nullable, because every row written before this column existed belongs to
	 * a firing nobody recorded an id for, and inventing one after the fact would
	 * claim a grouping that was never observed.
	 *
	 * @param ISchemaWrapper $schema The schema being changed.
	 *
	 * @return integer How many pieces were added.
	 *
	 * @spec openspec/changes/notification-routing-per-group-and-scope/specs/notificatie-engine/spec.md#requirement-one-rule-reaches-a-person-and-an-integration-recorded-once-req-nrg-004
	 */
	private function addEventId(ISchemaWrapper $schema): int {
		if ($schema->hasTable(self::HISTORY_TABLE) === false) {
			return 0;
		}

		$table = $schema->getTable(self::HISTORY_TABLE);
		$added = 0;

		if ($table->hasColumn('event_id') === false) {
			$table->addColumn('event_id', Types::STRING, ['notnull' => false, 'length' => 64]);
			$added++;
		}

		if ($table->hasIndex('or_notif_event') === false) {
			// The whole point of the column: every transport of one firing, in
			// one read.
			$table->addIndex(['event_id'], 'or_notif_event');
			$added++;
		}

		return $added;

	}//end addEventId()

	/**
	 * Create the broadcast and receipt tables when they are absent.
	 *
	 * @param ISchemaWrapper $schema The schema being changed.
	 *
	 * @return integer How many tables were created.
	 *
	 * @spec openspec/changes/notification-routing-per-group-and-scope/specs/notificatie-engine/spec.md#requirement-a-broadcast-reaches-every-user-once-recorded-req-nrg-005
	 */
	private function createBroadcastTables(ISchemaWrapper $schema): int {
		$created = 0;

		if ($schema->hasTable(self::BROADCAST_TABLE) === false) {
			$table = $schema->createTable(self::BROADCAST_TABLE);
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('subject', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('body', Types::TEXT, ['notnull' => false]);
			$table->addColumn('sender', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('starts_at', Types::DATETIME_MUTABLE, ['notnull' => true]);
			$table->addColumn('ends_at', Types::DATETIME_MUTABLE, ['notnull' => true]);
			$table->addColumn('created', Types::DATETIME_MUTABLE, ['notnull' => true]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['uuid'], 'or_notif_bc_uuid');
			// The read every page does: what is showing right now.
			$table->addIndex(['starts_at', 'ends_at'], 'or_notif_bc_period');
			$created++;
		}//end if

		if ($schema->hasTable(self::RECEIPT_TABLE) === false) {
			$table = $schema->createTable(self::RECEIPT_TABLE);
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('broadcast_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('seen_at', Types::DATETIME_MUTABLE, ['notnull' => true]);

			$table->setPrimaryKey(['id']);
			// "Once" is this index. Acknowledging twice refreshes one row
			// rather than writing a second, so a double-click cannot make the
			// record say two people saw it.
			$table->addUniqueIndex(['broadcast_uuid', 'user_id'], 'or_notif_bc_rcpt_once');
			$table->addIndex(['user_id'], 'or_notif_bc_rcpt_user');
			$created++;
		}//end if

		return $created;

	}//end createBroadcastTables()
}//end class
