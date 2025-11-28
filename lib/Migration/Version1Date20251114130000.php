<?php

/**
 * OpenRegister Schema Extend Column Removal Migration
 *
 * This migration removes the deprecated 'extend' column from the schemas table.
 * The 'extend' pattern has been replaced with the standards-compliant JSON Schema
 * composition patterns (allOf, oneOf, anyOf).
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to remove deprecated extend column from schemas table
 *
 * Removes the legacy 'extend' column which has been replaced with the
 * standards-compliant JSON Schema composition patterns (allOf, oneOf, anyOf).
 *
 * This migration should run after Version1Date20251114120000 which adds the
 * new composition columns. It will migrate any existing 'extend' values to 'allOf'
 * before removing the column.
 */
class Version1Date20251114130000 extends SimpleMigrationStep
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
     * Migrate extend values to allOf before schema change
     *
     * @param IOutput $output        Migration output interface
     * @param Closure $schemaClosure Schema closure
     * @param array   $options       Migration options
     *
     * @return void
     */
    public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_schemas') === false || $schema->getTable('openregister_schemas')->hasColumn('extend') === false) {
            return;
        }

        $output->info(message: '🔄 Migrating extend values to allOf...');

        // Find all schemas with extend field set.
        $qb = $this->connection->getQueryBuilder();
        $qb->select('id', 'extend')
            ->from('openregister_schemas')
            ->where($qb->expr()->isNotNull('extend'))
            ->andWhere($qb->expr()->neq('extend', $qb->createNamedParameter('')));

        $result        = $qb->executeQuery();
        $migratedCount = 0;

        while (($row = $result->fetch()) !== false) {
            $id     = $row['id'];
            $extend = $row['extend'];

            // Convert extend to allOf (single parent becomes array with one element).
            $allOf = json_encode([$extend]);

            // Update the schema.
            $updateQb = $this->connection->getQueryBuilder();
            $updateQb->update('openregister_schemas')
                ->set('all_of', $updateQb->createNamedParameter($allOf))
                ->where($updateQb->expr()->eq('id', $updateQb->createNamedParameter($id)))
                ->executeStatement();

            $migratedCount++;
        }//end while

        $result->closeCursor();

        if ($migratedCount > 0) {
            $output->info(message: "   ✓ Migrated {$migratedCount} schema(s) from extend to allOf");
        } else {
            $output->info(message: '   ℹ️  No schemas with extend field found');
        }

    }//end preSchemaChange()


    /**
     * Remove extend column from schemas table
     *
     * @param IOutput $output        Migration output interface
     * @param Closure $schemaClosure Schema closure
     * @param array   $options       Migration options
     *
     * @return ISchemaWrapper|null Updated schema
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        $output->info(message: '🔧 Removing deprecated extend column...');

        // Remove extend field from schemas table.
        if ($schema->hasTable('openregister_schemas') === true) {
            $table = $schema->getTable('openregister_schemas');

            // Remove extend column if it exists.
            if ($table->hasColumn('extend') === true) {
                $table->dropColumn('extend');

                $output->info(message: '   ✓ Removed extend column from schemas table');
                $output->info(message: '✅ Migration completed successfully');
                $output->info(message: '📚 Use allOf, oneOf, or anyOf for schema composition');
                $output->info('   See: https://json-schema.org/understanding-json-schema/reference/combining');
            } else {
                $output->info(message: '   ⚠️  extend column does not exist (already removed)');
            }
        } else {
            $output->info(message: '⚠️  Schemas table does not exist!');
        }

        return $schema;

    }//end changeSchema()


}//end class
