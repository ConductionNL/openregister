<?php
/**
 * OpenRegister Migration Version1Date20251127000000
 *
 * Migration to add request_body column to webhook_logs table for storing request payload on failures.
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
 * Add request_body column to webhook_logs table
 *
 * @category  Migration
 * @package   OCA\OpenRegister\Migration
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   1.0.0
 * @link      https://www.OpenRegister.app
 */
class Version1Date20251127000000 extends SimpleMigrationStep
{


    /**
     * Change database schema
     *
     * @param IOutput $output        Output interface
     * @param Closure $schemaClosure Schema closure
     * @param array   $options       Options
     *
     * @return ISchemaWrapper|null
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */
        $schema = $schemaClosure();

        // Add request_body column to webhook_logs table if it exists.
        if ($schema->hasTable('openregister_webhook_logs') === true) {
            $table = $schema->getTable('openregister_webhook_logs');

            // Add request_body column if it doesn't exist.
            if ($table->hasColumn('request_body') === false) {
                $table->addColumn(
                        'request_body',
                        Types::TEXT,
                        [
                            'notnull' => false,
                        ]
                        );
                $output->info('Added request_body column to openregister_webhook_logs table');
            }
        }

        return $schema;

    }//end changeSchema()


}//end class
