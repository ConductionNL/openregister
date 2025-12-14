<?php

declare(strict_types=1);

/*
 * Migration to drop deprecated file_texts and object_texts tables.
 *
 * These tables are no longer needed as we've migrated to the chunks-based
 * architecture. All text extraction now uses the openregister_chunks table.
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

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Drops deprecated file_texts and object_texts tables.
 */
class Version1Date20251118000000 extends SimpleMigrationStep
{


    /**
     * Apply schema changes.
     *
     * @param IOutput $output        Output helper.
     * @param Closure $schemaClosure Schema factory.
     * @param array   $options       Migration options.
     *
     * @return ISchemaWrapper Updated schema.
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $schema = $schemaClosure();

        $this->dropFileTextsTable(output: $output, schema: $schema);
        $this->dropObjectTextsTable(output: $output, schema: $schema);

        return $schema;

    }//end changeSchema()


    /**
     * Drop the deprecated file_texts table.
     *
     * @param IOutput        $output Output helper.
     * @param ISchemaWrapper $schema Database schema.
     *
     * @return void
     */
    private function dropFileTextsTable(IOutput $output, ISchemaWrapper $schema): void
    {
        if ($schema->hasTable('openregister_file_texts') === false) {
            $output->info(message: 'ℹ️  Table openregister_file_texts does not exist, skipping.');
            return;
        }

        $schema->dropTable('openregister_file_texts');
        $output->info(message: '✅ Dropped deprecated openregister_file_texts table.');

    }//end dropFileTextsTable()


    /**
     * Drop the deprecated object_texts table.
     *
     * @param IOutput        $output Output helper.
     * @param ISchemaWrapper $schema Database schema.
     *
     * @return void
     */
    private function dropObjectTextsTable(IOutput $output, ISchemaWrapper $schema): void
    {
        if ($schema->hasTable('openregister_object_texts') === false) {
            $output->info(message: 'ℹ️  Table openregister_object_texts does not exist, skipping.');
            return;
        }

        $schema->dropTable('openregister_object_texts');
        $output->info(message: '✅ Dropped deprecated openregister_object_texts table.');

    }//end dropObjectTextsTable()


}//end class
