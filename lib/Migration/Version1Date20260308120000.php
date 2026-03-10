<?php

/**
 * Database migration to add mapping column to webhooks table.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author  Conduction Development Team <dev@conductio.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
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

/**
 * Adds the mapping column to openregister_webhooks table.
 *
 * Allows Webhook entities to reference a Mapping entity for
 * payload transformation before delivery.
 *
 * @package OCA\OpenRegister\Migration
 */
class Version1Date20260308120000 extends SimpleMigrationStep
{
    /**
     * Change the database schema.
     *
     * @param IOutput $output        Migration output
     * @param Closure $schemaClosure Schema closure
     * @param array   $options       Migration options
     *
     * @return ISchemaWrapper|null The updated schema
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        // @var ISchemaWrapper $schema
        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_webhooks') === false) {
            return null;
        }

        $table = $schema->getTable('openregister_webhooks');

        if ($table->hasColumn('mapping') === true) {
            return null;
        }

        $table->addColumn(
            'mapping',
            Types::INTEGER,
            [
                'notnull' => false,
                'default' => null,
            ]
        );

        return $schema;

    }//end changeSchema()
}//end class
