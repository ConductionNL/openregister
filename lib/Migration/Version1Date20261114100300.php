<?php

/**
 * OpenRegister Version1Date20261114100300
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-the-unredacted-originals-custody-is-stated-and-its-deletion-scheduled-req-rrs-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;

/**
 * Creates the custody table of unredacted originals.
 */
class Version1Date20261114100300 extends TargetedMigrationStep {

	/**
	 * Create `openregister_custody`.
	 *
	 * @param IOutput $output        The migration output.
	 * @param Closure $schemaClosure Returns the schema wrapper.
	 * @param array   $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The migration step signature.
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-the-unredacted-originals-custody-is-stated-and-its-deletion-scheduled-req-rrs-005
	 */
	protected function changeTargetedSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		if ($schema->hasTable('openregister_custody') === false) {
			$table = $schema->createTable('openregister_custody');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('original_file_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('copy_file_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('object_uuid', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
			$table->addColumn('retention', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('published_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('delete_after', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'scheduled']);
			$table->addColumn('outcome_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('outcome_reason', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['original_file_id'], 'idx_or_custody_original');
			$table->addIndex(['copy_file_id'], 'idx_or_custody_copy');
			$table->addIndex(['status', 'delete_after'], 'idx_or_custody_due');
			$output->info('Created openregister_custody table');
		}

		return $schema;
	}//end changeTargetedSchema()
}//end class
