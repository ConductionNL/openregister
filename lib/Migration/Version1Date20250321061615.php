<?php
/**
 * Migration to add and modify columns in various tables and drop unused tables.
 *
 * This migration adds columns to the openregister_objects, openregister_schemas,
 * openregister_registers, and openregister_audit_trails tables. It also drops the
 * openregister_object_audit_logs table as it is no longer used.
 *
 * @category  Migration
 * @package   OCA\OpenRegister\Migration
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1Date20250321061615 extends SimpleMigrationStep
{
    /**
     * Pre-schema change operations.
     *
     * @param IOutput $output
     * @param Closure(): ISchemaWrapper $schemaClosure
     * @param array $options
     */
    public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        // No pre-schema changes required
    }

    /**
     * Change schema by adding and modifying columns, and dropping unused tables.
     *
     * @param IOutput $output
     * @param Closure(): ISchemaWrapper $schemaClosure
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Update the openregister_objects table
        $table = $schema->getTable('openregister_objects');
        
        // Add organisation column to store organisation name
        if ($table->hasColumn('organisation') === FALSE) {
            $table->addColumn('organisation', Types::STRING, [
                'notnull' => FALSE,
                'length' => 255,
            ]);
        }

        // Add application column to store application name
        if ($table->hasColumn('application') === FALSE) {
            $table->addColumn('application', Types::STRING, [
                'notnull' => FALSE,
                'length' => 255,
            ]);
        }

        // Add validation column to store validation rules in JSON format
        if ($table->hasColumn('validation') === FALSE) {
            $table->addColumn('validation', Types::JSON, [
                'notnull' => FALSE,
            ]);
        }

        // Add deleted column to store deletion details in JSON format
        if ($table->hasColumn('deleted') === FALSE) {
            $table->addColumn('deleted', Types::JSON, [
                'notnull' => FALSE,
            ]);
        }

        // Add geo column to store geo data in JSON format
        if ($table->hasColumn('geo') === FALSE) {
            $table->addColumn('geo', Types::JSON, [
                'notnull' => FALSE,
            ]);
        }

        // Add retention column to store retention data in JSON format
        if ($table->hasColumn('retention') === FALSE) {
            $table->addColumn('retention', Types::JSON, [
                'notnull' => FALSE,
            ]);
        }

        // Update the openregister_schemas table
        $table = $schema->getTable('openregister_schemas');
        
        // Add slug column to store unique identifier for objects
        if ($table->hasColumn('slug') === FALSE) {
            $table->addColumn('slug', Types::STRING, [
                'notnull' => FALSE,
                'length' => 255,
            ]);
        }

        // Add owner column to store the Nextcloud user that owns this schema
        if ($table->hasColumn('owner') === FALSE) {
            $table->addColumn('owner', Types::STRING, [
                'notnull' => FALSE,
                'length' => 255,
            ]);
        }

        // Add application column to store application name
        if ($table->hasColumn('application') === FALSE) {
            $table->addColumn('application', Types::STRING, [
                'notnull' => FALSE,
                'length' => 255,
            ]);
        }

        // Add organisation column to store organisation name
        if ($table->hasColumn('organisation') === FALSE) {
            $table->addColumn('organisation', Types::STRING, [
                'notnull' => FALSE,
                'length' => 255,
            ]);
        }

        // Add authorization column to store authorization rules in JSON format
        if ($table->hasColumn('authorization') === FALSE) {
            $table->addColumn('authorization', Types::JSON, [
                'notnull' => FALSE,
            ]);
        }

        // Add deleted column to store deletion timestamp
        if ($table->hasColumn('deleted') === FALSE) {
            $table->addColumn('deleted', Types::DATETIME, [
                'notnull' => FALSE,
            ]);
        }

        // Update the openregister_registers table
        $table = $schema->getTable('openregister_registers');

        // Add slug column to store unique identifier for registers
        if ($table->hasColumn('slug') === FALSE) {
            $table->addColumn('slug', Types::STRING, [
                'notnull' => FALSE,
                'length' => 255,
            ]);
        }

        // Add owner column to store the Nextcloud user that owns this register
        if ($table->hasColumn('owner') === FALSE) {
            $table->addColumn('owner', Types::STRING, [
                'notnull' => FALSE,
                'length' => 255,
            ]);
        }

        // Add application column to store application name
        if ($table->hasColumn('application') === FALSE) {
            $table->addColumn('application', Types::STRING, [
                'notnull' => FALSE,
                'length' => 255,
            ]);
        }

        // Add organisation column to store organisation name
        if ($table->hasColumn('organisation') === FALSE) {
            $table->addColumn('organisation', Types::STRING, [
                'notnull' => FALSE,
                'length' => 255,
            ]);
        }

        // Add authorization column to store authorization rules in JSON format
        if ($table->hasColumn('authorization') === FALSE) {
            $table->addColumn('authorization', Types::JSON, [
                'notnull' => FALSE,
            ]);
        }

        // Add deleted column to store deletion timestamp
        if ($table->hasColumn('deleted') === FALSE) {
            $table->addColumn('deleted', Types::DATETIME, [
                'notnull' => FALSE,
            ]);
        }

        // Update the openregister_audit_trails table
        $table = $schema->getTable('openregister_audit_trails');

        // Add object_uuid column to store unique identifier for objects
        if ($table->hasColumn('object_uuid') === FALSE) {
            $table->addColumn('object_uuid', Types::STRING, ['notnull' => FALSE, 'length' => 255]);
        }

        // Add register_uuid column to store unique identifier for registers
        if ($table->hasColumn('register_uuid') === FALSE) {
            $table->addColumn('register_uuid', Types::STRING, ['notnull' => FALSE, 'length' => 255]);
        }

        // Add schema_uuid column to store unique identifier for schemas
        if ($table->hasColumn('schema_uuid') === FALSE) {
            $table->addColumn('schema_uuid', Types::STRING, ['notnull' => FALSE, 'length' => 255]);
        }

        // Add organisation_id column to store the organization identifier (OIN, RSIN, KVK, etc.)
        if ($table->hasColumn('organisation_id') === FALSE) {
            $table->addColumn('organisation_id', Types::STRING, [
                'notnull' => FALSE,
                'length' => 255,
            ]);
        }

        // Add organisation_id_type column to store the type of organization identifier used
        if ($table->hasColumn('organisation_id_type') === FALSE) {
            $table->addColumn('organisation_id_type', Types::STRING, [
                'notnull' => FALSE,
                'length' => 50,
            ]);
        }

        // Add processing_activity_id column to store Processing Activity ID
        if ($table->hasColumn('processing_activity_id') === FALSE) {
            $table->addColumn('processing_activity_id', Types::STRING, [
                'notnull' => FALSE,
                'length' => 255,
            ]);
        }

        // Add processing_activity_url column to store Processing Activity URL
        if ($table->hasColumn('processing_activity_url') === FALSE) {
            $table->addColumn('processing_activity_url', Types::STRING, [
                'notnull' => FALSE,
                'length' => 255,
            ]);
        }

        // Add processing_id column to store Processing ID
        if ($table->hasColumn('processing_id') === FALSE) {
            $table->addColumn('processing_id', Types::STRING, [
                'notnull' => FALSE,
                'length' => 255,
            ]);
        }

        // Add confidentiality column to store data confidentiality level
        if ($table->hasColumn('confidentiality') === FALSE) {
            $table->addColumn('confidentiality', Types::STRING, [
                'notnull' => FALSE,
                'length' => 255,
            ]);
        }

        // Add retention_period column to store data retention period
        if ($table->hasColumn('retention_period') === FALSE) {
            $table->addColumn('retention_period', Types::STRING, [
                'notnull' => FALSE,
                'length' => 255,
            ]);
        }

        // Drop the openregister_object_audit_logs table as it is no longer used
        if ($schema->hasTable('openregister_object_audit_logs')) {
            $schema->dropTable('openregister_object_audit_logs');
        }

        return $schema;
    }

    /**
     * Post-schema change operations.
     *
     * @param IOutput $output
     * @param Closure(): ISchemaWrapper $schemaClosure
     * @param array $options
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        // No post-schema changes required
    }
}
