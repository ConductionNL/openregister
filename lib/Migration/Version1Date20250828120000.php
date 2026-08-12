<?php

/**
 * OpenRegister Migration - Add Performance Indexes for Faceting
 *
 * This migration adds critical indexes to optimize faceting performance
 * and reduce query execution time from 7+ seconds to under 1 second.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to add performance indexes for faceting optimization
 *
 * This migration addresses critical performance bottlenecks in the faceting system
 * by adding proper indexes on frequently queried columns and composite indexes
 * for common filter combinations.
 *
 * All indexes (single-column and composite) are added exclusively through the
 * Doctrine DBAL schema-diff API (`$table->addIndex()`). An earlier revision of
 * this migration created the composite indexes via raw `CREATE INDEX` SQL
 * (`$connection->executeStatement()`) executed directly inside `changeSchema()`.
 * That is unsafe: on a fresh install Nextcloud's `Installer` runs
 * `MigrationService::migrate('latest', schemaOnly: true)`, which batches every
 * pending migration's `changeSchema()` call and applies the combined schema
 * diff in a single `migrateToSchema()` call at the very end. Raw SQL executed
 * mid-batch runs before `openregister_objects` (created by an earlier
 * migration) physically exists yet, so PostgreSQL fails every statement with
 * "relation \"oc_openregister_objects\" does not exist" — silently, because
 * the failure was caught, so a fresh NC32 install ended up with none of the
 * composite indexes below. The raw SQL also used MySQL-only column
 * prefix-length syntax (`register(20)`), which is invalid on PostgreSQL/SQLite
 * regardless of timing. Routing everything through `addIndex()` keeps the
 * change part of the deferred schema diff (safe for both the batched
 * schema-only install path and the per-step upgrade path) and portable across
 * all three supported database platforms.
 *
 * @package OCA\OpenRegister\Migration
 *
 * @spec exclude Bug-fix to a pre-existing migration's index-creation mechanism (raw SQL ->
 *       DBAL addIndex()); no new schema or behavior, restores stable32 CI (fixes silent
 *       "relation does not exist" errors on every fresh install).
 */
class Version1Date20250828120000 extends SimpleMigrationStep {
	/**
	 * Apply database schema changes for faceting performance.
	 *
	 * @param IOutput $output Output interface for logging
	 * @param Closure $schemaClosure Schema retrieval closure
	 * @param array $options Migration options
	 *
	 * @return null|ISchemaWrapper Modified schema or null
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Database migration requires checking many index conditions
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Database migration requires many index definitions
	 *
	 * @spec exclude See class-level note — index-creation mechanism fix only.
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		if ($schema->hasTable('openregister_objects') === false) {
			return null;
		}

		$table = $schema->getTable('openregister_objects');

		// 1. Critical single-column indexes for common faceting fields.
		// Note: 'deleted' column is JSON type and cannot have btree index in PostgreSQL.
		$singleIndexes = [
			// 'deleted'      => 'objects_deleted_idx',  // Skipped: JSON columns cannot have btree indexes in PostgreSQL.
			'published' => 'objects_published_idx',
			'depublished' => 'objects_depublished_idx',
			'created' => 'objects_created_idx',
			'updated' => 'objects_updated_idx',
			'owner' => 'objects_owner_idx',
			'organisation' => 'objects_organisation_idx',
		];

		foreach ($singleIndexes as $column => $indexName) {
			if ($table->hasColumn($column) === true && $table->hasIndex($indexName) === false) {
				$table->addIndex([$column], $indexName);
				$output->info(message: "Added index {$indexName} on column {$column}");
			}
		}

		// 2. Critical composite indexes for common filter combinations.
		// Note: no column length prefixes (e.g. MySQL's `register(20)`) — those are
		// MySQL-only syntax, invalid on PostgreSQL/SQLite, and DBAL's addIndex() schema
		// diff has no portable way to express them. `register`/`schema`/`organisation`
		// are VARCHAR(255); combined with MySQL 8.0's default DYNAMIC row format
		// (3072-byte index key limit, vs. the legacy 767-byte limit), a full-column
		// composite index comfortably fits, so dropping the prefix is safe.
		$compositeIndexes = [
			// For base filtering (published state).
			// Note: Removed 'deleted' column from all composite indexes because it's JSON type
			// and cannot be part of btree indexes in PostgreSQL.
			// 'objects_deleted_published_idx'       => ['deleted', 'published'],
			// 'objects_lifecycle_idx'               => ['deleted', 'published', 'depublished'],.
			'objects_published_depublished_idx' => ['published', 'depublished'],

			// For register/schema filtering with lifecycle.
			// 'objects_register_schema_deleted_idx' => ['register', 'schema', 'deleted'],
			// 'objects_register_lifecycle_idx'      => ['register', 'deleted', 'published'],
			// 'objects_schema_lifecycle_idx'        => ['schema', 'deleted', 'published'],.
			'objects_register_schema_published_idx' => ['register', 'schema', 'published'],
			'objects_register_published_idx' => ['register', 'published'],
			'objects_schema_published_idx' => ['schema', 'published'],

			// For organisation-based filtering.
			// 'objects_org_lifecycle_idx'           => ['organisation', 'deleted', 'published'],.
			'objects_org_published_idx' => ['organisation', 'published'],

			// For date range queries on faceting.
			// 'objects_created_deleted_idx'         => ['created', 'deleted'],
			// 'objects_updated_deleted_idx'         => ['updated', 'deleted'],.
			'objects_created_published_idx' => ['created', 'published'],
			'objects_updated_published_idx' => ['updated', 'published'],
		];

		foreach ($compositeIndexes as $indexName => $columns) {
			// Check if index already exists (covers installs upgrading from a version
			// that created it, successfully or not, via the old raw-SQL path).
			if ($table->hasIndex($indexName) === true) {
				continue;
			}

			$allColumnsExist = true;
			foreach ($columns as $column) {
				if ($table->hasColumn($column) === false) {
					$allColumnsExist = false;
					break;
				}
			}

			if ($allColumnsExist === true) {
				$table->addIndex($columns, $indexName);
				$output->info(message: "Added composite index {$indexName} on columns: " . implode(', ', $columns));
			}
		}//end foreach

		return $schema;
	}//end changeSchema()
}//end class
