<?php

/**
 * AdminGateInterface: the part of AdminGate that the permissions audit controller uses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCP\AppFramework\Http\JSONResponse;

/**
 * The methods of AdminGate that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface AdminGateInterface {

	/**
	 * Whether the caller is an instance administrator.
	 *
	 * Fails closed when either collaborator is absent, or when nobody is signed
	 * in, so an unanswerable question is never read as a yes.
	 *
	 * @return bool True when the signed-in caller is an admin.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function isAdmin(): bool;

	/**
	 * The response a refused caller gets.
	 *
	 * Lives with the gate so every caller refuses in the same shape. The point
	 * of gating explicitly rather than letting SecurityMiddleware throw is that
	 * the 403 looks like every other 403 in this app; that only holds if the
	 * body is written in one place.
	 *
	 * @param string $doing What the caller was trying to read or change.
	 *
	 * @return JSONResponse A 403 naming the requirement.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function refusal(string $doing): JSONResponse;
}//end interface
