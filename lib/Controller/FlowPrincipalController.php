<?php

/**
 * Which kinds of principal this instance understands.
 *
 * 🔑 THE SERVER DECIDES WHAT IS VALID, NOT THE EDITOR. An editor that offered
 * only what it can SEARCH would silently refuse a position, a function or a
 * case role — types contributed by apps whose search it does not know — and the
 * author would have no way to say what they meant.
 *
 * The answer depends on which apps are installed, and that is intended: a flow
 * naming `position` is valid where decidiq is installed and not elsewhere, and
 * the editor should say so rather than accept it and let it fail at run time on
 * a machine nobody is watching.
 *
 * ITS OWN CONTROLLER, DELIBERATELY. This belongs beside the node catalogue by
 * subject, and `FlowController` already carries nine collaborators. Adding a
 * tenth for one endpoint would buy proximity at the cost of a constructor
 * nobody can read — and this question has no dependency on any of the other
 * nine.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\Flow\Principal\PrincipalResolverRegistry;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Serves the principal types an editor may offer.
 */
class FlowPrincipalController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                    $appName    The app id.
	 * @param IRequest                  $request    The request.
	 * @param PrincipalResolverRegistry $principals Says which kinds of principal
	 *                                              this instance understands.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PrincipalResolverRegistry $principals,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Every principal type this instance can resolve.
	 *
	 * @return JSONResponse The types, sorted so the list is stable.
	 *
	 * @NoAdminRequired
	 *
	 * @no-admin-idor-exempt Reads no object and takes no id: it answers with the
	 * set of principal types this instance's installed apps provide, which is
	 * the same for every caller.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
	 */
	#[NoAdminRequired]
	public function types(): JSONResponse {
		$types = $this->principals->types();

		return new JSONResponse(['results' => $types, 'total' => count($types)]);

	}//end types()
}//end class
