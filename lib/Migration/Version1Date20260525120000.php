<?php

/**
 * Tier-2 flow (workflowengine) integration migration.
 *
 * Ensures the `openregister_flow_links` table exists with the full
 * Tier-2 schema:
 *
 *  - id (bigint, PK, autoincrement)
 *  - object_uuid (string 36, not null)
 *  - register_id (bigint unsigned, not null)
 *  - schema_id (bigint unsigned, nullable)
 *  - operation_id (bigint unsigned, not null) — flow_operations row id
 *  - operation_class (string 255, nullable) — concrete IOperation FQCN
 *  - operation_name (string 255, nullable) — cached operation display name
 *  - entity_type (string 255, nullable) — `OCA\WorkflowEngine\Entity\*`
 *  - enabled (boolean, default true)
 *  - linked_by (string 64, not null)
 *  - linked_at (datetime, not null)
 *
 * Idempotent: creates the table if missing, otherwise adds any missing
 * Tier-2 columns. Companion to the Tier-2 talk/polls/email/forms/deck
 * link tables; the wrapping `FlowLinkService` replaces the
 * `[or:{uuid}]` name-marker convention used by Tier-1 `FlowProvider`
 * with a proper persistence layer so links survive operation renames.
 *
 * Admin-gated: NC Flow operations are configured by admins only via
 * Workflow Settings. The link table just records "this admin pinned
 * operation X to OR object Y"; only admins can write rows (controller
 * enforces), but everyone can read so non-admins see automations.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
 * Tier-2 flow-links table — create-or-extend.
 */
class Version1Date20260525120000 extends SimpleMigrationStep {
	/**
	 * Change the database schema.
	 *
	 * @param IOutput $output Output for the migration process
	 * @param Closure $schemaClosure The schema closure
	 * @param array<array-key, mixed> $options Migration options
	 *
	 * @return ISchemaWrapper|null
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();
		$changed = false;

		if ($schema->hasTable('openregister_flow_links') === false) {
			$this->createFlowLinksTable(schema: $schema, output: $output);
			$changed = true;
		}

		if ($schema->hasTable('openregister_flow_links') === true
			&& $this->extendFlowLinksTable(schema: $schema, output: $output) === true
		) {
			$changed = true;
		}

		if ($changed === false) {
			return null;
		}

		return $schema;
	}//end changeSchema()

	/**
	 * Create the openregister_flow_links table at the Tier-2 shape.
	 *
	 * @param ISchemaWrapper $schema The schema wrapper
	 * @param IOutput $output Migration output
	 *
	 * @return void
	 */
	private function createFlowLinksTable(ISchemaWrapper $schema, IOutput $output): void {
		$table = $schema->createTable('openregister_flow_links');

		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('object_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('register_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('schema_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
		$table->addColumn('operation_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('operation_class', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
		$table->addColumn('operation_name', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
		$table->addColumn('entity_type', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
		$table->addColumn('enabled', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
		$table->addColumn('linked_by', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('linked_at', Types::DATETIME, ['notnull' => true]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['object_uuid', 'operation_id'], 'idx_flow_object_op');
		$table->addIndex(['object_uuid'], 'idx_flow_object');
		$table->addIndex(['operation_id'], 'idx_flow_op');
		$table->addIndex(['schema_id'], 'idx_flow_schema');

		$output->info('Created openregister_flow_links table (Tier-2 schema)');
	}//end createFlowLinksTable()

	/**
	 * Add any missing Tier-2 columns to an existing flow_links table.
	 *
	 * @param ISchemaWrapper $schema The schema wrapper
	 * @param IOutput $output Migration output
	 *
	 * @return bool True when a column was added.
	 */
	private function extendFlowLinksTable(ISchemaWrapper $schema, IOutput $output): bool {
		$table = $schema->getTable('openregister_flow_links');
		$changed = false;

		if ($table->hasColumn('schema_id') === false) {
			$table->addColumn(
				'schema_id',
				Types::BIGINT,
				['notnull' => false, 'unsigned' => true, 'default' => null]
			);
			$table->addIndex(['schema_id'], 'idx_flow_schema');
			$output->info('Added schema_id column to openregister_flow_links');
			$changed = true;
		}

		if ($table->hasColumn('operation_class') === false) {
			$table->addColumn('operation_class', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
			$output->info('Added operation_class column to openregister_flow_links');
			$changed = true;
		}

		if ($table->hasColumn('operation_name') === false) {
			$table->addColumn('operation_name', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
			$output->info('Added operation_name column to openregister_flow_links');
			$changed = true;
		}

		if ($table->hasColumn('entity_type') === false) {
			$table->addColumn('entity_type', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
			$output->info('Added entity_type column to openregister_flow_links');
			$changed = true;
		}

		if ($table->hasColumn('enabled') === false) {
			$table->addColumn('enabled', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
			$output->info('Added enabled column to openregister_flow_links');
			$changed = true;
		}

		return $changed;
	}//end extendFlowLinksTable()
}//end class
