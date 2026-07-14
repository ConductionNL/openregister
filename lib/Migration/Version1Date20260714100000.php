<?php

/**
 * Migration mapping pack store migration.
 *
 * Creates `openregister_migration_packs` — infrastructure DB state for the
 * migration-mapping-packs feature. Each row is a reusable, declarative
 * source-format-to-schema import mapping definition:
 *
 *  - id (bigint, PK, autoincrement)
 *  - pack_slug (string 128, not null, unique) — the pack document's own `id` field
 *  - name (string 255, not null)
 *  - source_format (string 16, not null) — csv|json|excel
 *  - version (string 32, not null) — semver
 *  - definition (text/clob, not null) — full pack document as JSON
 *  - builtin (boolean, not null) — default false; true for seeded reference packs
 *  - owner (string 64, nullable) — creating admin uid, null for built-in packs
 *  - created_at (datetime, not null)
 *  - updated_at (datetime, not null)
 *
 * Indexes:
 *  - (pack_slug) unique — lookup key used by the import endpoint's `packId` param
 *
 * This is explicitly NOT an OpenRegister object/register (ADR-001): the rows
 * are mapping config with no business meaning. Idempotent: creates the table
 * only when absent.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/migration-mapping-packs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create the openregister_migration_packs table.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 *
 * @spec openspec/specs/migration-mapping-packs/spec.md
 */
class Version1Date20260714100000 extends SimpleMigrationStep
{
    /**
     * Change the database schema.
     *
     * @param IOutput                 $output        Output for the migration process.
     * @param Closure                 $schemaClosure The schema closure.
     * @param array<array-key, mixed> $options       Migration options.
     *
     * @return ISchemaWrapper|null
     *
     * @spec openspec/specs/migration-mapping-packs/spec.md
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_migration_packs') === true) {
            return null;
        }

        $table = $schema->createTable('openregister_migration_packs');

        $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
        $table->addColumn('pack_slug', Types::STRING, ['notnull' => true, 'length' => 128]);
        $table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
        $table->addColumn('source_format', Types::STRING, ['notnull' => true, 'length' => 16]);
        $table->addColumn('version', Types::STRING, ['notnull' => true, 'length' => 32]);
        $table->addColumn('definition', Types::TEXT, ['notnull' => true]);
        $table->addColumn('builtin', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
        $table->addColumn('owner', Types::STRING, ['notnull' => false, 'length' => 64]);
        $table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
        $table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['pack_slug'], 'idx_or_migration_pack_slug');

        $output->info('Created openregister_migration_packs table');

        return $schema;
    }//end changeSchema()
}//end class
