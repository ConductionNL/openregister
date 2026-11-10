<?php

/**
 * Configuration as a deployment — the four tables the lifecycle needs.
 *
 * `openregister_config_sets` is a set of pending values with its author and
 * its approval. `openregister_config_drafts` holds one pending value per
 * address, beside the live value it was taken against. `openregister_config_
 * deployments` is the append-only history: each row carries, per address, the
 * value that was there before and the value that replaced it, which is what
 * makes a rollback an apply rather than a reconstruction.
 * `openregister_config_values` holds the layered live values below the
 * instance, and at the instance layer it holds the provenance the explainer
 * reads: which deployment last moved the value.
 *
 * Idempotent: each table is created only when absent.
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
 * Create the configuration deployment tables.
 *
 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
 */
class Version1Date20260915224500 extends SimpleMigrationStep {

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

		$this->createSetsTable(schema: $schema, output: $output);
		$this->createDraftsTable(schema: $schema, output: $output);
		$this->createDeploymentsTable(schema: $schema, output: $output);
		$this->createValuesTable(schema: $schema, output: $output);

		return $schema;

	}//end changeSchema()

	/**
	 * Create the draft set table.
	 *
	 * @param ISchemaWrapper $schema The schema.
	 * @param IOutput        $output Migration output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	private function createSetsTable(ISchemaWrapper $schema, IOutput $output): void {
		if ($schema->hasTable('openregister_config_sets') === true) {
			return;
		}

		$table = $schema->createTable('openregister_config_sets');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
		$table->addColumn('name', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('description', Types::TEXT, ['notnull' => false]);
		$table->addColumn('state', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'open']);
		$table->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('approved_by', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('approved_at', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('deployment_uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
		$table->addColumn('created', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('updated', Types::DATETIME, ['notnull' => false]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['uuid'], 'idx_or_cfgset_uuid');
		$table->addIndex(['state'], 'idx_or_cfgset_state');
		$table->addIndex(['created_by', 'state'], 'idx_or_cfgset_author');

		$output->info('Created openregister_config_sets table');

	}//end createSetsTable()

	/**
	 * Create the pending value table.
	 *
	 * @param ISchemaWrapper $schema The schema.
	 * @param IOutput        $output Migration output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	private function createDraftsTable(ISchemaWrapper $schema, IOutput $output): void {
		if ($schema->hasTable('openregister_config_drafts') === true) {
			return;
		}

		$table = $schema->createTable('openregister_config_drafts');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
		$table->addColumn('set_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('layer', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'instance']);
		$table->addColumn('layer_ref', Types::STRING, ['notnull' => false, 'length' => 128]);
		$table->addColumn('config_key', Types::STRING, ['notnull' => true, 'length' => 191]);
		// The live value the draft was taken against, so a set approved on one
		// reading of the instance cannot be deployed onto a different one.
		$table->addColumn('base_value', Types::TEXT, ['notnull' => false]);
		$table->addColumn('base_present', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
		$table->addColumn('draft_value', Types::TEXT, ['notnull' => false]);
		$table->addColumn('removes', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
		$table->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('created', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('updated', Types::DATETIME, ['notnull' => false]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['uuid'], 'idx_or_cfgdraft_uuid');
		$table->addIndex(['set_uuid'], 'idx_or_cfgdraft_set');
		$table->addIndex(['layer', 'config_key'], 'idx_or_cfgdraft_addr');

		$output->info('Created openregister_config_drafts table');

	}//end createDraftsTable()

	/**
	 * Create the append-only deployment history.
	 *
	 * @param ISchemaWrapper $schema The schema.
	 * @param IOutput        $output Migration output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	private function createDeploymentsTable(ISchemaWrapper $schema, IOutput $output): void {
		if ($schema->hasTable('openregister_config_deployments') === true) {
			return;
		}

		$table = $schema->createTable('openregister_config_deployments');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
		$table->addColumn('name', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('set_uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
		$table->addColumn('author', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('approver', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('deployed_by', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('deployed_at', Types::DATETIME, ['notnull' => false]);
		// Set only on a rollback, and the reason this table never needs an
		// update: undoing is a new row, not an edit of the row being undone.
		$table->addColumn('restores_uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
		$table->addColumn('changes', Types::TEXT, ['notnull' => false]);
		$table->addColumn('change_count', Types::INTEGER, ['notnull' => false, 'default' => 0]);
		$table->addColumn('state', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'applied']);
		$table->addColumn('created', Types::DATETIME, ['notnull' => false]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['uuid'], 'idx_or_cfgdep_uuid');
		$table->addIndex(['set_uuid'], 'idx_or_cfgdep_set');
		$table->addIndex(['restores_uuid'], 'idx_or_cfgdep_restores');
		$table->addIndex(['deployed_at'], 'idx_or_cfgdep_when');

		$output->info('Created openregister_config_deployments table');

	}//end createDeploymentsTable()

	/**
	 * Create the layered value and provenance table.
	 *
	 * @param ISchemaWrapper $schema The schema.
	 * @param IOutput        $output Migration output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	private function createValuesTable(ISchemaWrapper $schema, IOutput $output): void {
		if ($schema->hasTable('openregister_config_values') === true) {
			return;
		}

		$table = $schema->createTable('openregister_config_values');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
		$table->addColumn('layer', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'instance']);
		$table->addColumn('layer_ref', Types::STRING, ['notnull' => false, 'length' => 128]);
		$table->addColumn('config_key', Types::STRING, ['notnull' => true, 'length' => 191]);
		$table->addColumn('config_value', Types::TEXT, ['notnull' => false]);
		// Null means no deployment ever moved this value, which the explainer
		// reports as predating the first deployment rather than as unknown.
		$table->addColumn('deployment_uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
		$table->addColumn('bundle_uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
		$table->addColumn('updated_by', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('created', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('updated', Types::DATETIME, ['notnull' => false]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['uuid'], 'idx_or_cfgval_uuid');
		$table->addIndex(['layer', 'layer_ref', 'config_key'], 'idx_or_cfgval_addr');
		$table->addIndex(['config_key'], 'idx_or_cfgval_key');
		$table->addIndex(['deployment_uuid'], 'idx_or_cfgval_dep');

		$output->info('Created openregister_config_values table');

	}//end createValuesTable()
}//end class
