<?php

/**
 * Adds `direction` to `openregister_email_links`.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCA\OpenRegister\BackgroundJob\BackfillEmailLinkMessageIdJob;
use OCP\BackgroundJob\IJobList;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;

/**
 * Adds the nullable `direction` column once: `inbound` for a linked mail,
 * `outbound` for one the app sent bound to an object. Existing rows keep
 * null and are read as their metadata says. After the schema change it
 * queues {@see BackfillEmailLinkMessageIdJob} once, so the links made
 * before the `Message-ID` was read get theirs.
 *
 * A sent mail is stored with account and message id 0 and its `Message-ID`
 * as the uid. An install that kept the Tier-1 table still carries
 * `email_links_unique_idx` (account, message, object), which would refuse the
 * second sent mail on an object; that index is dropped and the Tier-2
 * `idx_email_object_message` (object, account, message, uid) is ensured, so
 * one row per message and object stays unique.
 *
 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md
 */
class Version1Date20261121190000 extends TargetedMigrationStep {

	/**
	 * The Tier-1 unique index without the uid, which every sent mail would share.
	 */
	private const TIER1_UNIQUE_INDEX = 'email_links_unique_idx';

	/**
	 * The Tier-2 unique index, which includes the uid.
	 */
	private const MESSAGE_UNIQUE_INDEX = 'idx_email_object_message';

	/**
	 * Constructor.
	 *
	 * @param IJobList $jobList The background job list.
	 */
	public function __construct(
		private readonly IJobList $jobList,
	) {
	}//end __construct()

	/**
	 * Add the column.
	 *
	 * @param IOutput              $output        Migration output.
	 * @param Closure              $schemaClosure Returns the schema wrapper.
	 * @param array<string, mixed> $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The schema.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Nextcloud's.
	 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md
	 */
	protected function changeTargetedSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('openregister_email_links') === false) {
			return $schema;
		}

		$table = $schema->getTable('openregister_email_links');
		if ($table->hasColumn('direction') === false) {
			$table->addColumn('direction', Types::STRING, ['notnull' => false, 'length' => 16, 'default' => null]);
		}

		if ($table->hasIndex(self::TIER1_UNIQUE_INDEX) === true) {
			$table->dropIndex(self::TIER1_UNIQUE_INDEX);
		}

		if ($table->hasIndex(self::MESSAGE_UNIQUE_INDEX) === false) {
			$table->addUniqueIndex(['object_uuid', 'mail_account_id', 'mail_message_id', 'mail_message_uid'], self::MESSAGE_UNIQUE_INDEX);
		}

		return $schema;
	}//end changeTargetedSchema()

	/**
	 * Queue the one-shot backfill of the `Message-ID` on existing links.
	 *
	 * @param IOutput              $output        Migration output.
	 * @param Closure              $schemaClosure Returns the schema wrapper.
	 * @param array<string, mixed> $options       Migration options.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Nextcloud's.
	 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md
	 */
	protected function afterTargetedSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$this->jobList->add(BackfillEmailLinkMessageIdJob::class);
	}//end afterTargetedSchemaChange()
}//end class
