<?php
/**
 * StoredViewQueries: the stored query of a saved view, read from the view store.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Search\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service;

use OCA\OpenRegister\Kernel\Contracts\Core\StoredViewQueriesInterface;
use OCA\OpenRegister\Search\Db\ViewMapperInterface;

/**
 * Answers object search's question for a view's stored query from the view mapper and the view service.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */
final class StoredViewQueries implements StoredViewQueriesInterface {

	/**
	 * Wire the view store.
	 *
	 * @param ViewMapperInterface $viewMapper Reads a view under or past its access guards.
	 * @param ViewService         $views      Reads a view a user may use.
	 */
	public function __construct(
		private readonly ViewMapperInterface $viewMapper,
		private readonly ViewService $views,
	) {
	}//end __construct()

	/**
	 * The stored query of a view, read with or without the view mapper's RBAC and multitenancy.
	 *
	 * @param int|string $viewId  The view.
	 * @param bool       $guarded True to read it under RBAC and multitenancy.
	 *
	 * @return array<string, mixed>|null The stored query.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function queryOf(int|string $viewId, bool $guarded): ?array {
		return $this->viewMapper->find($viewId, _rbac: $guarded, _multitenancy: $guarded)->getQuery();
	}//end queryOf()

	/**
	 * The stored query of a view the user owns or that is public.
	 *
	 * @param int|string $viewId The view.
	 * @param string     $owner  The user.
	 *
	 * @return array<string, mixed>|null The stored query.
	 *
	 * @spec openspec/changes/search-file-text-and-vector-facade
	 */
	public function usableQueryOf(int|string $viewId, string $owner): ?array {
		return $this->views->find(id: $viewId, owner: $owner)->getQuery();
	}//end usableQueryOf()
}//end class
