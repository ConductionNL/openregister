<?php
/**
 * OpenRegister Multi-Tenancy Migration
 *
 * This migration completes the multi-tenancy implementation by:
 * 1. Adding users and owner fields to Organisation table
 * 2. Setting all existing registers, schemas, and objects to have an organisation and owner
 * 3. Making organisation and owner fields mandatory (non-nullable)
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

            // Add owner field (user ID who owns the organisation)
            if (!$table->hasColumn('owner')) {
                $table->addColumn('owner', Types::STRING, [
                    'notnull' => false,
                    'length' => 255
                ]);
                $output->info('Added owner column to organisations table');
            }

            // Add slug field (URL-friendly identifier)
            if (!$table->hasColumn('slug')) {
                $table->addColumn('slug', Types::STRING, [
                    'notnull' => false,
                    'length' => 255
                ]);
                $output->info('Added slug column to organisations table');
            }

            // Add unique constraints for uuid and slug
            if ($table->hasColumn('uuid') && !$table->hasIndex('organisations_uuid_unique')) {
                $table->addUniqueIndex(['uuid'], 'organisations_uuid_unique');
                $output->info('Added unique constraint on uuid column');
            }

            if ($table->hasColumn('slug') && !$table->hasIndex('organisations_slug_unique')) {
                $table->addUniqueIndex(['slug'], 'organisations_slug_unique');
                $output->info('Added unique constraint on slug column');
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
        // Step 1: Ensure at least one organisation exists
        $defaultOrgId = $this->ensureOrganisationExists($output);

        // Step 2: Update existing records to have organisation and owner
        $this->updateExistingRecords($output, $defaultOrgId);

        // Step 3: Generate slugs for existing organisations
        $this->generateOrganisationSlugs($output);

        // Step 4: Make organisation and owner fields mandatory
        $this->makeFieldsMandatory($output, $schemaClosure);

        $output->info('Multi-tenancy migration completed successfully!');
    }

    /**
     * Ensure at least one organisation exists
     *
     * @param IOutput $output Migration output
     *
     * @return int The ID of the organisation
     */
    private function ensureOrganisationExists(IOutput $output): int
    {
        // Check if any organisation exists
        $qb = $this->connection->getQueryBuilder();
        $qb->select('id')
           ->from('openregister_organisations')
           ->setMaxResults(1);

        $result = $qb->executeQuery();
        $orgId = $result->fetchOne();
        $result->closeCursor();

        if ($orgId) {
            $output->info('Organisation already exists with ID: ' . $orgId);
            return (int) $orgId;
        }

        // Create a default organisation
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
               'slug' => $qb->createNamedParameter('default-organisation'),
               'name' => $qb->createNamedParameter('Default Organisation'),
               'description' => $qb->createNamedParameter('Default organisation for users without specific organisation membership'),
               'users' => $qb->createNamedParameter('[]'),
               'owner' => $qb->createNamedParameter('system'),
               'created' => $qb->createNamedParameter($now, Types::DATETIME),
               'updated' => $qb->createNamedParameter($now, Types::DATETIME)
           ]);

        $qb->executeStatement();
        $orgId = $this->connection->lastInsertId('openregister_organisations');

        $output->info('Created organisation with ID: ' . $orgId);
        return (int) $orgId;
    }

    /**
     * Update existing records to have organisation and owner
     *
     * @param IOutput $output        Migration output
     * @param int     $orgId         ID of the organisation
     *
     * @return void
     */
    private function updateExistingRecords(IOutput $output, int $orgId): void
    {
        $orgUuid = $this->getOrganisationUuid($orgId);

        // Update registers without organisation
        $updated = $this->updateTable('openregister_registers', $orgUuid, $output);
        $output->info("Updated $updated registers with organisation");

        // Update schemas without organisation
        $updated = $this->updateTable('openregister_schemas', $orgUuid, $output);
        $output->info("Updated $updated schemas with organisation");

        // Update objects without organisation
        $updated = $this->updateTable('openregister_objects', $orgUuid, $output);
        $output->info("Updated $updated objects with organisation");
    }

    /**
     * Update a specific table with organisation
     *
     * @param string  $tableName      Table to update
     * @param string  $orgUuid        UUID of organisation
     * @param IOutput $output         Migration output
     *
     * @return int Number of updated records
     */
    private function updateTable(string $tableName, string $orgUuid, IOutput $output): int
    {
        // Set organisation for records without one
        $qb = $this->connection->getQueryBuilder();
        $qb->update($tableName)
           ->set('organisation', $qb->createNamedParameter($orgUuid))
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
     * Generate slugs for existing organisations that don't have one
     *
     * @param IOutput $output Migration output
     *
     * @return void
     */
    private function generateOrganisationSlugs(IOutput $output): void
    {
        // Get all organisations without slugs
        $qb = $this->connection->getQueryBuilder();
        $qb->select('id', 'name')
           ->from('openregister_organisations')
           ->where($qb->expr()->orX(
               $qb->expr()->isNull('slug'),
               $qb->expr()->eq('slug', $qb->createNamedParameter(''))
           ));

        $result = $qb->executeQuery();
        $organisations = $result->fetchAll();
        $result->closeCursor();

        $updated = 0;
        foreach ($organisations as $org) {
            $slug = $this->generateSlug($org['name']);
            
            // Ensure slug is unique
            $counter = 1;
            $originalSlug = $slug;
            while ($this->slugExists($slug, $org['id'])) {
                $slug = $originalSlug . '-' . $counter;
                $counter++;
            }

            // Update the organisation with the generated slug
            $updateQb = $this->connection->getQueryBuilder();
            $updateQb->update('openregister_organisations')
                     ->set('slug', $updateQb->createNamedParameter($slug))
                     ->where($updateQb->expr()->eq('id', $updateQb->createNamedParameter($org['id'], \PDO::PARAM_INT)));

            $updateQb->executeStatement();
            $updated++;
        }

        $output->info("Generated slugs for $updated organisations");
    }

    /**
     * Generate a URL-friendly slug from a string
     *
     * @param string $string The string to convert to a slug
     *
     * @return string The generated slug
     */
    private function generateSlug(string $string): string
    {
        // Convert to lowercase
        $slug = strtolower($string);
        
        // Replace spaces and special characters with hyphens
        $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
        $slug = preg_replace('/[\s-]+/', '-', $slug);
        
        // Remove leading and trailing hyphens
        $slug = trim($slug, '-');
        
        // Ensure slug is not empty
        if (empty($slug)) {
            $slug = 'organisation';
        }
        
        return $slug;
    }

    /**
     * Check if a slug already exists (excluding a specific organisation ID)
     *
     * @param string $slug The slug to check
     * @param int    $excludeId Organisation ID to exclude from the check
     *
     * @return bool True if slug exists
     */
    private function slugExists(string $slug, int $excludeId): bool
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('id')
           ->from('openregister_organisations')
           ->where($qb->expr()->andX(
               $qb->expr()->eq('slug', $qb->createNamedParameter($slug)),
               $qb->expr()->neq('id', $qb->createNamedParameter($excludeId, \PDO::PARAM_INT))
           ))
           ->setMaxResults(1);

        $result = $qb->executeQuery();
        $exists = $result->fetchOne() !== false;
        $result->closeCursor();

        return $exists;
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