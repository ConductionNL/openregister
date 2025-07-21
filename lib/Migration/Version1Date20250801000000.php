<?php
/**
 * OpenRegister Multi-Tenancy Migration
 *
 * This migration completes the multi-tenancy implementation by:
 * 1. Adding users, isDefault, and owner fields to Organisation table
 * 2. Creating a default organisation if none exists
 * 3. Setting all existing registers, schemas, and objects to the default organisation
 * 4. Making organisation and owner fields mandatory (non-nullable)
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conductio.nl>
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
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use OCP\IDBConnection;

/**
 * Migration to complete multi-tenancy implementation
 */
class Version1Date20250801000000 extends SimpleMigrationStep
{
    /**
     * Database connection
     *
     * @var IDBConnection
     */
    private IDBConnection $connection;

    /**
     * Constructor
     *
     * @param IDBConnection $connection Database connection
     */
    public function __construct(IDBConnection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Pre-schema change operations
     *
     * @param IOutput                   $output
     * @param Closure(): ISchemaWrapper $schemaClosure
     * @param array                     $options
     *
     * @return void
     */
    public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        $output->info('Starting multi-tenancy migration...');
    }

    /**
     * Apply schema changes for multi-tenancy
     *
     * @param IOutput                   $output
     * @param Closure(): ISchemaWrapper $schemaClosure
     * @param array                     $options
     *
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // 1. Add new fields to organisations table
        if ($schema->hasTable('openregister_organisations')) {
            $table = $schema->getTable('openregister_organisations');
            
            // Add users field (JSON array of user IDs)
            if (!$table->hasColumn('users')) {
                $table->addColumn('users', Types::JSON, [
                    'notnull' => false,
                    'default' => '[]'
                ]);
                $output->info('Added users column to organisations table');
            }

            // Add isDefault field (boolean flag for default organisation)
            if (!$table->hasColumn('isDefault')) {
                $table->addColumn('isDefault', Types::BOOLEAN, [
                    'notnull' => true,
                    'default' => false
                ]);
                $output->info('Added isDefault column to organisations table');
            }

            // Add owner field (user ID who owns the organisation)
            if (!$table->hasColumn('owner')) {
                $table->addColumn('owner', Types::STRING, [
                    'notnull' => false,
                    'length' => 255
                ]);
                $output->info('Added owner column to organisations table');
            }
        }

        return $schema;
    }

    /**
     * Post-schema change operations - Data migration and constraints
     *
     * @param IOutput                   $output
     * @param Closure(): ISchemaWrapper $schemaClosure
     * @param array                     $options
     *
     * @return void
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        // Step 1: Ensure default organisation exists
        $defaultOrgId = $this->ensureDefaultOrganisation($output);

        // Step 2: Update existing records to have organisation and owner
        $this->updateExistingRecords($output, $defaultOrgId);

        // Step 3: Make organisation and owner fields mandatory
        $this->makeFieldsMandatory($output, $schemaClosure);

        $output->info('Multi-tenancy migration completed successfully!');
    }

    /**
     * Ensure a default organisation exists
     *
     * @param IOutput $output Migration output
     *
     * @return int The ID of the default organisation
     */
    private function ensureDefaultOrganisation(IOutput $output): int
    {
        // Check if default organisation already exists
        $qb = $this->connection->getQueryBuilder();
        $qb->select('id')
           ->from('openregister_organisations')
           ->where($qb->expr()->eq('isDefault', $qb->createNamedParameter(true, \PDO::PARAM_BOOL)));

        $result = $qb->executeQuery();
        $defaultOrg = $result->fetchOne();
        $result->closeCursor();

        if ($defaultOrg) {
            $output->info('Default organisation already exists with ID: ' . $defaultOrg);
            return (int) $defaultOrg;
        }

        // Create default organisation
        $uuid = bin2hex(random_bytes(16));
        $uuid = sprintf('%08s-%04s-%04x-%04x-%12s',
            substr($uuid, 0, 8),
            substr($uuid, 8, 4),
            (hexdec(substr($uuid, 12, 4)) & 0x0fff) | 0x4000,
            (hexdec(substr($uuid, 16, 4)) & 0x3fff) | 0x8000,
            substr($uuid, 20, 12)
        );
        $now = new \DateTime();

        $qb = $this->connection->getQueryBuilder();
        $qb->insert('openregister_organisations')
           ->values([
               'uuid' => $qb->createNamedParameter($uuid),
               'name' => $qb->createNamedParameter('Default Organisation'),
               'description' => $qb->createNamedParameter('Default organisation for users without specific organisation membership'),
               'users' => $qb->createNamedParameter('[]'),
               'isDefault' => $qb->createNamedParameter(true, \PDO::PARAM_BOOL),
               'owner' => $qb->createNamedParameter('system'),
               'created' => $qb->createNamedParameter($now, Types::DATETIME),
               'updated' => $qb->createNamedParameter($now, Types::DATETIME)
           ]);

        $qb->executeStatement();
        $defaultOrgId = $this->connection->lastInsertId('openregister_organisations');

        $output->info('Created default organisation with ID: ' . $defaultOrgId);
        return (int) $defaultOrgId;
    }

    /**
     * Update existing records to have organisation and owner
     *
     * @param IOutput $output        Migration output
     * @param int     $defaultOrgId  ID of the default organisation
     *
     * @return void
     */
    private function updateExistingRecords(IOutput $output, int $defaultOrgId): void
    {
        $defaultOrgUuid = $this->getOrganisationUuid($defaultOrgId);

        // Update registers without organisation
        $updated = $this->updateTable('openregister_registers', $defaultOrgUuid, $output);
        $output->info("Updated $updated registers with default organisation");

        // Update schemas without organisation
        $updated = $this->updateTable('openregister_schemas', $defaultOrgUuid, $output);
        $output->info("Updated $updated schemas with default organisation");

        // Update objects without organisation
        $updated = $this->updateTable('openregister_objects', $defaultOrgUuid, $output);
        $output->info("Updated $updated objects with default organisation");
    }

    /**
     * Update a specific table with default organisation
     *
     * @param string  $tableName      Table to update
     * @param string  $defaultOrgUuid UUID of default organisation
     * @param IOutput $output         Migration output
     *
     * @return int Number of updated records
     */
    private function updateTable(string $tableName, string $defaultOrgUuid, IOutput $output): int
    {
        // Set organisation for records without one
        $qb = $this->connection->getQueryBuilder();
        $qb->update($tableName)
           ->set('organisation', $qb->createNamedParameter($defaultOrgUuid))
           ->where($qb->expr()->orX(
               $qb->expr()->isNull('organisation'),
               $qb->expr()->eq('organisation', $qb->createNamedParameter(''))
           ));

        $organisationUpdated = $qb->executeStatement();

        // Set owner for records without one (use 'system' as default)
        $qb = $this->connection->getQueryBuilder();
        $qb->update($tableName)
           ->set('owner', $qb->createNamedParameter('system'))
           ->where($qb->expr()->orX(
               $qb->expr()->isNull('owner'),
               $qb->expr()->eq('owner', $qb->createNamedParameter(''))
           ));

        $ownerUpdated = $qb->executeStatement();

        $output->info("Table $tableName: $organisationUpdated records updated with organisation, $ownerUpdated with owner");
        
        return $organisationUpdated;
    }

    /**
     * Get organisation UUID by ID
     *
     * @param int $organisationId Organisation ID
     *
     * @return string Organisation UUID
     */
    private function getOrganisationUuid(int $organisationId): string
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('uuid')
           ->from('openregister_organisations')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($organisationId, \PDO::PARAM_INT)));

        $result = $qb->executeQuery();
        $uuid = $result->fetchOne();
        $result->closeCursor();

        return (string) $uuid;
    }

    /**
     * Make organisation and owner fields mandatory
     *
     * @param IOutput                   $output        Migration output
     * @param Closure(): ISchemaWrapper $schemaClosure Schema closure
     *
     * @return void
     */
    private function makeFieldsMandatory(IOutput $output, Closure $schemaClosure): void
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $tables = ['openregister_registers', 'openregister_schemas', 'openregister_objects'];

        foreach ($tables as $tableName) {
            if ($schema->hasTable($tableName)) {
                $table = $schema->getTable($tableName);

                // Make organisation field mandatory (change from nullable to not null)
                if ($table->hasColumn('organisation')) {
                    $organisationColumn = $table->getColumn('organisation');
                    $organisationColumn->setNotnull(true);
                    $output->info("Made organisation field mandatory in $tableName");
                }

                // Make owner field mandatory (change from nullable to not null)  
                if ($table->hasColumn('owner')) {
                    $ownerColumn = $table->getColumn('owner');
                    $ownerColumn->setNotnull(true);
                    $output->info("Made owner field mandatory in $tableName");
                }
            }
        }

        // Also ensure Organisation table owner is not null
        if ($schema->hasTable('openregister_organisations')) {
            $table = $schema->getTable('openregister_organisations');
            if ($table->hasColumn('owner')) {
                $ownerColumn = $table->getColumn('owner');
                $ownerColumn->setNotnull(true);
                $output->info("Made owner field mandatory in organisations table");
            }
        }
    }
} 