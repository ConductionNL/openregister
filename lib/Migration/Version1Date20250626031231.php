<?php
/**
 * OpenRegister Migration Version1Date20250626031231
 *
 * Migration to ensure source field has proper defaults.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

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
     * Change schema to ensure source field has proper defaults
     *
     * @param IOutput                   $output        Migration output
     * @param Closure(): ISchemaWrapper $schemaClosure Schema closure
     * @param array                     $options       Migration options
     *
     * @return ISchemaWrapper
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        // @var ISchemaWrapper $schema
        $schema = $schemaClosure();

        // Ensure the source field in openregister_registers table has proper default.
        if ($schema->hasTable('openregister_registers') === true) {
            $table = $schema->getTable('openregister_registers');
            if ($table->hasColumn('source') === true) {
                $column = $table->getColumn('source');
                $column->setNotnull(false);
                $column->setDefault('internal');
            }
        }

        // Ensure the source field in openregister_schemas table has proper default.
        if ($schema->hasTable('openregister_schemas') === true) {
            $table = $schema->getTable('openregister_schemas');
            if ($table->hasColumn('source') === true) {
                $column = $table->getColumn('source');
                $column->setNotnull(false);
                $column->setDefault('internal');
            }
        }

        return $schema;

    }//end changeSchema()
}//end class
