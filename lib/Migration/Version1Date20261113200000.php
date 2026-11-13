<?php

/**
 * Mail headers — the thread headers of collected `.eml` files.
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
 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md#requirement-collected-mail-files-carry-their-thread-headers-req-rth-010
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create `openregister_mail_headers`: one row per collected `.eml` file with
 * its Message-ID, In-Reply-To, References chain and sent date. Idempotent:
 * created only when absent.
 *
 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md#requirement-collected-mail-files-carry-their-thread-headers-req-rth-010
 */
class Version1Date20261113200000 extends SimpleMigrationStep {

	/**
	 * Change the database schema.
	 *
	 * @param IOutput                 $output        Output for the migration process.
	 * @param Closure                 $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Nextcloud's.
	 *
	 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md#requirement-collected-mail-files-carry-their-thread-headers-req-rth-010
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();
		if ($schema->hasTable('openregister_mail_headers') === true) {
			return $schema;
		}

		$table = $schema->createTable('openregister_mail_headers');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('file_id', Types::BIGINT, ['notnull' => true]);
		$table->addColumn('message_id', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => '']);
		$table->addColumn('in_reply_to', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => '']);
		$table->addColumn('reference_ids', Types::TEXT, ['notnull' => false]);
		$table->addColumn('sent_at', Types::DATETIME, ['notnull' => false]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['file_id'], 'or_mail_headers_file');
		$table->addIndex(['message_id'], 'or_mail_headers_msgid');
		$table->addIndex(['in_reply_to'], 'or_mail_headers_reply');

		$output->info('Created openregister_mail_headers table');

		return $schema;
	}//end changeSchema()
}//end class
