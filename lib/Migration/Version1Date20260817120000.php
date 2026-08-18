<?php

/**
 * OpenRegister Schema Smart Picker Enabled Property Migration
 *
 * This migration adds a 'smart_picker_enabled' boolean column to the schemas
 * table to control whether a schema-scoped Smart Picker provider (registered
 * by a consuming app) is functionally active for objects of that schema.
 * Defaults to false: a schema must opt in, unlike the `searchable` column's
 * opt-out default.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/schema-scoped-smart-picker/design.md#d2a
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to add smart_picker_enabled column to schemas table
 *
 * This migration adds a boolean column to gate schema-scoped Smart Picker
 * provider functionality per schema:
 * - smart_picker_enabled: Boolean flag (default false) opting a schema in to
 *   its own Smart Picker entry's functionality
 * - Maintains backward compatibility: existing schemas default to false, so
 *   no schema is affected until an admin explicitly opts in
 */
class Version1Date20260817120000 extends SimpleMigrationStep {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $connection Database connection
	 */
	public function __construct(
		private readonly IDBConnection $connection,
	) {
	}//end __construct()

	/**
	 * Add smart_picker_enabled column to schemas table
	 *
	 * @param IOutput $output Migration output interface
	 * @param Closure $schemaClosure Schema closure
	 * @param array $options Migration options
	 *
	 * @return ISchemaWrapper|null Updated schema
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		$output->info(message: '🔧 Adding smart_picker_enabled column to schemas table...');

		if ($schema->hasTable('openregister_schemas') === true) {
			$table = $schema->getTable('openregister_schemas');

			if ($table->hasColumn('smart_picker_enabled') === false) {
				$table->addColumn(
					'smart_picker_enabled',
					Types::BOOLEAN,
					[
						'notnull' => true,
						'default' => false,
						'comment' => 'Whether a schema-scoped Smart Picker provider is functionally active for this schema',
					]
				);

				$output->info(message: '✅ Added smart_picker_enabled column with default value false');
				$output->info('🎯 This enables per-schema Smart Picker provider gating:');
				$output->info(message: '   • smart_picker_enabled = true  → schema-scoped Smart Picker provider is active');
				$output->info(message: '   • smart_picker_enabled = false → schema-scoped Smart Picker provider is functionally inert');
				$output->info(message: '🚀 Existing schemas default to false — opt-in, unlike the searchable flag!');

				return $schema;
			}

			$output->info(message: 'ℹ️  smart_picker_enabled column already exists, skipping...');
			return null;
		}//end if

		$output->info(message: '⚠️  Schemas table not found, skipping smart_picker_enabled column addition');

		return null;
	}//end changeSchema()

	/**
	 * Ensure all existing schemas have smart_picker_enabled set to false
	 *
	 * @param IOutput $output Migration output interface
	 * @param Closure $schemaClosure Schema closure
	 * @param array $options Migration options
	 *
	 * @return void
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$output->info(message: '🔧 Verifying existing schemas default to smart_picker_enabled = false...');

		// Since we added the column with default value false and notnull
		// constraint, all existing records should already have
		// smart_picker_enabled = 0.
		try {
			$sql = 'SELECT COUNT(*) as total FROM `oc_openregister_schemas`';
			$result = $this->connection->executeQuery($sql);
			$row = $result->fetch();
			$totalSchemas = $row['total'] ?? 0;

			if ($totalSchemas > 0) {
				$schemaMsg = "Found {$totalSchemas} existing schemas - all automatically set to smart_picker_enabled=false";
				$output->info(message: $schemaMsg);
			}

			if ($totalSchemas === 0) {
				$output->info(message: 'ℹ️  No existing schemas found - ready for new schemas with Smart Picker gating');
			}

			$output->info(message: '🎯 All schemas are now properly configured for schema-scoped Smart Picker gating');
		} catch (\Exception $e) {
			$output->info('❌ Failed to verify schemas: ' . $e->getMessage());
			$output->info(message: '⚠️  This may indicate an issue with the smart_picker_enabled column');
			$output->info('💡 Manual check: SELECT smart_picker_enabled FROM oc_openregister_schemas LIMIT 1');
		}//end try
	}//end postSchemaChange()
}//end class
