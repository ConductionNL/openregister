<?php

/**
 * LinkedEntityServiceInterface: what callers outside the Service component ask of LinkedEntityService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use Exception;

/**
 * Links Nextcloud entities to objects, registers and schemas, and looks the links up in reverse.
 */
interface LinkedEntityServiceInterface {

	/**
	 * Add a linked entity ID to an object's metadata column.
	 *
	 * @param string $objectUuid The object UUID
	 * @param string $type The linked entity type (e.g., 'mail', 'contacts')
	 * @param string $entityId The entity ID to add
	 *
	 * @throws Exception If the type is invalid or the object is not found
	 *
	 * @return array The updated linked IDs array
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md#requirement-metadata-columns-on-entity-tables
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function addLink(string $objectUuid, string $type, string $entityId): array;

	/**
	 * Add a linked entity ID to a register's metadata column.
	 *
	 * @param string $registerUuid The register UUID
	 * @param string $type The linked entity type
	 * @param string $entityId The entity ID to add
	 *
	 * @throws Exception If the type is invalid
	 *
	 * @return array The updated linked IDs array
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md#requirement-metadata-columns-on-entity-tables
	 */
	public function addLinkToRegister(string $registerUuid, string $type, string $entityId): array;

	/**
	 * Add a linked entity ID to a schema's metadata column.
	 *
	 * @param string $schemaUuid The schema UUID
	 * @param string $type The linked entity type
	 * @param string $entityId The entity ID to add
	 *
	 * @throws Exception If the type is invalid
	 *
	 * @return array The updated linked IDs array
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md#requirement-metadata-columns-on-entity-tables
	 */
	public function addLinkToSchema(string $schemaUuid, string $type, string $entityId): array;

	/**
	 * Remove a linked entity ID from an object's metadata column.
	 *
	 * @param string $objectUuid The object UUID
	 * @param string $type The linked entity type
	 * @param string $entityId The entity ID to remove
	 *
	 * @throws Exception If the type is invalid or the object is not found
	 *
	 * @return array The updated linked IDs array
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md#requirement-remove-link-entities-and-mappers
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function removeLink(string $objectUuid, string $type, string $entityId): array;

	/**
	 * Reverse lookup: find all objects and entities linked to a specific entity.
	 *
	 * Scans magic tables (for schemas with the corresponding linkedType) and
	 * entity tables (registers, schemas, organisations) for the given entity ID.
	 *
	 * @param string $type The linked entity type (e.g., 'mail')
	 * @param string $entityId The entity ID to search for
	 *
	 * @throws Exception If the type is invalid
	 *
	 * @return array Array of result objects with entityType, uuid, name, etc.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md#requirement-reverse-lookup-across-tables
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function reverseLookup(string $type, string $entityId): array;
}//end interface
