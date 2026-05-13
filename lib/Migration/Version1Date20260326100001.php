<?php

/**
 * Migration to drop entity-specific link tables replaced by generic linked entity columns.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Drops openregister_email_links, openregister_contact_links, and openregister_deck_links tables.
 *
 * These entity-specific link tables are replaced by the generic linked entity metadata columns
 * (_mail, _contacts, _deck) on magic tables and entity tables.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class Version1Date20260326100001 extends SimpleMigrationStep
{
    /**
     * Tables to drop.
     */
    private const TABLES_TO_DROP = [
        'openregister_email_links',
        'openregister_contact_links',
        'openregister_deck_links',
    ];

    /**
     * Change the database schema.
     *
     * @param IOutput                 $output        Output for the migration process
     * @param Closure                 $schemaClosure The schema closure
     * @param array<array-key, mixed> $options       Migration options
     *
     * @return ISchemaWrapper|null
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema  = $schemaClosure();
        $changed = false;

        foreach (self::TABLES_TO_DROP as $tableName) {
            if ($schema->hasTable($tableName) === true) {
                $schema->dropTable($tableName);
                $output->info("Dropped $tableName (replaced by generic linked entity columns)");
                $changed = true;
            }
        }

        if ($changed === false) {
            return null;
        }

        return $schema;
    }//end changeSchema()
}//end class
