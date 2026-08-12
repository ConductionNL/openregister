<?php

/**
 * Integration-leaf foundation tables — public case-token links + page-level
 * analytics series.
 *
 * Creates two additive tables backing the two leaf-foundation surfaces:
 *
 *  - openregister_case_tokens: one row per public "track your case" link.
 *    Binds an opaque token to an object (register / schema / uuid) with a
 *    lifecycle (created / expires / revoked) so the anonymous resolve
 *    endpoint can fail-closed.
 *  - openregister_analytics_series: one row per page-level chart series a
 *    leaf registers (labels + datasets JSON, chart type, visibility scope)
 *    so a dashboard widget can render pre-computed series without a
 *    per-object roundtrip.
 *
 * Idempotent: each table is created only when absent. Purely additive —
 * no existing table is touched.
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
 * @spec openspec/specs/integration-leaf-foundation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create the case-token and analytics-series tables.
 *
 * @spec openspec/specs/integration-leaf-foundation/spec.md
 */
class Version1Date20260615000000 extends SimpleMigrationStep {
	/**
	 * Change the database schema.
	 *
	 * @param IOutput $output Output for the migration process
	 * @param Closure $schemaClosure The schema closure
	 * @param array<array-key, mixed> $options Migration options
	 *
	 * @return ISchemaWrapper|null
	 *
	 * @spec openspec/specs/integration-leaf-foundation/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		if ($schema->hasTable('openregister_case_tokens') === false) {
			$table = $schema->createTable('openregister_case_tokens');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('token', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('object_uuid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('register_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('schema_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('label', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('expires_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('revoked_at', Types::DATETIME, ['notnull' => false]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['token'], 'idx_or_casetok_token');
			$table->addIndex(['object_uuid'], 'idx_or_casetok_object');

			$output->info('Created openregister_case_tokens table');
		}//end if

		if ($schema->hasTable('openregister_analytics_series') === false) {
			$table = $schema->createTable('openregister_analytics_series');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('series_key', Types::STRING, ['notnull' => true, 'length' => 128]);
			$table->addColumn('register_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('schema_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('title', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('chart_type', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'line']);
			$table->addColumn('labels', Types::TEXT, ['notnull' => false]);
			$table->addColumn('datasets', Types::TEXT, ['notnull' => false]);
			$table->addColumn('visibility', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'private']);
			$table->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('updated_at', Types::DATETIME, ['notnull' => false]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['series_key'], 'idx_or_anaser_key');
			$table->addIndex(['register_id', 'schema_id'], 'idx_or_anaser_scope');

			$output->info('Created openregister_analytics_series table');
		}//end if

		return $schema;
	}//end changeSchema()
}//end class
