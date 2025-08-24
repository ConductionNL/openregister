<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\SimpleMigrationStep;
use OCP\Migration\IOutput;

/**
 * Migration to ensure source field has proper defaults
 */
class Version1Date20250626031231 extends SimpleMigrationStep
{


    /**
     * @param  IOutput                   $output
     * @param  Closure(): ISchemaWrapper $schemaClosure
     * @param  array                     $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */
        $schema = $schemaClosure();

        // Ensure the source field in openregister_registers table has proper default
        if ($schema->hasTable('openregister_registers')) {
            $table = $schema->getTable('openregister_registers');
            if ($table->hasColumn('source')) {
                $column = $table->getColumn('source');
                $column->setNotnull(false);
                $column->setDefault('internal');
            }
        }

        // Ensure the source field in openregister_schemas table has proper default
        if ($schema->hasTable('openregister_schemas')) {
            $table = $schema->getTable('openregister_schemas');
            if ($table->hasColumn('source')) {
                $column = $table->getColumn('source');
                $column->setNotnull(false);
                $column->setDefault('internal');
            }
        }

        return $schema;

    }//end changeSchema()


}//end class
