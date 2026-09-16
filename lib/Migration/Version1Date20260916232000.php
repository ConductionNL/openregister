<?php

/**
 * Configuration bundles — the one table a binding needs.
 *
 * A bundle binds one configuration to many subjects. Its VALUES need no table:
 * `openregister_config_values` already carries the bundle layer, so a bundle's
 * permissions, notification rules and lifecycle settings are rows at
 * `layer = 'bundle'` keyed by the bundle name. What was missing is the binding
 * itself, which is this table.
 *
 * The unique index is on the subject, not on the pair. A subject follows at
 * most one bundle, because the explainer's chain has one bundle slot between
 * register and subject and a second bundle would need a precedence rule nobody
 * has written.
 *
 * Idempotent: the table is created only when absent.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create the configuration binding table.
 *
 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
 */
class Version1Date20260916232000 extends SimpleMigrationStep {

	/**
	 * Change the database schema.
	 *
	 * @param IOutput                 $output        Output for the migration process.
	 * @param Closure                 $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		if ($schema->hasTable('openregister_config_bindings') === true) {
			return $schema;
		}

		$table = $schema->createTable('openregister_config_bindings');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
		$table->addColumn('bundle', Types::STRING, ['notnull' => true, 'length' => 128]);
		$table->addColumn('subject', Types::STRING, ['notnull' => true, 'length' => 128]);
		$table->addColumn('subject_type', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'schema']);
		$table->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('created', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('updated', Types::DATETIME, ['notnull' => false]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['uuid'], 'idx_or_cfgbind_uuid');
		$table->addUniqueIndex(['subject'], 'idx_or_cfgbind_subject');
		$table->addIndex(['bundle'], 'idx_or_cfgbind_bundle');

		$output->info('Created openregister_config_bindings table');

		return $schema;

	}//end changeSchema()
}//end class
