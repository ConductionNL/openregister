<?php

/**
 * WritableObjectSourceProvider — the opt-in write capability for object-source
 * providers.
 *
 * Read-only is the contract default for every object-source provider; a
 * provider that can push create/update/delete through to its external system
 * implements THIS interface in addition. The save/delete dispatch only ever
 * delegates a write when the schema annotation carries `readOnly: false` AND
 * the provider (which re-verifies its own backing configuration at write time,
 * fail-closed) accepts it. The eight Nextcloud-native providers deliberately
 * do not implement this interface.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/dbal-virtual-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Write capability for object-source providers (opt-in per provider).
 */
interface WritableObjectSourceProvider extends ObjectSourceProvider {
	/**
	 * Insert a new row/entity in the external system.
	 *
	 * @param SourcedTable         $source The register, the sourced schema and its object-source config block.
	 * @param array<string, mixed> $data   The validated object data.
	 *
	 * @return ObjectEntity The created entity as re-read from the external system.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function insert(SourcedTable $source, array $data): ObjectEntity;

	/**
	 * Update an existing row/entity in the external system.
	 *
	 * @param SourcedTable         $source The register, the sourced schema and its object-source config block.
	 * @param string               $id     The object id (external key, possibly composite-joined).
	 * @param array<string, mixed> $data   The validated object data.
	 *
	 * @return ObjectEntity The updated entity as re-read from the external system.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function update(SourcedTable $source, string $id, array $data): ObjectEntity;

	/**
	 * Delete a row/entity in the external system (hard delete).
	 *
	 * @param SourcedTable $source The register, the sourced schema and its object-source config block.
	 * @param string       $id     The object id (external key, possibly composite-joined).
	 *
	 * @return bool True when a row was deleted; false when no row matched.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function remove(SourcedTable $source, string $id): bool;
}//end interface
