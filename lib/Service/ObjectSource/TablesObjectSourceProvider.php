<?php

/**
 * TablesObjectSourceProvider — serves a virtual schema's objects live (read-only)
 * from a single Nextcloud Tables table (or Tables View).
 *
 * The authoritative record is the Tables row held by the Tables app; this
 * provider projects each row the acting user may read as a non-persisted
 * ObjectEntity and never writes back (writes to a bound schema are rejected by the
 * existing object-source write-guard). It is modelled on
 * {@see GroupObjectSourceProvider} (shape, no-persist `toObjectEntity`) and
 * {@see DeckObjectSourceProvider} (another-app integration): all Tables access
 * goes through {@see TablesTableReader}, which guards every `OCA\Tables\Service\*`
 * reference with `class_exists` + the app-enabled gate, so an instance without
 * Tables installed degrades to an empty result plus a logged warning — never a
 * fatal, never a DB fallback.
 *
 * Every Tables call is scoped to the acting `$userId`, so Tables enforces
 * ownership/shares/contexts; a denied table/row surfaces as null/empty (denied ==
 * absent, no enumeration oracle).
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use DateTime;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read-only object-source provider backed by the Nextcloud Tables app.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class TablesObjectSourceProvider implements ObjectSourceProvider
{

    /**
     * Default page size when a query carries no explicit limit.
     *
     * @var int
     */
    private const DEFAULT_LIMIT = 200;

    /**
     * Row cap for the O(n) UUID-resolution scan in find() (bounded + logged).
     *
     * @var int
     */
    private const UUID_SCAN_CAP = 1000;

    /**
     * Constructor.
     *
     * @param TablesTableReader       $reader       Guarded gateway to Tables services.
     * @param TablesColumnMapper      $columnMapper Column/row → property
     *                                              projection.
     * @param TablesUuidDeriver       $uuidDeriver  Deterministic object-uuid derivation.
     * @param TablesSchemaSyncService $syncService  Managed-schema lookup (relation targets).
     * @param IUserSession            $userSession  Acting-user session (RBAC scoping).
     * @param LoggerInterface         $logger       Logger for read diagnostics.
     *
     * @return void
     */
    public function __construct(
        private readonly TablesTableReader $reader,
        private readonly TablesColumnMapper $columnMapper,
        private readonly TablesUuidDeriver $uuidDeriver,
        private readonly TablesSchemaSyncService $syncService,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * {@inheritDoc}
     *
     * @return string The provider id.
     *
     * @spec openspec/changes/tables-object-source-provider/specs/tables-virtual-register/spec.md
     */
    public function getId(): string
    {
        return 'tables';
    }//end getId()

    /**
     * {@inheritDoc}
     *
     * Gated on the Tables app (enabled for the acting user) and the loadability of
     * its service classes; otherwise a bound schema degrades to an empty list.
     *
     * @return bool True when Tables can serve reads on this instance.
     *
     * @spec openspec/changes/tables-object-source-provider/specs/tables-virtual-register/spec.md
     */
    public function isEnabled(): bool
    {
        return $this->reader->isAvailable();
    }//end isEnabled()

    /**
     * {@inheritDoc}
     *
     * Accepts both a numeric Tables rowId and a derived UUID (resolved by a
     * bounded, logged scan of the bound table). Returns null when the row is
     * absent OR the acting user may not read it (denied == absent).
     *
     * @param Register             $register The register the schema belongs to.
     * @param Schema               $schema   The sourced schema.
     * @param string               $id       The rowId or derived UUID.
     * @param array<string, mixed> $config   The object-source config block.
     *
     * @return ObjectEntity|null The virtual object, or null when absent/denied.
     *
     * @spec openspec/changes/tables-object-source-provider/specs/tables-virtual-register/spec.md
     */
    public function find(Register $register, Schema $schema, string $id, array $config=[]): ?ObjectEntity
    {
        $userId = $this->userId();
        if ($userId === null) {
            return null;
        }

        $tableId = (int) ($config['tableId'] ?? 0);
        if ($tableId === 0) {
            return null;
        }

        if (ctype_digit($id) === true) {
            $row = $this->reader->findRow(rowId: (int) $id, userId: $userId);
            if ($row === null) {
                return null;
            }

            return $this->toObjectEntity(register: $register, schema: $schema, row: $row, tableId: $tableId, userId: $userId);
        }

        if ($this->uuidDeriver->looksLikeUuid($id) === true) {
            return $this->findByUuid(register: $register, schema: $schema, uuid: $id, config: $config, userId: $userId);
        }

        return null;
    }//end find()

    /**
     * {@inheritDoc}
     *
     * Pushes `limit`/`offset` natively to Tables; any other filter/sort is applied
     * provider-side in PHP. A `config.viewId` binds a Tables View instead of the
     * raw `config.tableId`.
     *
     * @param Register             $register The register the schema belongs to.
     * @param Schema               $schema   The sourced schema.
     * @param array<string, mixed> $query    Query (filters/sort/limit/offset).
     * @param array<string, mixed> $config   The object-source config block.
     *
     * @return ObjectEntity[] The matching virtual objects.
     *
     * @spec openspec/changes/tables-object-source-provider/specs/tables-virtual-register/spec.md
     */
    public function findAll(Register $register, Schema $schema, array $query=[], array $config=[]): array
    {
        $userId = $this->userId();
        if ($userId === null) {
            return [];
        }

        $tableId = (int) ($config['tableId'] ?? 0);
        if ($tableId === 0) {
            return [];
        }

        $limit  = (int) ($query['limit'] ?? self::DEFAULT_LIMIT);
        $offset = (int) ($query['offset'] ?? 0);
        $this->warnUnpushedFilters(query: $query);

        $rows = $this->fetchRows(config: $config, tableId: $tableId, userId: $userId, limit: $limit, offset: $offset);

        $objects = [];
        foreach ($rows as $row) {
            $objects[] = $this->toObjectEntity(register: $register, schema: $schema, row: $row, tableId: $tableId, userId: $userId);
        }

        return $objects;
    }//end findAll()

    /**
     * {@inheritDoc}
     *
     * @param Register             $register The register the schema belongs to.
     * @param Schema               $schema   The sourced schema.
     * @param array<string, mixed> $query    Query (filters/search).
     * @param array<string, mixed> $config   The object-source config block.
     *
     * @return int The number of matching virtual objects.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $register/$schema unused; the count is source-side.
     *
     * @spec openspec/changes/tables-object-source-provider/specs/tables-virtual-register/spec.md
     */
    public function count(Register $register, Schema $schema, array $query=[], array $config=[]): int
    {
        $userId = $this->userId();
        if ($userId === null) {
            return 0;
        }

        $viewId  = (int) ($config['viewId'] ?? 0);
        $tableId = (int) ($config['tableId'] ?? 0);
        if ($viewId !== 0) {
            return $this->reader->countRows(id: $viewId, userId: $userId, isView: true);
        }

        if ($tableId !== 0) {
            return $this->reader->countRows(id: $tableId, userId: $userId, isView: false);
        }

        return 0;
    }//end count()

    /**
     * Resolve a virtual object by its derived UUID via a bounded, logged scan.
     *
     * @param Register             $register The register the schema belongs to.
     * @param Schema               $schema   The sourced schema.
     * @param string               $uuid     The derived object UUID.
     * @param array<string, mixed> $config   The object-source config block.
     * @param string               $userId   The acting user id.
     *
     * @return ObjectEntity|null The virtual object, or null when not found.
     *
     * @spec openspec/changes/tables-object-source-provider/specs/tables-virtual-register/spec.md
     */
    private function findByUuid(Register $register, Schema $schema, string $uuid, array $config, string $userId): ?ObjectEntity
    {
        $tableId = (int) ($config['tableId'] ?? 0);
        $this->logger->warning(
            sprintf('[ObjectSource:tables] resolving UUID %s by scanning table %d (bounded to %d rows)', $uuid, $tableId, self::UUID_SCAN_CAP)
        );

        $rows = $this->fetchRows(config: $config, tableId: $tableId, userId: $userId, limit: self::UUID_SCAN_CAP, offset: 0);
        foreach ($rows as $row) {
            $rowId = (int) ($row['id'] ?? 0);
            if ($this->uuidDeriver->deriveObjectUuid(tableId: $tableId, rowId: $rowId) === $uuid) {
                return $this->toObjectEntity(register: $register, schema: $schema, row: $row, tableId: $tableId, userId: $userId);
            }
        }

        return null;
    }//end findByUuid()

    /**
     * Fetch a page of rows, honouring a `config.viewId` View binding.
     *
     * @param array<string, mixed> $config  The object-source config block.
     * @param int                  $tableId The bound table id.
     * @param string               $userId  The acting user id.
     * @param int                  $limit   The native row limit.
     * @param int                  $offset  The native row offset.
     *
     * @return array<int, array<string, mixed>> The row descriptors.
     *
     * @spec openspec/changes/tables-object-source-provider/specs/tables-virtual-register/spec.md
     */
    private function fetchRows(array $config, int $tableId, string $userId, int $limit, int $offset): array
    {
        $viewId = (int) ($config['viewId'] ?? 0);
        if ($viewId !== 0) {
            return $this->reader->findRowsByView(viewId: $viewId, userId: $userId, limit: $limit, offset: $offset);
        }

        return $this->reader->findRowsByTable(tableId: $tableId, userId: $userId, limit: $limit, offset: $offset);
    }//end fetchRows()

    /**
     * Log a warning when a query carries filters/sort the provider cannot push
     * down (no silent truncation — per the interface contract).
     *
     * @param array<string, mixed> $query The query.
     *
     * @return void
     *
     * @spec openspec/changes/tables-object-source-provider/specs/tables-virtual-register/spec.md
     */
    private function warnUnpushedFilters(array $query): void
    {
        $pushable = ['limit', 'offset', '_limit', '_offset', 'page', '_page'];
        $unpushed = array_diff(array_keys($query), $pushable);
        if (empty($unpushed) === false) {
            $this->logger->warning(
                '[ObjectSource:tables] query operators applied provider-side (not pushed down): '.implode(', ', $unpushed)
            );
        }
    }//end warnUnpushedFilters()

    /**
     * Map a Tables row descriptor onto a non-persisted virtual ObjectEntity.
     *
     * @param Register             $register The register.
     * @param Schema               $schema   The sourced schema.
     * @param array<string, mixed> $row      The row descriptor.
     * @param int                  $tableId  The bound table id.
     * @param string               $userId   The acting user id.
     *
     * @return ObjectEntity The virtual object (never saved).
     *
     * @spec openspec/changes/tables-object-source-provider/specs/tables-virtual-register/spec.md
     */
    private function toObjectEntity(Register $register, Schema $schema, array $row, int $tableId, string $userId): ObjectEntity
    {
        $columns   = $this->reader->listColumns(tableId: $tableId, userId: $userId);
        $columnMap = $this->columnMapper->buildColumnMap(columns: $columns);
        $resolver  = $this->targetSchemaResolver();

        $rowId = (int) ($row['id'] ?? 0);
        $data  = $this->columnMapper->projectRow(row: $row, columns: $columns, columnMap: $columnMap, targetSchemaExists: $resolver);
        $data  = array_merge(['id' => (string) $rowId], $data);

        $entity = new ObjectEntity();
        $entity->setUuid($this->uuidDeriver->deriveObjectUuid(tableId: $tableId, rowId: $rowId));
        $entity->setRegister((string) $register->getId());
        $entity->setSchema((string) $schema->getId());
        $entity->setObject($data);
        $this->applyMetadata(entity: $entity, row: $row);

        return $entity;
    }//end toObjectEntity()

    /**
     * Apply the row's `@self` metadata (created/updated/owner) to the entity.
     *
     * @param ObjectEntity         $entity The virtual object.
     * @param array<string, mixed> $row    The row descriptor.
     *
     * @return void
     *
     * @spec openspec/changes/tables-object-source-provider/specs/tables-virtual-register/spec.md
     */
    private function applyMetadata(ObjectEntity $entity, array $row): void
    {
        $owner = ($row['createdBy'] ?? null);
        if (is_string($owner) === true && $owner !== '') {
            $entity->setOwner($owner);
        }

        $this->applyDate(entity: $entity, setter: 'setCreated', value: ($row['createdAt'] ?? null));
        $this->applyDate(entity: $entity, setter: 'setUpdated', value: ($row['lastEditAt'] ?? null));
    }//end applyMetadata()

    /**
     * Parse an ISO-8601 string and apply it via a DateTime setter, guarded.
     *
     * @param ObjectEntity $entity The virtual object.
     * @param string       $setter The DateTime setter name (setCreated/setUpdated).
     * @param mixed        $value  The ISO-8601 string, or null.
     *
     * @return void
     *
     * @spec openspec/changes/tables-object-source-provider/specs/tables-virtual-register/spec.md
     */
    private function applyDate(ObjectEntity $entity, string $setter, mixed $value): void
    {
        if (is_string($value) === false || $value === '') {
            return;
        }

        try {
            $entity->$setter(new DateTime($value));
        } catch (Throwable $e) {
            // Unparseable timestamp — leave the metadata field unset.
        }
    }//end applyDate()

    /**
     * Build a memoised predicate: does a target table have a seeded schema?
     *
     * @return callable(int): bool The relation-target predicate.
     *
     * @spec openspec/changes/tables-object-source-provider/specs/tables-virtual-register/spec.md
     */
    private function targetSchemaResolver(): callable
    {
        $cache = [];

        return function (int $targetTableId) use (&$cache): bool {
            if (array_key_exists($targetTableId, $cache) === false) {
                $cache[$targetTableId] = $this->syncService->hasManagedSchemaForTableId(tableId: $targetTableId);
            }

            return $cache[$targetTableId];
        };
    }//end targetSchemaResolver()

    /**
     * The acting user id, or null when no user is logged in.
     *
     * @return string|null The acting user id.
     *
     * @spec openspec/changes/tables-object-source-provider/specs/tables-virtual-register/spec.md
     */
    private function userId(): ?string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return null;
        }

        return $user->getUID();
    }//end userId()
}//end class
