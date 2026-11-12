<?php

/**
 * Migration to add and modify columns in various tables and drop unused tables.
 *
 * This migration adds columns to the openregister_objects, openregister_schemas,
 * openregister_registers, and openregister_audit_trails tables. It also drops the
 * openregister_object_audit_logs table as it is no longer used.
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
use Doctrine\DBAL\Schema\Table;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Schema\ITable;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1Date20250321061615 extends SimpleMigrationStep {
	/**
	 * Pre-schema change operations.
	 *
	 * @param IOutput $output Output interface for migration progress
	 * @param Closure(): ISchemaWrapper $schemaClosure Schema closure function
	 * @param array $options Migration options
	 *
	 * @return void
	 */
	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		// No pre-schema changes required.
	}//end preSchemaChange()

	/**
	 * Change schema by adding and modifying columns, and dropping unused tables.
	 *
	 * @param IOutput $output Output interface for migration progress
	 * @param Closure(): ISchemaWrapper $schemaClosure Schema closure function
	 * @param array $options Migration options
	 *
	 * @return ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		$string255 = ['notnull' => false, 'length' => 255];
		$nullable  = ['notnull' => false];

		// Organisation, application, validation rules, deletion details, geo data and retention data.
		$this->addMissingColumns(
			table: $schema->getTable('openregister_objects'),
			columns: [
				['organisation', Types::STRING, $string255],
				['application', Types::STRING, $string255],
				['validation', Types::JSON, $nullable],
				['deleted', Types::JSON, $nullable],
				['geo', Types::JSON, $nullable],
				['retention', Types::JSON, $nullable],
			]
		);

		// Slug, owning Nextcloud user, application, organisation, authorization rules and deletion timestamp.
		$ownedColumns = [
			['slug', Types::STRING, $string255],
			['owner', Types::STRING, $string255],
			['application', Types::STRING, $string255],
			['organisation', Types::STRING, $string255],
			['authorization', Types::JSON, $nullable],
			['deleted', Types::DATETIME, $nullable],
		];
		$this->addMissingColumns(table: $schema->getTable('openregister_schemas'), columns: $ownedColumns);
		$this->addMissingColumns(table: $schema->getTable('openregister_registers'), columns: $ownedColumns);

		// Object, register and schema identifiers, the organisation identifier (OIN, RSIN, KVK, etc.)
		// and its type, the processing activity, confidentiality level and retention period.
		$this->addMissingColumns(
			table: $schema->getTable('openregister_audit_trails'),
			columns: [
				['object_uuid', Types::STRING, $string255],
				['register_uuid', Types::STRING, $string255],
				['schema_uuid', Types::STRING, $string255],
				['organisation_id', Types::STRING, $string255],
				['organisation_id_type', Types::STRING, ['notnull' => false, 'length' => 50]],
				['processing_activity_id', Types::STRING, $string255],
				['processing_activity_url', Types::STRING, $string255],
				['processing_id', Types::STRING, $string255],
				['confidentiality', Types::STRING, $string255],
				['retention_period', Types::STRING, $string255],
			]
		);

		// Drop the openregister_object_audit_logs table as it is no longer used.
		if ($schema->hasTable('openregister_object_audit_logs') === true) {
			$schema->dropTable('openregister_object_audit_logs');
		}

		return $schema;
	}//end changeSchema()

	/**
	 * Add each column the table does not have yet, in the given order.
	 *
	 * No native type hint on $table: ISchemaWrapper returns Doctrine's Table before
	 * Nextcloud 35 and ITable from 35 on, and a hint for either is a TypeError on the other.
	 *
	 * @param ITable|Table                                      $table   The table to extend.
	 * @param array<int, array{0: string, 1: string, 2: array}> $columns Name, type and options per column.
	 *
	 * @return void
	 */
	private function addMissingColumns($table, array $columns): void {
		foreach ($columns as [$name, $type, $columnOptions]) {
			if ($table->hasColumn($name) === false) {
				$table->addColumn($name, $type, $columnOptions);
			}
		}
	}//end addMissingColumns()

	/**
	 * Post-schema change operations.
	 *
	 * @param IOutput $output Output interface for migration progress
	 * @param Closure(): ISchemaWrapper $schemaClosure Schema closure function
	 * @param array $options Migration options
	 *
	 * @return void
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		// No post-schema changes required.
	}//end postSchemaChange()
}//end class
