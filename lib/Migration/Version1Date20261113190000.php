<?php

/**
 * Scheduled messages — the table the scheduled-message sweep reads.
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
 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-the-scheduled-sweep-is-bounded-and-sends-once
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create `openregister_scheduled_messages`: the composed message (channel,
 * source, path, body, headers), what it is bound to (object, author), when it
 * goes (send_at), and the sweep's bookkeeping (state, attempts, claim, last
 * error, response, message id). Idempotent: created only when absent.
 *
 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-the-scheduled-sweep-is-bounded-and-sends-once
 */
class Version1Date20261113190000 extends SimpleMigrationStep {

	/**
	 * Change the database schema.
	 *
	 * @param IOutput                 $output        Output for the migration process.
	 * @param Closure                 $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The schema, changed or not.
	 *
	 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-the-scheduled-sweep-is-bounded-and-sends-once
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */
		$schema = $schemaClosure();

		if ($schema->hasTable('openregister_scheduled_messages') === true) {
			return $schema;
		}

		$table = $schema->createTable('openregister_scheduled_messages');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('channel', Types::STRING, ['notnull' => true, 'length' => 32]);
		$table->addColumn('source', Types::STRING, ['notnull' => true, 'length' => 128]);
		$table->addColumn('path', Types::STRING, ['notnull' => false, 'length' => 1024]);
		$table->addColumn('body', Types::TEXT, ['notnull' => false]);
		$table->addColumn('headers', Types::TEXT, ['notnull' => false]);
		$table->addColumn('object_uuid', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('author', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('send_at', Types::STRING, ['notnull' => false, 'length' => 32]);
		$table->addColumn('state', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'pending']);
		$table->addColumn('attempts', Types::INTEGER, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
		$table->addColumn('claimed_by', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('claimed_at', Types::STRING, ['notnull' => false, 'length' => 32]);
		$table->addColumn('last_error', Types::TEXT, ['notnull' => false]);
		$table->addColumn('response', Types::TEXT, ['notnull' => false]);
		$table->addColumn('message_id', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('created', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('updated', Types::DATETIME, ['notnull' => false]);
		$table->setPrimaryKey(['id']);
		$table->addIndex(['state', 'id'], 'or_sched_msg_state');
		$table->addIndex(['object_uuid'], 'or_sched_msg_object');
		$output->info('Created openregister_scheduled_messages');

		return $schema;
	}//end changeSchema()
}//end class
