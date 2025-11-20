<?php

declare(strict_types=1);

/*
 * OpenRegister Schema Extension Migration
 *
 * This migration adds the 'extend' column to the schemas table to support
 * schema inheritance/extension functionality.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to add extend column to schemas table
 *
 * Adds support for schema inheritance by allowing schemas to extend other schemas.
 * The extend column stores the ID, UUID, or slug of the parent schema.
 */
class Version1Date20251102170000 extends SimpleMigrationStep
{


    /**
     * Add extend column to schemas table
     *
     * @param IOutput $output        Migration output interface
     * @param Closure $schemaClosure Schema closure
     * @param array   $options       Migration options
     *
     * @return ISchemaWrapper|null Updated schema
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */
        $schema = $schemaClosure();

        $output->info('⚠️  Schema extension (extend column) is deprecated - skipping migration');
        $output->info('   Schema inheritance now uses allOf, oneOf, and anyOf fields instead');

        // DEPRECATED: The extend column functionality has been replaced by JSON Schema
        // composition using allOf, oneOf, and anyOf fields. This migration is kept
        // for backwards compatibility but no longer adds the extend column.
        //
        // If the extend column exists from a previous installation, it will remain
        // but is no longer used by the application.
        return null;

    }//end changeSchema()


}//end class
