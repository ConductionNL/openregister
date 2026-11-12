<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Per-object identifiers and query parameters on list/search processing-log entries.
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
 * @spec openspec/specs/avg-verwerkingsregister/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds `object_uuids`, `subject_ids` and `query_params` to `openregister_processing_log`.
 * Existing rows keep null in all three.
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md
 */
class Version1Date20261105160000 extends SimpleMigrationStep {

	/**
	 * Add the three nullable JSON text columns once.
	 *
	 * @param IOutput              $output        Migration output.
	 * @param Closure              $schemaClosure Returns the schema wrapper.
	 * @param array<string, mixed> $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The schema.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Nextcloud's.
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('openregister_processing_log') === false) {
			return $schema;
		}

		$table = $schema->getTable('openregister_processing_log');
		foreach (['object_uuids', 'subject_ids', 'query_params'] as $column) {
			if ($table->hasColumn($column) === false) {
				$table->addColumn($column, Types::TEXT, ['notnull' => false, 'default' => null]);
			}
		}

		return $schema;
	}//end changeSchema()
}//end class
