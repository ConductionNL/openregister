<?php

/**
 * Database migration creating the handoff queue table.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * `oc_openregister_handoff_queue` backs `whenUnavailable: queue` handoffs
 * (ADR-051 semantic-object-handoff): a handoff triggered while no installed
 * schema implements the target kind is parked here and drained when a
 * provider appears (schema-save / app-enable listeners + a fallback
 * TimedJob). Mirrors the WebhookLog durable pattern.
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
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates the `openregister_handoff_queue` table (idempotent).
 *
 * @package OCA\OpenRegister\Migration
 */
class Version1Date20260706000000 extends SimpleMigrationStep {
	/**
	 * Change the database schema.
	 *
	 * @param IOutput $output Migration output.
	 * @param Closure $schemaClosure Schema closure returning an ISchemaWrapper.
	 * @param array $options Migration options.
	 *
	 * @return ISchemaWrapper|null The updated schema, or null if no changes.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Scenario: No provider installed, queue mode)
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if ($schema->hasTable('openregister_handoff_queue') === true) {
			return null;
		}

		$table = $schema->createTable('openregister_handoff_queue');

		$table->addColumn(
			'id',
			Types::BIGINT,
			['autoincrement' => true, 'notnull' => true, 'length' => 20]
		);
		$table->addColumn(
			'source_object_uuid',
			Types::STRING,
			['notnull' => true, 'length' => 255]
		);
		$table->addColumn(
			'source_register',
			Types::INTEGER,
			['notnull' => true, 'default' => 0]
		);
		$table->addColumn(
			'source_schema',
			Types::INTEGER,
			['notnull' => true, 'default' => 0]
		);
		$table->addColumn(
			'handoff_id',
			Types::STRING,
			['notnull' => true, 'length' => 128]
		);
		$table->addColumn(
			'target_kind',
			Types::STRING,
			['notnull' => true, 'length' => 255]
		);
		$table->addColumn(
			'requesting_user',
			Types::STRING,
			['notnull' => true, 'length' => 64]
		);
		$table->addColumn(
			'correlation_id',
			Types::STRING,
			['notnull' => true, 'length' => 64]
		);
		$table->addColumn(
			'mapping_hash',
			Types::STRING,
			['notnull' => false, 'length' => 64, 'default' => null]
		);
		$table->addColumn(
			'status',
			Types::STRING,
			['notnull' => true, 'length' => 32, 'default' => 'parked']
		);
		$table->addColumn(
			'attempt',
			Types::INTEGER,
			['notnull' => true, 'default' => 0]
		);
		$table->addColumn(
			'last_error',
			Types::TEXT,
			['notnull' => false, 'default' => null]
		);
		$table->addColumn(
			'created',
			Types::DATETIME,
			['notnull' => true]
		);
		$table->addColumn(
			'last_attempt_at',
			Types::DATETIME,
			['notnull' => false, 'default' => null]
		);
		$table->addColumn(
			'executed_at',
			Types::DATETIME,
			['notnull' => false, 'default' => null]
		);

		$table->setPrimaryKey(['id']);
		$table->addIndex(['status', 'target_kind'], 'or_handoffq_status_kind');
		$table->addIndex(['source_object_uuid'], 'or_handoffq_src_uuid');

		$output->info('   ✓ Created openregister_handoff_queue table');

		return $schema;
	}//end changeSchema()
}//end class
