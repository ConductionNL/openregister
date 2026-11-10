<?php

/**
 * CaseTokenServiceInterface: what callers outside the component use of CaseTokenService.
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
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * The case token lookup the case token endpoint runs.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface CaseTokenServiceInterface {

	/**
	 * Resolve a token to a PUBLIC-SAFE view of the referenced object.
	 *
	 * Runs the canonical OR read path with RBAC enforced (`_rbac: true`):
	 * an anonymous caller only sees the fields the public group may read,
	 * exactly as `ObjectsController::show()` does for anonymous requests.
	 * The token never bypasses RBAC — it only addresses the object.
	 *
	 * Returns null on any failure (unknown / revoked / expired token,
	 * object missing, RBAC-denied) so the caller returns a uniform 404
	 * and the endpoint is not an enumeration oracle.
	 *
	 * THE VIEW CARRIES THE OBJECT'S PUBLIC TIMELINE. A citizen following a
	 * "track your case" link came to find out what has happened, and a status
	 * with no history answers half the question. The entries are filtered on
	 * `public` HERE, on the server, by the same service the signed-in timeline
	 * reads: nothing that says `internal` crosses this boundary, and no caller
	 * can ask this method for a different filter.
	 *
	 * @param string $token The opaque token.
	 *
	 * @return array<string,mixed>|null The public-safe object view, or
	 *                                  null when the token cannot be
	 *                                  resolved.
	 *
	 * @spec openspec/specs/integration-leaf-foundation/spec.md
	 */
	public function resolve(string $token): ?array;
}//end interface
