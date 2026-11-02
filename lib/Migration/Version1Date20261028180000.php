<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The sender's display name on an email link.
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
 * @spec openspec/specs/email-link-sender-name-and-details/spec.md#requirement-an-email-link-carries-the-senders-display-name
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds `sender_name` to `openregister_email_links`.
 *
 * @spec openspec/specs/email-link-sender-name-and-details/spec.md#requirement-an-email-link-carries-the-senders-display-name
 */
class Version1Date20261028180000 extends SimpleMigrationStep {

	/**
	 * The table.
	 */
	public const TABLE = 'openregister_email_links';

	/**
	 * Add the column when the table is there and the column is not.
	 *
	 * @param IOutput              $output        Migration output.
	 * @param Closure              $schemaClosure Returns the schema wrapper.
	 * @param array<string, mixed> $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The schema.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Nextcloud's.
	 * @spec openspec/specs/email-link-sender-name-and-details/spec.md#requirement-an-email-link-carries-the-senders-display-name
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable(self::TABLE) === false) {
			return $schema;
		}

		$table = $schema->getTable(self::TABLE);
		if ($table->hasColumn('sender_name') === true) {
			return $schema;
		}

		$table->addColumn('sender_name', Types::STRING, ['notnull' => false, 'length' => 255]);

		return $schema;
	}//end changeSchema()
}//end class
