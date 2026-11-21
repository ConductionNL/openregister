<?php
/**
 * StoredViewQueriesInterface: the stored query of a saved view, as a search scoped by it reads it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

/**
 * The stored query of a saved view.
 *
 * Object search (Core) narrows a search to the registers and schemas a view stores;
 * Search keeps the views and binds its answer in its own wiring. A view that cannot
 * be resolved throws, exactly as the view store throws it.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */
interface StoredViewQueriesInterface {

	/**
	 * The stored query of a view, read with or without the view store's own access guards.
	 *
	 * @param int|string $viewId  The view.
	 * @param bool       $guarded True to read it under the view store's RBAC and multitenancy.
	 *
	 * @return array<string, mixed>|null The stored query.
	 *
	 * @throws \Exception When the view cannot be resolved.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function queryOf(int|string $viewId, bool $guarded): ?array;

	/**
	 * The stored query of a view a user may use: their own, or a public one.
	 *
	 * @param int|string $viewId The view.
	 * @param string     $owner  The user.
	 *
	 * @return array<string, mixed>|null The stored query.
	 *
	 * @throws \Exception When the view cannot be resolved or the user may not use it.
	 *
	 * @spec openspec/changes/search-file-text-and-vector-facade
	 */
	public function usableQueryOf(int|string $viewId, string $owner): ?array;
}//end interface
