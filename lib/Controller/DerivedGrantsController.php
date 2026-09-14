<?php

/**
 * The re-run an administrator presses after changing a claim rule.
 *
 * An access change nobody is told about is the one that surprises an auditor.
 * Narrowing a rule that reached two hundred people is a decision somebody should
 * see the size of before they walk away from the screen, so this answers in
 * numbers and names the accounts whose access moved (design D-11).
 *
 * WHY IT IS NOT AUTOMATIC. Re-deriving on every rule save would put an
 * unbounded walk over every account inside a request that was meant to store one
 * field. The administrator asks for it, reads the count, and decides.
 *
 * ADMINISTRATOR ONLY, by the framework rather than by a check in the method.
 * The route carries no `#[NoAdminRequired]`, so Nextcloud's middleware refuses
 * everybody else before the method runs. A body check beside that attribute is
 * the exact mismatch the semantic-auth gate exists to catch.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\Rbac\DerivedGrantStore;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Re-runs the claim derivation and reports what moved.
 *
 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
 */
class DerivedGrantsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string            $appName App identifier.
	 * @param IRequest          $request Active HTTP request.
	 * @param DerivedGrantStore $store   The rules, the claims and the grants.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly DerivedGrantStore $store,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Re-derive every account that has claims, and say how many moved.
	 *
	 * Response shape:
	 *
	 *   {"ruleCount": 3, "users": 240, "changed": 12,
	 *    "grantsBefore": 240, "grantsAfter": 228, "accounts": ["bea", ...]}
	 *
	 * @return JSONResponse What the re-run did.
	 *
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	#[NoCSRFRequired]
	public function reapply(): JSONResponse {
		$rules = $this->store->rules();

		// An instance with no rules has nothing to re-run, and saying so is a
		// different answer from "nothing moved". The second reads as a rule that
		// had no effect, which is what somebody debugging a rule would most like
		// to be told by mistake.
		if ($rules === []) {
			return new JSONResponse(
				[
					'ruleCount' => 0,
					'users' => 0,
					'changed' => 0,
					'message' => 'No claim rules are declared, so nothing was derived',
				]
			);
		}

		return new JSONResponse(array_merge(['ruleCount' => count($rules)], $this->store->reapply()));
	}//end reapply()
}//end class
