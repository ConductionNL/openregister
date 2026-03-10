<?php

/**
 * OpenRegister Migration Version1Date20260306120000
 *
 * Migration to add hooks column to schemas table.
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
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add hooks JSON column to schemas table for schema lifecycle hook configuration.
 *
 * @psalm-suppress UnusedClass
 */
class Version1Date20260306120000 extends SimpleMigrationStep
{
    /**
     * Apply schema changes for hooks column.
     *
     * @param IOutput                   $output        Output interface
     * @param Closure(): ISchemaWrapper $schemaClosure Schema closure
     * @param array<string, mixed>      $options       Migration options
     *
     * @return ISchemaWrapper|null
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        // @var ISchemaWrapper $schema
        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_schemas') === true) {
            $table = $schema->getTable('openregister_schemas');

            if ($table->hasColumn('hooks') === false) {
                $table->addColumn(
                    'hooks',
                    Types::TEXT,
                    [
                        'notnull' => false,
                        'default' => null,
                    ]
                );
                $output->info(message: 'Added hooks column to schemas table');
            }
        }

        return $schema;
    }//end changeSchema()
}//end class
