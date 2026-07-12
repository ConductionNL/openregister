<?php

/**
 * TablesSchemaSyncService — reconciles the auto-seeded `tables` virtual register
 * so that one read-only virtual schema exists per Nextcloud Tables table.
 *
 * Given plain table descriptors (`{id, title, columns}`) — produced by
 * {@see TablesTableReader}, never by touching Tables types here — this service
 * seeds/updates one schema per table under the `tables` register (deterministic
 * idempotent slug `nc-<slug(title)>-t<tableId>`, carrying the
 * `x-openregister-object-source` binding and a `managed` marker) and retires the
 * managed schemas of tables that are gone. It NEVER overwrites a schema it did
 * not create (a hand-authored `config.tableId` binding is left untouched), per
 * design D7. The same reconcile runs from the Repair step, the
 * `occ openregister:tables:sync` command, and — for single-table retirement —
 * the `TableDeletedEvent` listener.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tables-object-source-provider/specs/tables-virtual-register/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reconciler for the auto-seeded `tables` virtual register and its schemas.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class TablesSchemaSyncService
{

    /**
     * The virtual register slug every Tables schema is seeded under.
     *
     * @var string
     */
    public const REGISTER_SLUG = 'tables';

    /**
     * The provider id serving these schemas' objects.
     *
     * @var string
     */
    public const PROVIDER_ID = 'tables';

    /**
     * Constructor.
     *
     * @param RegisterMapper     $registerMapper Register data mapper.
     * @param SchemaMapper       $schemaMapper   Schema data mapper.
     * @param TablesColumnMapper $columnMapper   Column → schema-property projection.
     * @param LoggerInterface    $logger         Logger for reconcile diagnostics.
     *
     * @return void
     */
    public function __construct(
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly TablesColumnMapper $columnMapper,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Reconcile the `tables` register against the current set of tables.
     *
     * Idempotent: re-running with an unchanged table set makes no changes. Seeds
     * or refreshes one managed schema per descriptor and retires managed schemas
     * whose table is no longer present.
     *
     * @param array<int, array<string, mixed>> $tables The table descriptors ({id, title, columns}).
     *
     * @return array{seeded: int, retired: int, skipped: int} Reconcile statistics.
     *
     * @spec openspec/changes/tables-object-source-provider/specs/tables-virtual-register/spec.md
     */
    public function reconcile(array $tables): array
    {
        $register = $this->ensureRegister();
        $stats    = ['seeded' => 0, 'retired' => 0, 'skipped' => 0];

        $seenTableIds = [];
        foreach ($tables as $table) {
            $tableId        = (int) ($table['id'] ?? 0);
            $seenTableIds[] = $tableId;
            if ($this->seedTable(register: $register, table: $table) === true) {
                $stats['seeded']++;
                continue;
            }

            $stats['skipped']++;
        }

        $stats['retired'] = $this->retireMissing(register: $register, keepTableIds: $seenTableIds);

        return $stats;
    }//end reconcile()

    /**
     * Retire the managed schema bound to a single deleted table.
     *
     * @param int $tableId The id of the deleted table.
     *
     * @return bool True when a schema was retired.
     *
     * @spec openspec/changes/tables-object-source-provider/specs/tables-virtual-register/spec.md
     */
    public function retireByTableId(int $tableId): bool
    {
        try {
            $register = $this->registerMapper->find(self::REGISTER_SLUG, _rbac: false, _multitenancy: false);
        } catch (Throwable $e) {
            return false;
        }

        $retired = $this->retireMissing(register: $register, keepTableIds: [], onlyTableId: $tableId);

        return $retired > 0;
    }//end retireByTableId()

    /**
     * Whether a managed schema exists for the given table id (relation target).
     *
     * @param int $tableId The candidate target table id.
     *
     * @return bool True when a managed schema is bound to that table.
     *
     * @spec openspec/changes/tables-object-source-provider/specs/tables-virtual-register/spec.md
     */
    public function hasManagedSchemaForTableId(int $tableId): bool
    {
        try {
            $register = $this->registerMapper->find(self::REGISTER_SLUG, _rbac: false, _multitenancy: false);
        } catch (Throwable $e) {
            return false;
        }

        return isset($this->managedSchemasByTableId(register: $register)[$tableId]);
    }//end hasManagedSchemaForTableId()

    /**
     * Deterministic idempotent slug for a table's virtual schema.
     *
     * @param string $title   The table title.
     * @param int    $tableId The table id.
     *
     * @return string The schema slug (`nc-<slug(title)>-t<tableId>`).
     *
     * @spec openspec/changes/tables-object-source-provider/specs/tables-virtual-register/spec.md
     */
    public function deterministicSlug(string $title, int $tableId): string
    {
        $slug = strtolower(trim($title));
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = 'table';
        }

        return sprintf('nc-%s-t%d', $slug, $tableId);
    }//end deterministicSlug()

    /**
     * Find or create the `tables` virtual register.
     *
     * @return Register The existing or newly created register.
     *
     * @spec openspec/changes/tables-object-source-provider/specs/tables-virtual-register/spec.md
     */
    private function ensureRegister(): Register
    {
        try {
            return $this->registerMapper->find(self::REGISTER_SLUG, _rbac: false, _multitenancy: false);
        } catch (Throwable $e) {
            return $this->registerMapper->createFromArray(
                object: [
                    'title'       => 'Tables',
                    'slug'        => self::REGISTER_SLUG,
                    'description' => 'Read-only virtual register projecting Nextcloud Tables tables as OpenRegister objects.',
                    'application' => self::REGISTER_SLUG,
                    'schemas'     => [],
                ]
            );
        }
    }//end ensureRegister()

    /**
     * Seed or refresh the managed schema for one table.
     *
     * @param Register             $register The `tables` register.
     * @param array<string, mixed> $table    The table descriptor ({id, title, columns}).
     *
     * @return bool True when the schema was created or refreshed, false when skipped.
     *
     * @spec openspec/changes/tables-object-source-provider/specs/tables-virtual-register/spec.md
     */
    private function seedTable(Register $register, array $table): bool
    {
        $tableId = (int) ($table['id'] ?? 0);
        $title   = (string) ($table['title'] ?? '');
        $slug    = $this->deterministicSlug(title: $title, tableId: $tableId);
        $shape   = $this->columnMapper->buildSchemaProperties(columns: (array) ($table['columns'] ?? []));

        $configuration = [
            'x-schema-org'                 => 'schema:Thing',
            'x-openregister-object-source' => [
                'provider' => self::PROVIDER_ID,
                'readOnly' => true,
                'config'   => ['tableId' => $tableId, 'managed' => true],
            ],
        ];

        $schemaTitle = $title;
        if ($schemaTitle === '') {
            $schemaTitle = $slug;
        }

        $payload = [
            'title'         => $schemaTitle,
            'slug'          => $slug,
            'description'   => sprintf('Read-only virtual schema for Nextcloud Tables table #%d.', $tableId),
            'properties'    => $shape['properties'],
            'required'      => $shape['required'],
            'configuration' => $configuration,
        ];

        try {
            $existing = $this->schemaMapper->find($slug, _rbac: false, _multitenancy: false);
        } catch (Throwable $e) {
            $schema = $this->schemaMapper->createFromArray(object: $payload);
            $this->linkSchema(register: $register, schema: $schema);
            return true;
        }

        if ($this->isManaged(schema: $existing) === false) {
            $this->logger->warning(
                sprintf('[ObjectSource:tables] schema "%s" is hand-authored — not overwriting during sync', $slug)
            );
            return false;
        }

        $this->schemaMapper->updateFromArray(id: (int) $existing->getId(), object: $payload);
        $this->linkSchema(register: $register, schema: $existing);

        return true;
    }//end seedTable()

    /**
     * Retire managed schemas whose table is no longer present.
     *
     * @param Register        $register     The `tables` register.
     * @param array<int, int> $keepTableIds Table ids to keep (empty when only retiring one).
     * @param int|null        $onlyTableId  When set, retire only this table's schema.
     *
     * @return int The number of schemas retired.
     *
     * @spec openspec/changes/tables-object-source-provider/specs/tables-virtual-register/spec.md
     */
    private function retireMissing(Register $register, array $keepTableIds, ?int $onlyTableId=null): int
    {
        $retired   = 0;
        $schemaIds = $register->getSchemas();
        $changed   = false;
        foreach ($this->managedSchemasByTableId(register: $register) as $tableId => $schema) {
            if ($onlyTableId !== null && $tableId !== $onlyTableId) {
                continue;
            }

            if ($onlyTableId === null && in_array($tableId, $keepTableIds, true) === true) {
                continue;
            }

            // **DELETE SAFETY (runtime-schema-api)**: no `force: true` here.
            //
            // These schemas are read-only MIRRORS of Nextcloud Tables tables — their
            // objects live in Tables, not in a magic table — so the repaired
            // SchemaMapper guard normally counts 0 and retiring a mirror of a
            // now-deleted table proceeds exactly as before. If a magic table DOES hold
            // rows for such a schema, that is real user data that this sync job has no
            // mandate to destroy; refusing (and leaving the schema linked) is the safe
            // outcome. Forcing here would silently orphan it.
            try {
                $this->schemaMapper->delete($schema);
                $retired++;
            } catch (Throwable $e) {
                $this->logger->warning('[ObjectSource:tables] could not retire schema '.$schema->getSlug().': '.$e->getMessage());
                // Do NOT unlink a schema we failed to delete: that would detach a
                // surviving schema from its register and hide it from every editor —
                // the precise data-corruption pattern this change exists to close.
                continue;
            }

            $schemaIds = array_values(
                array_filter(
                    $schemaIds,
                    static fn($ref) => (string) $ref !== (string) $schema->getId()
                        && (string) $ref !== (string) $schema->getSlug()
                )
            );
            $changed   = true;
        }//end foreach

        if ($changed === true) {
            $register->setSchemas($schemaIds);
            $this->registerMapper->update($register);
        }

        return $retired;
    }//end retireMissing()

    /**
     * Load the managed schemas of the register, keyed by their bound table id.
     *
     * @param Register $register The `tables` register.
     *
     * @return array<int, Schema> Map of tableId → managed schema.
     *
     * @spec openspec/changes/tables-object-source-provider/specs/tables-virtual-register/spec.md
     */
    private function managedSchemasByTableId(Register $register): array
    {
        $result = [];
        foreach ($register->getSchemas() as $ref) {
            try {
                $schema = $this->schemaMapper->find($ref, _rbac: false, _multitenancy: false);
            } catch (Throwable $e) {
                continue;
            }

            if ($this->isManaged(schema: $schema) === false) {
                continue;
            }

            $tableId = (int) ($schema->getObjectSource()['config']['tableId'] ?? 0);
            if ($tableId !== 0) {
                $result[$tableId] = $schema;
            }
        }

        return $result;
    }//end managedSchemasByTableId()

    /**
     * Whether a schema is managed by this sync (vs hand-authored).
     *
     * @param Schema $schema The schema to test.
     *
     * @return bool True when the schema carries the managed marker.
     *
     * @spec openspec/changes/tables-object-source-provider/specs/tables-virtual-register/spec.md
     */
    private function isManaged(Schema $schema): bool
    {
        $source = $schema->getObjectSource();
        if ($source === null || $source['provider'] !== self::PROVIDER_ID) {
            return false;
        }

        return ($source['config']['managed'] ?? false) === true;
    }//end isManaged()

    /**
     * Ensure a schema is linked into the register's schema list.
     *
     * @param Register $register The `tables` register.
     * @param Schema   $schema   The schema to link.
     *
     * @return void
     *
     * @spec openspec/changes/tables-object-source-provider/specs/tables-virtual-register/spec.md
     */
    private function linkSchema(Register $register, Schema $schema): void
    {
        $schemaIds = $register->getSchemas();
        if (in_array($schema->getId(), $schemaIds, false) === true) {
            return;
        }

        $schemaIds[] = $schema->getId();
        $register->setSchemas($schemaIds);
        $this->registerMapper->update($register);
    }//end linkSchema()
}//end class
