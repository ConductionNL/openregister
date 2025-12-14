<?php

/**
 * Add chunks_json column to file_texts table
 *
 * This migration adds a column to store text chunks as JSON for independent
 * text extraction that doesn't depend on SOLR indexing.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add chunks_json column to file_texts table
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 */
class Version1Date20251107170000 extends SimpleMigrationStep
{


    /**
     * Modify the database schema
     *
     * @param IOutput $output        Output handler
     * @param Closure $schemaClosure Schema closure
     * @param array   $options       Options
     *
     * @return ISchemaWrapper|null The modified schema or null
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema  = $schemaClosure();
        $updated = false;

        if ($schema->hasTable('openregister_file_texts') === true) {
            $table = $schema->getTable('openregister_file_texts');

            if ($table->hasColumn('chunks_json') === false) {
                $table->addColumn(
                        'chunks_json',
                        Types::TEXT,
                        [
                            'notnull' => false,
                            'default' => null,
                            'comment' => 'JSON-encoded array of text chunks with metadata',
                        ]
                        );
                $output->info(message: '✅ Added chunks_json column to file_texts table');
                $updated = true;
            } else {
                $output->info(message: 'ℹ️  chunks_json column already exists in file_texts table');
            }
        } else {
            $output->warning(message: '⚠️  openregister_file_texts table does not exist');
        }//end if

        if ($updated === true) {
            return $schema;
        }

        return null;

    }//end changeSchema()


    /**
     * Post-schema change hook
     *
     * @param IOutput $output        Output handler
     * @param Closure $schemaClosure Schema closure
     * @param array   $options       Options
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        $output->info(message: '✅ Migration complete - Text extraction is now independent of SOLR');
        $output->info(message: '   Chunks will be generated during extraction and stored for later use');

    }//end postSchemaChange()


}//end class
