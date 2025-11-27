<?php
/**
 * OpenRegister Migration Version1Date20250125000000
 *
 * Migration to add configuration column to webhooks table.
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
 * Add configuration column to webhooks table
 */
class Version1Date20250125000000 extends SimpleMigrationStep
{


    /**
     * Change schema to add configuration column
     *
     * @param IOutput $output        Migration output
     * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array   $options       Migration options
     *
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        // @var ISchemaWrapper $schema
        $schema = $schemaClosure();

        $table = $schema->getTable('openregister_webhooks');

        if ($table->hasColumn('configuration') === false) {
            $table->addColumn(
                    'configuration',
                    Types::TEXT,
                    [
                        'notnull' => false,
                    ]
                    );

            return $schema;
        }//end if

        return null;

    }//end changeSchema()


}//end class


