<?php

/**
 * OpenRegister LegacyIntegrationProvider
 *
 * An integration provider in the released positional form.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
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

namespace OCA\OpenRegister\Service\Integration;

/**
 * An integration provider in the positional form of the released contract.
 *
 * @deprecated Implement RefIntegrationProvider.
 *
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-1
 */
interface LegacyIntegrationProvider extends IntegrationProvider {
	/**
	 * List the linked things for one object.
	 *
	 * @param string              $register Register slug or numeric id.
	 * @param string              $schema   Schema slug or numeric id.
	 * @param string              $objectId Object uuid.
	 * @param array<string,mixed> $filters  Optional list filters.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function list(string $register, string $schema, string $objectId, array $filters=[]): array;

	/**
	 * Get a single linked thing.
	 *
	 * @param string $register Register slug or numeric id.
	 * @param string $schema   Schema slug or numeric id.
	 * @param string $objectId Owning object uuid.
	 * @param string $entityId Linked-thing id.
	 *
	 * @return array<string,mixed>
	 */
	public function get(string $register, string $schema, string $objectId, string $entityId): array;

	/**
	 * Create / attach a linked thing.
	 *
	 * @param string              $register Register slug or numeric id.
	 * @param string              $schema   Schema slug or numeric id.
	 * @param string              $objectId Owning object uuid.
	 * @param array<string,mixed> $payload  New linked-thing fields.
	 *
	 * @return array<string,mixed>
	 */
	public function create(string $register, string $schema, string $objectId, array $payload): array;

	/**
	 * Update a linked thing.
	 *
	 * @param string              $register Register slug or numeric id.
	 * @param string              $schema   Schema slug or numeric id.
	 * @param string              $objectId Owning object uuid.
	 * @param string              $entityId Linked-thing id.
	 * @param array<string,mixed> $payload  Update payload.
	 *
	 * @return array<string,mixed>
	 */
	public function update(string $register, string $schema, string $objectId, string $entityId, array $payload): array;

	/**
	 * Delete / unlink a linked thing.
	 *
	 * @param string $register Register slug or numeric id.
	 * @param string $schema   Schema slug or numeric id.
	 * @param string $objectId Owning object uuid.
	 * @param string $entityId Linked-thing id.
	 *
	 * @return void
	 */
	public function delete(string $register, string $schema, string $objectId, string $entityId): void;
}//end interface
