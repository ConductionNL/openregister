<?php

/**
 * Database migration for SaaS multi-tenant support.
 *
 * Adds tenant lifecycle fields (status, environment, timestamps) to the
 * openregister_organisations table and creates the openregister_tenant_usage
 * table for quota tracking.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
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
 * Adds tenant lifecycle and OTAP fields to organisations, creates tenant usage table.
 *
 * @package OCA\OpenRegister\Migration
 */
class Version1Date20260322000000 extends SimpleMigrationStep
{
    /**
     * Change the database schema.
     *
     * @param IOutput $output        Migration output
     * @param Closure $schemaClosure Schema closure
     * @param array   $options       Migration options
     *
     * @return ISchemaWrapper|null The updated schema or null if no changes
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $schema  = $schemaClosure();
        $changed = false;

        $changed = $this->addOrganisationColumns($schema, $output) || $changed;
        $changed = $this->createTenantUsageTable($schema, $output) || $changed;

        if ($changed === true) {
            return $schema;
        }

        return null;
    }//end changeSchema()

    /**
     * Add lifecycle and environment columns to organisations table.
     *
     * @param ISchemaWrapper $schema The schema wrapper
     * @param IOutput        $output Migration output
     *
     * @return bool Whether any changes were made
     */
    private function addOrganisationColumns(ISchemaWrapper $schema, IOutput $output): bool
    {
        $tableName = 'openregister_organisations';

        if ($schema->hasTable($tableName) === false) {
            $output->info("Table {$tableName} does not exist, skipping");
            return false;
        }

        $table   = $schema->getTable($tableName);
        $changed = false;

        if ($table->hasColumn('status') === false) {
            $table->addColumn(
                    'status',
                    Types::STRING,
                    [
                        'notnull' => true,
                        'length'  => 20,
                        'default' => 'active',
                        'comment' => 'Tenant lifecycle status: provisioning, active, suspended, deprovisioning, archived',
                    ]
                    );
            $output->info("Added 'status' column to {$tableName}");
            $changed = true;
        }

        if ($table->hasColumn('environment') === false) {
            $table->addColumn(
                    'environment',
                    Types::STRING,
                    [
                        'notnull' => true,
                        'length'  => 20,
                        'default' => 'production',
                        'comment' => 'OTAP environment: development, test, acceptance, production',
                    ]
                    );
            $output->info("Added 'environment' column to {$tableName}");
            $changed = true;
        }

        if ($table->hasColumn('provisioned_at') === false) {
            $table->addColumn(
                    'provisioned_at',
                    Types::DATETIME,
                    [
                        'notnull' => false,
                        'default' => null,
                        'comment' => 'Timestamp when organisation was provisioned',
                    ]
                    );
            $output->info("Added 'provisioned_at' column to {$tableName}");
            $changed = true;
        }

        if ($table->hasColumn('suspended_at') === false) {
            $table->addColumn(
                    'suspended_at',
                    Types::DATETIME,
                    [
                        'notnull' => false,
                        'default' => null,
                        'comment' => 'Timestamp when organisation was suspended',
                    ]
                    );
            $output->info("Added 'suspended_at' column to {$tableName}");
            $changed = true;
        }

        if ($table->hasColumn('deprovisioned_at') === false) {
            $table->addColumn(
                    'deprovisioned_at',
                    Types::DATETIME,
                    [
                        'notnull' => false,
                        'default' => null,
                        'comment' => 'Timestamp when organisation deprovisioning started',
                    ]
                    );
            $output->info("Added 'deprovisioned_at' column to {$tableName}");
            $changed = true;
        }

        return $changed;
    }//end addOrganisationColumns()

    /**
     * Create the tenant usage tracking table.
     *
     * @param ISchemaWrapper $schema The schema wrapper
     * @param IOutput        $output Migration output
     *
     * @return bool Whether any changes were made
     */
    private function createTenantUsageTable(ISchemaWrapper $schema, IOutput $output): bool
    {
        $tableName = 'openregister_tenant_usage';

        if ($schema->hasTable($tableName) === true) {
            $output->info("Table {$tableName} already exists, skipping");
            return false;
        }

        $table = $schema->createTable($tableName);

        $table->addColumn(
                'id',
                Types::BIGINT,
                [
                    'autoincrement' => true,
                    'notnull'       => true,
                ]
                );
        $table->addColumn(
                'organisation_uuid',
                Types::STRING,
                [
                    'notnull' => true,
                    'length'  => 36,
                    'comment' => 'UUID of the organisation',
                ]
                );
        $table->addColumn(
                'period',
                Types::DATETIME,
                [
                    'notnull' => true,
                    'comment' => 'Hourly bucket timestamp for usage aggregation',
                ]
                );
        $table->addColumn(
                'request_count',
                Types::BIGINT,
                [
                    'notnull' => true,
                    'default' => 0,
                    'comment' => 'Number of API requests in this period',
                ]
                );
        $table->addColumn(
                'bandwidth_bytes',
                Types::BIGINT,
                [
                    'notnull' => true,
                    'default' => 0,
                    'comment' => 'Total response bandwidth in bytes for this period',
                ]
                );
        $table->addColumn(
                'storage_bytes',
                Types::BIGINT,
                [
                    'notnull' => true,
                    'default' => 0,
                    'comment' => 'Total storage usage in bytes at time of recording',
                ]
                );
        $table->addColumn(
                'created',
                Types::DATETIME,
                [
                    'notnull' => false,
                    'default' => null,
                ]
                );
        $table->addColumn(
                'updated',
                Types::DATETIME,
                [
                    'notnull' => false,
                    'default' => null,
                ]
                );

        $table->setPrimaryKey(['id']);
        $table->addIndex(['organisation_uuid'], 'or_tu_org_uuid_idx');
        $table->addIndex(['period'], 'or_tu_period_idx');
        $table->addUniqueIndex(
            ['organisation_uuid', 'period'],
            'or_tu_org_period_idx'
        );

        $output->info("Created table {$tableName}");

        return true;
    }//end createTenantUsageTable()
}//end class
