<?php

/**
 * Give the notification dedupe state a reminder's sent count and stop reason.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/service-hours-and-repeating-reminders/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add `reminder_sent` and `reminder_stopped` to openregister_notification_dedupe.
 *
 * A reminder is a scheduled rule with an anchor, an offset, a repeat and a
 * stop condition (design D-4). Its state lives on the rule's existing dedupe
 * row: the fingerprint holds the anchor value it was armed from, so a moved
 * anchor reads as a changed fingerprint; these two columns hold how many
 * reminders were sent from that anchor and, once it stopped, the condition
 * that stopped it. Idempotent: each column is added only when absent.
 *
 * @spec openspec/changes/service-hours-and-repeating-reminders/specs/notificatie-engine/spec.md
 */
class Version1Date20261114100000 extends SimpleMigrationStep {

	/**
	 * The table.
	 */
	public const TABLE = 'openregister_notification_dedupe';

	/**
	 * Change the database schema.
	 *
	 * @param IOutput                 $output        Output for the migration process.
	 * @param Closure                 $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options       Migration options.
	 *
	 * @return ISchemaWrapper|null
	 *
	 * @spec openspec/changes/service-hours-and-repeating-reminders/specs/notificatie-engine/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */
		$schema = $schemaClosure();

		if ($schema->hasTable(self::TABLE) === false) {
			return $schema;
		}

		$table = $schema->getTable(self::TABLE);
		if ($table->hasColumn('reminder_sent') === false) {
			$table->addColumn('reminder_sent', Types::INTEGER, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
			$output->info('Added reminder_sent to ' . self::TABLE . '.');
		}

		if ($table->hasColumn('reminder_stopped') === false) {
			$table->addColumn('reminder_stopped', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
			$output->info('Added reminder_stopped to ' . self::TABLE . '.');
		}

		return $schema;
	}//end changeSchema()
}//end class
