<?php

/**
 * Migration creating `openregister_tenant_keys`.
 *
 * Stores per-tenant HMAC signing keys for the audit-trail hash-chain
 * (ADR-022 "audit-hash-chain" row).  Each row holds:
 *
 *  - tenant_id     — opaque tenant identifier (e.g. organisation UUID)
 *  - encrypted_key — AES-256-GCM ciphertext produced by OCP\Security\ICrypto
 *  - status        — 'active' | 'retired'  (one active row per tenant)
 *  - created_at    — ISO-8601 issuance / rotation timestamp (VARCHAR to
 *                    avoid timezone drift across DB engines)
 *
 * Retired rows are kept so verifiers can re-check older evidence records
 * that were signed under the previous key.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/scholiq-deps/tenant-key-api/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates the openregister_tenant_keys table.
 */
class Version1Date20260511100000 extends SimpleMigrationStep
{
    /**
     * Change the database schema.
     *
     * @param IOutput                 $output        Output for the migration process
     * @param Closure                 $schemaClosure The schema closure
     * @param array<array-key, mixed> $options       Migration options
     *
     * @return null|ISchemaWrapper
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        // @var ISchemaWrapper $schema
        $schema = $schemaClosure();

        if ($schema->hasTable(tableName: 'openregister_tenant_keys') === true) {
            return null;
        }

        $table = $schema->createTable(tableName: 'openregister_tenant_keys');

        $table->addColumn(
            name: 'id',
            typeName: Types::BIGINT,
            options: [
                'autoincrement' => true,
                'notnull'       => true,
            ]
        );

        $table->addColumn(
            name: 'tenant_id',
            typeName: Types::STRING,
            options: [
                'notnull' => true,
                'length'  => 255,
                'comment' => 'Opaque tenant identifier (organisation UUID or similar)',
            ]
        );

        $table->addColumn(
            name: 'encrypted_key',
            typeName: Types::TEXT,
            options: [
                'notnull' => true,
                'comment' => 'ICrypto-encrypted 256-bit HMAC key (AES-256-GCM ciphertext)',
            ]
        );

        $table->addColumn(
            name: 'status',
            typeName: Types::STRING,
            options: [
                'notnull' => true,
                'length'  => 16,
                'default' => 'active',
                'comment' => '"active" = current key; "retired" = superseded by rotation',
            ]
        );

        $table->addColumn(
            name: 'created_at',
            typeName: Types::STRING,
            options: [
                'notnull' => true,
                'length'  => 32,
                'comment' => 'ISO-8601 key issuance / rotation timestamp',
            ]
        );

        $table->setPrimaryKey(columnNames: ['id']);
        $table->addIndex(
            columnNames: ['tenant_id', 'status'],
            indexName: 'idx_or_tkeys_tenant_status'
        );
        $table->addIndex(
            columnNames: ['tenant_id'],
            indexName: 'idx_or_tkeys_tenant_id'
        );

        return $schema;

    }//end changeSchema()
}//end class
