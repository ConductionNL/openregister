<?php

/**
 * Migration adding `context` JSON column to `oc_openregister_messages`.
 *
 * Records the CnAiContext snapshot active at the moment a user message
 * was sent. The column defaults to '{}' (empty JSON object) so existing
 * rows are valid without a data migration.
 *
 * Rollback: run `occ migrations:execute openregister Version1Date20260511100000 --down`
 * to drop the column. Safe — no other code depended on it before this change.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction BV
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/ai-chat-companion-orchestrator/specs/chat-ai/spec.md#messagecontext-json-column
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Version1Date20260511100000
 *
 * Adds a `context` TEXT column (default '{}') to `oc_openregister_messages`.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 */
class Version1Date20260511100000 extends SimpleMigrationStep
{

    /**
     * Change the database schema — add the context column.
     *
     * @param IOutput                 $output        Output for the migration process
     * @param Closure                 $schemaClosure The schema closure
     * @param array<array-key, mixed> $options       Migration options
     *
     * @return null|ISchemaWrapper Updated schema or null if no changes
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */
        $schema = $schemaClosure();

        if ($schema->hasTable(tableName: 'openregister_messages') === false) {
            return null;
        }

        $table = $schema->getTable(tableName: 'openregister_messages');

        if ($table->hasColumn(name: 'context') === true) {
            // Column already exists — idempotent.
            return null;
        }

        $table->addColumn(
            name: 'context',
            typeName: Types::TEXT,
            options: [
                'notnull' => false,
                'default' => '{}',
                'comment' => 'CnAiContext JSON snapshot at the time the user message was sent',
            ]
        );

        return $schema;
    }//end changeSchema()

    /**
     * Rollback: remove the context column.
     *
     * @param IOutput                 $output        Output for the migration process
     * @param Closure                 $schemaClosure The schema closure
     * @param array<array-key, mixed> $options       Migration options
     *
     * @return null|ISchemaWrapper Updated schema or null if no changes
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function down(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */
        $schema = $schemaClosure();

        if ($schema->hasTable(tableName: 'openregister_messages') === false) {
            return null;
        }

        $table = $schema->getTable(tableName: 'openregister_messages');

        if ($table->hasColumn(name: 'context') === false) {
            return null;
        }

        $table->dropColumn(name: 'context');

        return $schema;
    }//end down()
}//end class
