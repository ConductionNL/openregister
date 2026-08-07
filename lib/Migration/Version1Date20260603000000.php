<?php

/**
 * Migration to add bases column to entity_relations table.
 *
 * Longer description.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/entity-relation-grondslagen/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds a nullable JSON `bases` column to openregister_entity_relations.
 *
 * The column stores an array of UUID-shaped strings referencing legal-basis
 * objects in the consuming app's register (e.g. DocuDesk's `base` schema).
 * OpenRegister stores the array verbatim; no UUID validation is performed.
 */
class Version1Date20260603000000 extends SimpleMigrationStep
{
    /**
     * Change the database schema.
     *
     * @param IOutput                 $output        Output for the migration process.
     * @param Closure                 $schemaClosure The schema closure.
     * @param array<array-key, mixed> $options       Migration options.
     *
     * @return ISchemaWrapper|null
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        // @var ISchemaWrapper $schema
        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_entity_relations') === false) {
            return null;
        }

        $table = $schema->getTable('openregister_entity_relations');

        if ($table->hasColumn('bases') === true) {
            return null;
        }

        $table->addColumn(
            name: 'bases',
            typeName: Types::JSON,
            options: [
                'notnull' => false,
                'default' => null,
            ]
        );

        $output->info('Added bases column to openregister_entity_relations');

        return $schema;
    }//end changeSchema()
}//end class
