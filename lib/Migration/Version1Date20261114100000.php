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
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the nullable `direction` column once: `inbound` for a linked mail,
 * `outbound` for one the app sent bound to an object. Existing rows keep
 * null and are read as their metadata says.
 *
 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md
 */
class Version1Date20261114100000 extends SimpleMigrationStep {

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
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('openregister_email_links') === false) {
			return $schema;
		}

		$table = $schema->getTable('openregister_email_links');
		if ($table->hasColumn('direction') === false) {
			$table->addColumn('direction', Types::STRING, ['notnull' => false, 'length' => 16, 'default' => null]);
		}

		return $schema;
	}//end changeSchema()
}//end class
