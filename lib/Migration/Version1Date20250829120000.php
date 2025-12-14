<?php
/**
 * OpenRegister Combined Schema and Object Enhancements Migration
 *
 * This migration combines multiple database enhancements:
 * 1. Adds image column to openregister_objects table for object representation
 * 2. Adds unique constraints on (organisation, slug) combinations for registers and schemas
 * 3. Cleans up existing duplicate slugs by updating them with suffixes
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
 * Combined migration for schema and object enhancements
 */
class Version1Date20250829120000 extends SimpleMigrationStep
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

    }//end __construct()


    /**
     * Pre-schema change operations to clean up duplicates
     *
     * @param IOutput                   $output        Output interface for logging
     * @param Closure(): ISchemaWrapper $schemaClosure Schema closure
     * @param array                     $options       Migration options
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @return void
     */
    public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        $this->cleanupDuplicateSlugs($output);

    }//end preSchemaChange()


    /**
     * Clean up duplicate (organisation, slug) combinations by updating slugs
     *
     * @param IOutput $output Output interface for logging
     *
     * @return void
     */
    private function cleanupDuplicateSlugs(IOutput $output): void
    {
        // Clean up duplicates in registers table.
        $this->cleanupTableDuplicates(tableName: 'openregister_registers', entityType: 'registers', output: $output);

        // Clean up duplicates in schemas table.
        $this->cleanupTableDuplicates(tableName: 'openregister_schemas', entityType: 'schemas', output: $output);

    }//end cleanupDuplicateSlugs()


    /**
     * Clean up duplicates in a specific table
     *
     * @param string  $tableName  The table name
     * @param string  $entityType The entity type for logging (registers/schemas)
     * @param IOutput $output     Output interface for logging
     *
     * @return void
     */
    private function cleanupTableDuplicates(string $tableName, string $entityType, IOutput $output): void
    {
        // Find duplicates: groups that have more than one record with same (organisation, slug).
        $qb = $this->connection->getQueryBuilder();
        $qb->select('organisation', 'slug')
            ->selectAlias($qb->func()->count('id'), 'duplicate_count')
            ->from($tableName)
            ->where($qb->expr()->isNotNull('organisation'))
            ->andWhere($qb->expr()->isNotNull('slug'))
            ->groupBy('organisation', 'slug')
            ->having($qb->expr()->gt('duplicate_count', $qb->createNamedParameter(1)));

        $duplicateGroups = $qb->executeQuery()->fetchAll();

        foreach ($duplicateGroups as $group) {
            $organisation = $group['organisation'];
            $originalSlug = $group['slug'];

            $output->info("Found {$group['duplicate_count']} duplicate {$entityType} with organisation '{$organisation}' and slug '{$originalSlug}'");

            // Get all records in this duplicate group, ordered by ID (keep first, update others).
            $qb2 = $this->connection->getQueryBuilder();
            $qb2->select('id', 'title')
                ->from($tableName)
                ->where($qb2->expr()->eq('organisation', $qb2->createNamedParameter($organisation)))
                ->andWhere($qb2->expr()->eq('slug', $qb2->createNamedParameter($originalSlug)))
                ->orderBy('id', 'ASC');

            $duplicates = $qb2->executeQuery()->fetchAll();

            // Skip the first record (keep original), update the rest.
            foreach (array_slice($duplicates, 1) as $index => $duplicate) {
                $newSlug = $this->generateUniqueSlug(
                    tableName: $tableName,
                    organisation: $organisation,
                    baseSlug: $originalSlug,
                    startNumber: ((int) $index + 2)
                );

                // Update the slug.
                $updateQb = $this->connection->getQueryBuilder();
                $updateQb->update($tableName)
                    ->set('slug', $updateQb->createNamedParameter($newSlug))
                    ->where($updateQb->expr()->eq('id', $updateQb->createNamedParameter($duplicate['id'])));

                $updateQb->executeStatement();

                $output->info("Updated {$entityType} '{$duplicate['title']}' (ID: {$duplicate['id']}) from slug '{$originalSlug}' to '{$newSlug}'");
            }
        }//end foreach

    }//end cleanupTableDuplicates()


    /**
     * Generate a unique slug for the given table and organisation
     *
     * @param string  $tableName    The table name
     * @param string  $organisation The organisation ID
     * @param string  $baseSlug     The base slug to make unique
     * @param integer $startNumber  The number to start appending (default: 2)
     *
     * @return string The unique slug
     */
    private function generateUniqueSlug(string $tableName, string $organisation, string $baseSlug, int $startNumber=2): string
    {
        $counter = $startNumber;
        $newSlug = $baseSlug.'-'.$counter;

        // Keep incrementing until we find a unique slug.
        while ($this->slugExists(tableName: $tableName, organisation: $organisation, slug: $newSlug) === true) {
            $counter++;
            $newSlug = $baseSlug.'-'.$counter;
        }

        return $newSlug;

    }//end generateUniqueSlug()


    /**
     * Check if a slug exists for the given organisation in the table
     *
     * @param string $tableName    The table name
     * @param string $organisation The organisation ID
     * @param string $slug         The slug to check
     *
     * @return boolean True if slug exists, false otherwise
     */
    private function slugExists(string $tableName, string $organisation, string $slug): bool
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select($qb->func()->count('id'))
            ->from($tableName)
            ->where($qb->expr()->eq('organisation', $qb->createNamedParameter($organisation)))
            ->andWhere($qb->expr()->eq('slug', $qb->createNamedParameter($slug)));

        $count = $qb->executeQuery()->fetchOne();
        return ((int) $count > 0);

    }//end slugExists()


    /**
     * Apply schema changes for both image column and unique constraints
     *
     * @param IOutput                   $output        Output interface for logging
     * @param Closure(): ISchemaWrapper $schemaClosure Schema closure
     * @param array                     $options       Migration options
     *
     * @return ISchemaWrapper
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        // 1. Add image column to openregister_objects table.
        if ($schema->hasTable('openregister_objects') === true) {
            $table = $schema->getTable('openregister_objects');

            if ($table->hasColumn('image') === false) {
                $table->addColumn(
                        'image',
                        Types::TEXT,
                        [
                            'notnull' => false,
                            'comment' => 'Image data or reference representing the object (e.g. logo)',
                        ]
                        );
                $output->info(message: 'Added image column to openregister_objects table');
            }
        }

        // 2. Add unique constraint for (organisation, slug) on registers table.
        if ($schema->hasTable('openregister_registers') === true) {
            $table = $schema->getTable('openregister_registers');

            // Check if both columns exist before adding constraint.
            if ($table->hasColumn('organisation') === true && $table->hasColumn('slug') === true) {
                $indexName = 'registers_organisation_slug_unique';
                if ($table->hasIndex($indexName) === false) {
                    $table->addUniqueIndex(['organisation', 'slug'], $indexName);
                    $output->info(message: 'Added unique constraint on (organisation, slug) for registers table');
                }
            } else {
                $output->warning('Cannot add unique constraint: organisation or slug column missing in registers table');
            }
        }

        // 3. Add unique constraint for (organisation, slug) on schemas table.
        if ($schema->hasTable('openregister_schemas') === true) {
            $table = $schema->getTable('openregister_schemas');

            // Check if both columns exist before adding constraint.
            if ($table->hasColumn('organisation') === true && $table->hasColumn('slug') === true) {
                $indexName = 'schemas_organisation_slug_unique';
                if ($table->hasIndex($indexName) === false) {
                    $table->addUniqueIndex(['organisation', 'slug'], $indexName);
                    $output->info(message: 'Added unique constraint on (organisation, slug) for schemas table');
                }
            } else {
                $output->warning('Cannot add unique constraint: organisation or slug column missing in schemas table');
            }
        }

        return $schema;

    }//end changeSchema()


}//end class
