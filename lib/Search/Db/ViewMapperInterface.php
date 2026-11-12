<?php

/**
 * ViewMapperInterface: the public surface of ViewMapper, for callers to type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Search
 * @package  OCA\OpenRegister\Search\Db
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

namespace OCA\OpenRegister\Search\Db;

use OCA\OpenRegister\Service\Rbac\ViewerReach;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use Symfony\Component\Uid\Uuid;

/**
 * Every public method of ViewMapper. Callers depend on this interface, not on the class.
 */
interface ViewMapperInterface {

	/**
	 * Views carrying an alert, oldest evaluation first.
	 *
	 * 🔑 THE ORDER IS THE WATERMARK. Taking the least recently evaluated views
	 * means a bounded pass walks the whole set over several ticks instead of
	 * re-counting the same busy ones, so no view starves behind a neighbour.
	 * A view never evaluated sorts first, which is what makes an alert somebody
	 * set a minute ago run on the next pass.
	 *
	 * @param int $limit Most views to return.
	 *
	 * @return View[] The views.
	 *
	 * @psalm-return array<int, View>
	 *
	 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-the-alert-sweep-is-bounded
	 */
	public function findWithAlerts(int $limit): array;

	/**
	 * Find a view by its ID
	 *
	 * Retrieves view by ID (supports both integer ID and UUID) with RBAC and
	 * organisation filtering. Verifies user has read permission before querying.
	 *
	 * The `$_rbac` / `$_multitenancy` flags mirror the pair every other mapper in
	 * this app carries (see SchemaMapper::find()): they mean "trusted internal
	 * read", never "public read". They exist so a caller that has ALREADY
	 * established its own authorization - a published access link, which IS the
	 * authorization - can resolve the one view it is entitled to serve even
	 * though there is no session to judge. Left at their defaults the ordinary
	 * behaviour stands: the session's own permissions and organisation decide.
	 *
	 * @param int|string $id The ID (integer) or UUID (string) of the view to find
	 * @param bool $_rbac Whether to apply the RBAC read check (default: true)
	 * @param bool $_multitenancy Whether to apply the organisation filter (default: true)
	 *
	 * @return View The found view entity
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If view not found or not accessible
	 * @throws \Exception If user doesn't have read permission for views
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Flags mirror the mapper-wide
	 * `_rbac` / `_multitenancy` convention.
	 *
	 * @spec openspec/specs/mariadb-ci-matrix/spec.md#requirement-a-text-identifier-is-never-bound-as-an-integer
	 */
	public function find($id, bool $_rbac = true, bool $_multitenancy = true): View;

	/**
	 * Find all views for a specific owner
	 *
	 * @param string $owner The owner user ID
	 *
	 * @return View[]
	 *
	 * @throws \Exception If user doesn't have read permission
	 *
	 * @psalm-return list<\OCA\OpenRegister\Search\Db\View>
	 */
	public function findAll(?string $owner = null): array;

	/**
	 * Every view this caller may see, each carrying the access they hold.
	 *
	 * The union `view-group-share` asks for: the caller's own views, the views
	 * shared with a group they are in, and the public ones.
	 *
	 * 🔑 THE GROUP MATCH IS DONE IN PHP AND THAT IS DELIBERATE. `shared_with`
	 * is JSON in a TEXT column, and the portable ways to match inside it are a
	 * `LIKE '%"group":"x"%'` — which matches a group whose name is a substring
	 * of another, and breaks the day the encoder emits a space after the colon
	 * — or a backend-specific JSON operator, which is four spellings that have
	 * to agree forever on a question about authorization. The row count makes
	 * the choice free: a view list is tens of rows per organisation, not
	 * millions, so the SQL narrows to "mine, public, or shared with anybody"
	 * and this walks what comes back.
	 *
	 * A view the caller may not see is DROPPED rather than returned without an
	 * access: a row with a null access reaching a client is a row somebody
	 * renders.
	 *
	 * @param ViewerReach $reach The caller, their groups and whether they administer the instance.
	 *
	 * @return View[] The views, each with its `access` set.
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 * @spec openspec/specs/saved-search-views/spec.md
	 */
	public function findAllFor(ViewerReach $reach): array;

	/**
	 * Create a new view from an Entity
	 *
	 * @param View $entity The view entity to create
	 *
	 * @return View The created view
	 * @throws \Exception If user doesn't have create permission
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Uuid::v4 is standard Symfony UID pattern
	 */
	public function insert(Entity $entity): View;

	/**
	 * Update an existing view
	 *
	 * @param View $entity The view entity to update
	 *
	 * @return View The updated view
	 * @throws \Exception If user doesn't have update permission or access to this organisation
	 */
	public function update(Entity $entity): View;

	/**
	 * Delete a view
	 *
	 * @param View $entity The view entity to delete
	 *
	 * @return View The deleted view
	 * @throws \Exception If user doesn't have delete permission or access to this organisation
	 *
	 * @psalm-suppress PossiblyUnusedReturnValue
	 */
	public function delete(Entity $entity): View;

	/**
	 * InsertOrUpdate through the mapper (QBMapper).
	 *
	 * @param View $entity The entity.
	 *
	 * @return View The stored entity.
	 */
	public function insertOrUpdate(Entity $entity): Entity;
}//end interface
