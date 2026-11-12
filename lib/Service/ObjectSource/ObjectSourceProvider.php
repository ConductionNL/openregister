<?php

/**
 * ObjectSourceProvider — read-only source of OpenRegister objects backed by a
 * system OTHER than the OpenRegister magic tables (e.g. a CalDAV VTODO
 * collection, or any leaf-integration entity).
 *
 * A provider lets a schema's objects be served LIVE from an external source
 * without copying them into OpenRegister storage. It is strictly read-only:
 * there is no create/update/delete — the external system stays authoritative
 * and writes to a sourced schema are rejected upstream (see GetObject/SaveObject).
 * Returned ObjectEntity instances are built in memory and never persisted.
 *
 * This mirrors the IntegrationProvider `query-time` storage strategy (live read,
 * no local persistence) but operates at the SCHEMA-OBJECT level rather than on
 * per-object sub-resources.
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
 * @spec openspec/changes/object-source-providers/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Read-only provider that serves a schema's objects from an external source.
 */
interface ObjectSourceProvider {
	/**
	 * Stable provider id referenced by `x-openregister-object-source.provider`.
	 *
	 * @return string The provider id (e.g. 'caldav-vtodo').
	 *
	 * @spec openspec/changes/object-source-providers/tasks.md#task-1.1
	 */
	public function getId(): string;

	/**
	 * Whether this provider can serve objects on this instance right now
	 * (e.g. the backing Nextcloud app is installed and enabled).
	 *
	 * When false, reads of a bound schema degrade to an empty result with a
	 * logged warning rather than erroring or falling back to the database.
	 *
	 * @return bool True when the provider is usable.
	 *
	 * @spec openspec/changes/object-source-providers/tasks.md#task-1.1
	 */
	public function isEnabled(): bool;

	/**
	 * Find a single virtual object by id, applying the schema's read
	 * authorization for the acting user.
	 *
	 * MUST return null when the object is absent OR access is denied, so the
	 * two cases are indistinguishable (no enumeration oracle).
	 *
	 * @param SourcedTable $source The register, the sourced schema being read and its object-source `config` block.
	 * @param string       $id     The object id / source key.
	 *
	 * @return ObjectEntity|null The virtual object, or null when absent/denied.
	 *
	 * @spec openspec/changes/object-source-providers/tasks.md#task-1.1
	 */
	public function find(SourcedTable $source, string $id): ?ObjectEntity;

	/**
	 * Find all virtual objects matching the query, applying the schema's read
	 * authorization for the acting user (denied objects are omitted).
	 *
	 * A provider MAY ignore query operators it cannot honour; it MUST document
	 * what it supports and MUST NOT silently cap results without a logged warning.
	 *
	 * @param SourcedTable         $source The register, the sourced schema being read and its object-source `config` block.
	 * @param array<string, mixed> $query  Query (filters/sort/search/limit/offset).
	 *
	 * @return ObjectEntity[] The matching virtual objects (possibly empty).
	 *
	 * @spec openspec/changes/object-source-providers/tasks.md#task-1.1
	 */
	public function findAll(SourcedTable $source, array $query = []): array;

	/**
	 * Count virtual objects matching the query (same authorization as findAll).
	 *
	 * @param SourcedTable         $source The register, the sourced schema being read and its object-source `config` block.
	 * @param array<string, mixed> $query  Query (filters/search).
	 *
	 * @return int The number of matching virtual objects.
	 *
	 * @spec openspec/changes/object-source-providers/tasks.md#task-1.1
	 */
	public function count(SourcedTable $source, array $query = []): int;
}//end interface
