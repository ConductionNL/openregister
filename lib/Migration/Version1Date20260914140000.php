<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The object read-state table: one row per (user, object) the user has seen.
 *
 * Per-user, per-object state stored OUTSIDE the object, exactly as a watcher
 * and a favourite are, so that reading an object writes no audit entry and cuts
 * no version on it.
 *
 * The unique index on (user_id, object_uuid) is what makes opening an object
 * idempotent: opening it twice refreshes one row rather than writing a second,
 * and it is the index the `_unread` lens's correlated `NOT EXISTS` reads.
 *
 * `register` and `schema` are carried on the row so a narrowed lens and a purge
 * by register stay a single statement without joining the per-schema object
 * table. `sub_seen` holds one JSON map of sub-resource name to the moment that
 * tab was last read, because a page renders every tab at once and a row per tab
 * would be a join per tab.
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
 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use Doctrine\DBAL\Types\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates the object read-state table and widens the notification history.
 *
 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md
 */
class Version1Date20260914140000 extends SimpleMigrationStep {

	/**
	 * The read-state table.
	 */
	private const TABLE = 'openregister_object_read_state';

	/**
	 * The notification history table, which gains four nullable columns.
	 */
	private const HISTORY_TABLE = 'openregister_notification_history';

	/**
	 * Create the table when absent, and widen the notification history.
	 *
	 * Add-only in both halves: the table is created only when it is missing,
	 * and each history column is added only when `hasColumn()` says it is not
	 * there. Nothing existing is altered, renamed or dropped.
	 *
	 * @param IOutput $output Migration output.
	 * @param Closure $schemaClosure Returns the schema wrapper.
	 * @param array<string, mixed> $options Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema, or null when nothing changed.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Nextcloud's.
	 *
	 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$changed = 0;

		if ($schema->hasTable(self::TABLE) === false) {
			$table = $schema->createTable(self::TABLE);
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('object_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('register', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('schema', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('last_seen_at', Types::DATETIME_MUTABLE, ['notnull' => true]);
			$table->addColumn('sub_seen', Types::JSON, ['notnull' => false]);
			$table->addColumn('created', Types::DATETIME_MUTABLE, ['notnull' => true]);

			$table->setPrimaryKey(['id']);
			// One read state per (user, object): seeing it twice is seeing it once.
			$table->addUniqueIndex(['user_id', 'object_uuid'], 'or_readstate_user_object');
			// The invalidation and the purge: every reader of this object.
			$table->addIndex(['object_uuid'], 'or_readstate_object');
			// The narrowed `_unread` lens: what has this user seen, here.
			$table->addIndex(['user_id', 'register', 'schema'], 'or_readstate_user_scope');
			$changed++;
		}//end if

		$changed += $this->widenNotificationHistory(schema: $schema);

		if ($changed === 0) {
			$output->info('object read state: table and notification columns already present, nothing to do');
			return null;
		}

		return $schema;

	}//end changeSchema()

	/**
	 * Add the read stamp, the subject axis and the snooze and archive stamps.
	 *
	 * `subject_type` and `subject_id` give the notification list an axis: a bell
	 * with four hundred entries is unusable without one, and the existing
	 * `schema_id` answers a different question (which schema produced the rule)
	 * than "what is this notice about". `snoozed_until` returns a notice to the
	 * bell on a date and `archived_at` takes it out without claiming it was
	 * read, so neither can live in `openregister_notification_readstate`, whose
	 * `read_at` is NOT NULL and would force an archive to lie about a read.
	 *
	 * `read_at` lands here beside them for the same reason, and because a
	 * history row already carries exactly ONE recipient, so the row IS the
	 * per-user fact. That is not a second home for an existing one: a grep of
	 * `lib/` on 2026-09-14 finds NotificationReadStateMapper with no caller at
	 * all. It stays for identifiers that are not history rows (a core
	 * notification id, a channel token), and NotificationClearingService is the
	 * one writer of this column.
	 *
	 * @param ISchemaWrapper $schema The schema being changed.
	 *
	 * @return integer How many columns were added.
	 *
	 * @spec openspec/changes/object-read-state/specs/notificatie-engine/spec.md#requirement-a-notification-may-be-snoozed-or-archived-and-the-list-has-an-axis-req-ors-004
	 */
	private function widenNotificationHistory(ISchemaWrapper $schema): int {
		if ($schema->hasTable(self::HISTORY_TABLE) === false) {
			return 0;
		}

		$table = $schema->getTable(self::HISTORY_TABLE);
		$added = 0;

		if ($table->hasColumn('read_at') === false) {
			$table->addColumn('read_at', Types::DATETIME_MUTABLE, ['notnull' => false]);
			$added++;
		}

		if ($table->hasColumn('subject_type') === false) {
			$table->addColumn('subject_type', Types::STRING, ['notnull' => false, 'length' => 64]);
			$added++;
		}

		if ($table->hasColumn('subject_id') === false) {
			$table->addColumn('subject_id', Types::STRING, ['notnull' => false, 'length' => 128]);
			$added++;
		}

		if ($table->hasColumn('snoozed_until') === false) {
			$table->addColumn('snoozed_until', Types::DATETIME_MUTABLE, ['notnull' => false]);
			$added++;
		}

		if ($table->hasColumn('archived_at') === false) {
			$table->addColumn('archived_at', Types::DATETIME_MUTABLE, ['notnull' => false]);
			$added++;
		}

		if ($added > 0 && $table->hasIndex('or_notif_recipient_subject') === false) {
			// The bell's own read: this user's notices, narrowed to one axis.
			$table->addIndex(['recipient', 'subject_type'], 'or_notif_recipient_subject');
		}

		return $added;

	}//end widenNotificationHistory()
}//end class
