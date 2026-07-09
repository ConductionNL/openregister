<?php

/**
 * TablesUuidDeriver — derives the deterministic UUID of a virtual object that
 * projects a single Nextcloud Tables row.
 *
 * Every virtual object served by {@see TablesObjectSourceProvider} must carry a
 * stable, content-addressable uuid so that (a) re-reading the same row always
 * yields the same object id and (b) a `relation` cell pointing at another table's
 * row can be mapped to that row's virtual-object uuid WITHOUT a per-row lookup
 * (see the design, D9). The uuid is a UUIDv5 over a fixed OpenRegister namespace
 * with the name `tables:<tableId>:<rowId>`, so a relation link and the referenced
 * object's own uuid always agree and OR-level deep-linking works across the
 * auto-seeded virtual schemas.
 *
 * UUIDv5 is one-way, so a UUID cannot be inverted back to a `(tableId, rowId)`
 * pair; {@see TablesObjectSourceProvider::find()} resolves a UUID by scanning the
 * bound table and comparing derived uuids (bounded + logged).
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

use Symfony\Component\Uid\Uuid;

/**
 * Deterministic UUIDv5 derivation for Tables-backed virtual objects.
 */
final class TablesUuidDeriver
{

    /**
     * The fixed OpenRegister namespace UUID for Tables-derived object uuids.
     *
     * A stable, purpose-specific RFC-4122 namespace so the derivation never
     * collides with any other UUIDv5 namespace in the system. It is a constant of
     * the derivation contract and MUST NOT change (changing it would silently
     * re-key every virtual object and break existing relation deep-links).
     *
     * @var string
     */
    public const NAMESPACE_UUID = '4b3d2c1a-5e6f-4a7b-8c9d-0e1f2a3b4c5d';

    /**
     * Derive the deterministic uuid of the virtual object for a Tables row.
     *
     * @param int $tableId The id of the table the row lives in.
     * @param int $rowId   The Tables row id.
     *
     * @return string The RFC-4122 UUIDv5 string.
     *
     * @SuppressWarnings(PHPMD.StaticAccess) Uuid::v5/fromString are the standard symfony/uid factories.
     *
     * @spec openspec/changes/tables-object-source-provider/specs/tables-virtual-register/spec.md
     */
    public function deriveObjectUuid(int $tableId, int $rowId): string
    {
        $namespace = Uuid::fromString(self::NAMESPACE_UUID);

        return Uuid::v5($namespace, sprintf('tables:%d:%d', $tableId, $rowId))->toRfc4122();
    }//end deriveObjectUuid()

    /**
     * Whether an id looks like an RFC-4122 UUID (rather than a numeric rowId).
     *
     * @param string $id The candidate id.
     *
     * @return bool True when the id is a valid UUID string.
     *
     * @SuppressWarnings(PHPMD.StaticAccess) Uuid::isValid is the standard symfony/uid validator.
     *
     * @spec openspec/changes/tables-object-source-provider/specs/tables-virtual-register/spec.md
     */
    public function looksLikeUuid(string $id): bool
    {
        return Uuid::isValid($id);
    }//end looksLikeUuid()
}//end class
