<?php
/**
 * ViewFinderInterface: one saved view by its id, under the caller's rules or not.
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

use OCA\OpenRegister\Kernel\Contracts\ViewInterface;

/**
 * The saved-view lookup Core needs to read a view's stored query.
 *
 * Core asks it where a view backs an object source and where an access link is
 * minted over a view; Search keeps the views and its view mapper answers,
 * bound in Search's own wiring.
 *
 * @spec openspec/specs/mariadb-ci-matrix/spec.md#requirement-a-text-identifier-is-never-bound-as-an-integer
 */
interface ViewFinderInterface {

	/**
	 * Find a view by its id or uuid.
	 *
	 * The `$_rbac` and `$_multitenancy` flags mean "trusted internal read", never
	 * "public read"; left at their defaults the session's own permissions and
	 * organisation decide.
	 *
	 * @param int|string $id            The id (integer) or uuid (string) of the view.
	 * @param bool       $_rbac         Whether to apply the RBAC read check.
	 * @param bool       $_multitenancy Whether to apply the organisation filter.
	 *
	 * @return ViewInterface The view.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When the view is not found or not accessible.
	 * @throws \Exception When the caller may not read views.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Flags mirror the mapper-wide `_rbac` / `_multitenancy` convention.
	 *
	 * @spec openspec/specs/mariadb-ci-matrix/spec.md#requirement-a-text-identifier-is-never-bound-as-an-integer
	 */
	public function find($id, bool $_rbac = true, bool $_multitenancy = true): ViewInterface;
}//end interface
