<?php

/**
 * Migration to add checksum column to chunks table.
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
 * Adds checksum column to chunks table for change detection.
 */
class Version1Date20251117000000 extends SimpleMigrationStep
{
    /**
     * Apply schema changes.
     *
     * @param IOutput $output        Output helper.
     * @param Closure $schemaClosure Schema factory.
     * @param array   $options       Migration options.
     *
     * @return ISchemaWrapper Updated schema.
     *
     * @SuppressWarnings (PHPMD.UnusedFormalParameter)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $schema = $schemaClosure();

        $this->addChecksumToChunks(output: $output, schema: $schema);

        return $schema;
    }//end changeSchema()

    /**
     * Add checksum column to chunks table.
     *
     * @param IOutput        $output Output helper.
     * @param ISchemaWrapper $schema Database schema.
     *
     * @return void
     */
    private function addChecksumToChunks(IOutput $output, ISchemaWrapper $schema): void
    {
        if ($schema->hasTable('openregister_chunks') === false) {
            $output->info(message: 'ℹ️  Table openregister_chunks does not exist, skipping.');
            return;
        }

        $table = $schema->getTable('openregister_chunks');

        if ($table->hasColumn('checksum') === true) {
            $output->info(message: 'ℹ️  Column checksum already exists in openregister_chunks, skipping.');
            return;
        }

        $table->addColumn(
            'checksum',
            Types::STRING,
            [
                'length'  => 64,
                'notnull' => false,
                'comment' => 'SHA256 checksum of the source text for change detection',
            ]
        );

        $table->addIndex(['checksum'], 'chunks_checksum_idx');

        $output->info(message: '✅ Added checksum column to openregister_chunks table.');
    }//end addChecksumToChunks()
}//end class
