<?php

/**
 * OpenRegister RefIntegrationProvider
 *
 * An integration provider whose operations take an ObjectRef.
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

use OCA\OpenRegister\Kernel\Contracts\ObjectRef;

/**
 * An integration provider in the parameter-object form.
 *
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-1
 */
interface RefIntegrationProvider extends IntegrationProvider {
	/**
	 * List linked things for an OR object.
	 *
	 * Implementations MAY honour the `$filters` array — common keys
	 * are `_limit` (int), `_page` (int), `_search` (string). Unknown
	 * filters MUST be ignored, not rejected.
	 *
	 * Return shape: implementations MAY return either
	 *   - a flat list `array<int,array<string,mixed>>`, or
	 *   - the paginated envelope `{items|results: array, total: int,
	 *     nextCursor: ?string}` (a `PaginatedResult` or its array form)
	 *     when the provider knows the cross-page total and/or can
	 *     produce a cursor for the next page.
	 *
	 * Either shape is accepted: `ObjectIntegrationsController` runs the
	 * value through {@see PaginatedResult::fromMixed()} and always emits
	 * the canonical `{items, total, nextCursor}` envelope to the client.
	 * Returning the envelope is preferred for providers backed by a
	 * counted/paged store, because the flat-list fallback sets
	 * `total = count(items)`, which disables frontend load-more.
	 *
	 * @param ObjectRef $ref The owning object: register, schema and uuid.
	 * @param array<string,mixed> $filters Optional list filters.
	 *
	 * @return array<int,array<string,mixed>>|array<string,mixed> Flat list
	 *                                                            of linked things, or a `{items, total, nextCursor}` envelope.
	 *
	 * @spec exclude Interface method declaration — pure contract shape; the behaviour lives in the implementing
	 *              providers (annotated to their integration-* change / pluggable-integration-registry task-1).
	 */
	public function list(ObjectRef $ref, array $filters = []): array;

	/**
	 * Get a single linked thing by id.
	 *
	 * Used by widget surface='single-entity' rendering — e.g. when a
	 * schema property of type 'reference' carries a referenceType
	 * marker pointing at this integration, `CnFormDialog` / `CnDetailGrid`
	 * fetch the referenced entity through this method (AD-18).
	 *
	 * Implementations MAY throw a `NotImplementedException` for
	 * list-only providers; the registry surfaces the failure via the
	 * widget's empty state.
	 *
	 * @param ObjectRef $ref The owning object: register, schema and uuid.
	 * @param string $entityId Linked-thing id.
	 *
	 * @return array<string,mixed> The linked thing.
	 *
	 * @spec exclude Interface method declaration — pure contract shape; the behaviour lives in the implementing
	 *              providers (annotated to their integration-* change / pluggable-integration-registry task-1).
	 */
	public function get(ObjectRef $ref, string $entityId): array;

	/**
	 * Create / attach a new linked thing.
	 *
	 * Providers with `storage='query-time'` MUST throw
	 * NotImplementedException — there is no local store to write to.
	 *
	 * @param ObjectRef $ref The owning object: register, schema and uuid.
	 * @param array<string,mixed> $payload New linked-thing fields.
	 *
	 * @return array<string,mixed> The created linked thing.
	 *
	 * @spec exclude Interface method declaration — pure contract shape; the behaviour lives in the implementing
	 *              providers (annotated to their integration-* change / pluggable-integration-registry task-1).
	 */
	public function create(ObjectRef $ref, array $payload): array;

	/**
	 * Update a linked thing.
	 *
	 * Providers with `storage='query-time'` MUST throw
	 * NotImplementedException.
	 *
	 * @param ObjectRef $ref The owning object: register, schema and uuid.
	 * @param string $entityId Linked-thing id.
	 * @param array<string,mixed> $payload Update payload.
	 *
	 * @return array<string,mixed> The updated linked thing.
	 *
	 * @spec exclude Interface method declaration — pure contract shape; the behaviour lives in the implementing
	 *              providers (annotated to their integration-* change / pluggable-integration-registry task-1).
	 */
	public function update(ObjectRef $ref, string $entityId, array $payload): array;

	/**
	 * Delete / unlink a linked thing.
	 *
	 * Providers with `storage='query-time'` MUST throw
	 * NotImplementedException.
	 *
	 * @param ObjectRef $ref The owning object: register, schema and uuid.
	 * @param string $entityId Linked-thing id.
	 *
	 * @return void
	 *
	 * @spec exclude Interface method declaration — pure contract shape; the behaviour lives in the implementing
	 *              providers (annotated to their integration-* change / pluggable-integration-registry task-1).
	 */
	public function delete(ObjectRef $ref, string $entityId): void;
}//end interface
