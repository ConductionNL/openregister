<?php

/**
 * Migration creating the unified `openregister_translations` sidecar.
 *
 * One row per (object_uuid, property, language). Stores the
 * translated `value` denormalised from the JSONB property + the
 * workflow `status` + the `translator` uid. Single table answers
 * Decisions 1 (status), 3 (per-language search), and 4 (completeness)
 * from the register-i18n architecture pass.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use Doctrine\DBAL\Types\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class Version1Date20260430120000 extends SimpleMigrationStep
{

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_translations') === true) {
            return $schema;
        }

        $table = $schema->createTable('openregister_translations');

        $table->addColumn('id', Types::BIGINT, [
            'autoincrement' => true,
            'notnull'       => true,
        ]);
        $table->setPrimaryKey(['id']);

        $table->addColumn('object_uuid', Types::STRING, [
            'notnull' => true,
            'length'  => 64,
        ]);
        $table->addColumn('property', Types::STRING, [
            'notnull' => true,
            'length'  => 128,
        ]);
        $table->addColumn('language', Types::STRING, [
            'notnull' => true,
            'length'  => 16,
        ]);

        // Denormalised translation content from the JSONB property.
        // TEXT (no length cap) so long-form translatable bodies fit.
        $table->addColumn('value', Types::TEXT, [
            'notnull' => false,
        ]);

        // Workflow state. Default 'draft' on first projection;
        // human/automation paths can promote to machine_translated /
        // human_reviewed / approved via TranslationStatusService.
        $table->addColumn('status', Types::STRING, [
            'notnull' => true,
            'length'  => 32,
            'default' => 'draft',
        ]);
        $table->addColumn('translator', Types::STRING, [
            'notnull' => false,
            'length'  => 64,
        ]);
        $table->addColumn('updated', Types::DATETIME_MUTABLE, [
            'notnull' => true,
        ]);

        // One row per (object, property, language) — UPSERT key.
        $table->addUniqueIndex(['object_uuid', 'property', 'language'], 'idx_translations_slot');
        // Per-language full-corpus search.
        $table->addIndex(['language'], 'idx_translations_language');
        // Status filters.
        $table->addIndex(['status'], 'idx_translations_status');
        // Per-object completeness queries.
        $table->addIndex(['object_uuid'], 'idx_translations_object');

        return $schema;
    }//end changeSchema()

}//end class
