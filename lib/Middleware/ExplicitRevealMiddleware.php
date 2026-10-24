<?php

/**
 * Opens explicit-reveal masking for a request to the objects API.
 *
 * An explicit-reveal property (a BSN) is answered as its mask on the objects
 * API, which is where a page reads records, and as its value to an app's own
 * server code. This middleware draws that line: it marks a request to
 * ObjectsController, and names the properties its `_reveal` parameter asks
 * to see.
 *
 * @category Middleware
 * @package  OCA\OpenRegister\Middleware
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
 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Middleware;

use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Service\Rbac\ExplicitReveal;
use OCP\AppFramework\Middleware;
use OCP\IRequest;

/**
 * Marks an objects API request for explicit-reveal masking.
 *
 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
 */
class ExplicitRevealMiddleware extends Middleware {

	/**
	 * Constructor.
	 *
	 * @param ExplicitReveal $reveal  The request's masking state.
	 * @param IRequest       $request The request.
	 */
	public function __construct(
		private readonly ExplicitReveal $reveal,
		private readonly IRequest $request,
	) {
	}//end __construct()

	/**
	 * Open masking before an objects API controller runs.
	 *
	 * @param object $controller The controller.
	 * @param string $methodName The method.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is the Middleware contract.
	 *
	 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
	 */
	public function beforeController($controller, $methodName): void {
		if ($controller instanceof ObjectsController === false) {
			return;
		}

		$this->reveal->open(reveal: (string)($this->request->getParam('_reveal') ?? ''));
	}//end beforeController()
}//end class
