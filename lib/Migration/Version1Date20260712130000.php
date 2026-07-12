<?php

/**
 * Migration adding MCP tool-invocation audit fields to `openregister_audit_trails`.
 *
 * Adds `tool_id`, `params_digest`, and `result_summary` — three nullable
 * columns that let a single `AuditTrail` row also serve as the immutable,
 * hash-chained record of an MCP tool invocation (EU AI Act art.12/14),
 * reusing the existing `AuditHashService` hash chain unchanged. Ordinary
 * object-CRUD rows leave all three columns null.
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
 * @spec openspec/changes/or-mcp-derived-tool-provider/specs/ai-mcp/spec.md
 *   (Requirement: REQ-DERIVED-006 — Every invocation is audited)
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds nullable `tool_id`, `params_digest`, `result_summary` columns to the
 * `openregister_audit_trails` table.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class Version1Date20260712130000 extends SimpleMigrationStep
{
    /**
     * Change the database schema.
     *
     * @param IOutput                 $output        Output for the migration process
     * @param Closure                 $schemaClosure The schema closure
     * @param array<array-key, mixed> $options       Migration options
     *
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        if ($schema->hasTable(tableName: 'openregister_audit_trails') === false) {
            return null;
        }

        $table   = $schema->getTable(tableName: 'openregister_audit_trails');
        $changed = false;

        if ($table->hasColumn(name: 'tool_id') === false) {
            $table->addColumn(
                name: 'tool_id',
                typeName: Types::STRING,
                options: [
                    'notnull' => false,
                    'length'  => 190,
                ]
            );
            $changed = true;
        }

        if ($table->hasColumn(name: 'params_digest') === false) {
            $table->addColumn(
                name: 'params_digest',
                typeName: Types::STRING,
                options: [
                    'notnull' => false,
                    'length'  => 64,
                ]
            );
            $changed = true;
        }

        if ($table->hasColumn(name: 'result_summary') === false) {
            $table->addColumn(
                name: 'result_summary',
                typeName: Types::JSON,
                options: ['notnull' => false]
            );
            $changed = true;
        }

        if ($table->hasIndex(name: 'idx_audit_trails_tool_id') === false) {
            $table->addIndex(
                columnNames: ['tool_id'],
                indexName: 'idx_audit_trails_tool_id'
            );
            $changed = true;
        }

        if ($changed === false) {
            return null;
        }

        $output->info('Added tool_id/params_digest/result_summary to openregister_audit_trails');

        return $schema;
    }//end changeSchema()
}//end class
