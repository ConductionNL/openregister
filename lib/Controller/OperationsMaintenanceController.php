<?php

/**
 * Maintenance mode, the support bundle and the instance's own facts.
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
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/operations-console/spec.md#requirement-maintenance-mode-closes-the-instance-without-locking-administration-out-req-aoc-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Service\Operations\MaintenanceModeServiceInterface;
use OCA\OpenRegister\Service\Operations\SupportBundleServiceInterface;
use OCA\OpenRegister\Controller\Support\OperationsActingRequest;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCA\OpenRegister\Controller\Support\OperationsMaintenanceControllerServices;

/**
 * Closing the instance, and saying what it is made of.
 *
 * Kept apart from the console's read panes because entering and leaving
 * maintenance are the two writes on the whole operations surface that change
 * what every OTHER caller can do. They declare no `#[NoAdminRequired]`, so
 * the middleware refuses a non-administrator before this controller is even
 * built, and CSRF stays required on them.
 *
 * @spec openspec/specs/operations-console/spec.md#requirement-maintenance-mode-closes-the-instance-without-locking-administration-out-req-aoc-006
 */
class OperationsMaintenanceController extends Controller {

	/**
	 * The acting administrator and the parameter readers.
	 *
	 * @var OperationsActingRequest
	 */
	private readonly OperationsActingRequest $acting;

	/**
	 * Maintenance mode.
	 *
	 * @var MaintenanceModeServiceInterface
	 */
	private readonly MaintenanceModeServiceInterface $maintenance;

	/**
	 * The support bundle and the instance facts.
	 *
	 * @var SupportBundleServiceInterface
	 */
	private readonly SupportBundleServiceInterface $bundle;

	/**
	 * Constructor.
	 *
	 * @param IRequest               $request     HTTP request.
	 * @param OperationsMaintenanceControllerServices $services The services, bundled.
	 */
	public function __construct(
		IRequest $request,
		OperationsMaintenanceControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->maintenance = $services->maintenance;
		$this->bundle = $services->bundle;
		$this->acting = new OperationsActingRequest(request: $request, userSession: $services->userSession);
	}//end __construct()

	/**
	 * Maintenance mode: read it, enter it or leave it.
	 *
	 * @return JSONResponse The mode in force.
	 *
	 * @auth admin-only entering or leaving maintenance declares no NoAdminRequired attribute,
	 *       so the middleware refuses a non-administrator before this
	 *       controller is built, and CSRF stays required on the write.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-maintenance-mode-closes-the-instance-without-locking-administration-out-req-aoc-006
	 */
	public function maintenance(): JSONResponse {
		$method = $this->request->getMethod();

		if ($method === 'GET') {
			return new JSONResponse(data: $this->maintenance->state());
		}

		if ($method === 'DELETE') {
			return new JSONResponse(data: $this->maintenance->leave(actor: $this->acting->actor()));
		}

		return new JSONResponse(
			data: $this->maintenance->enter(
				actor: $this->acting->actor(),
				message: $this->acting->stringParam(name: 'message')
			)
		);
	}//end maintenance()

	/**
	 * The support bundle, redacted where it was built.
	 *
	 * @return JSONResponse The bundle.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-a-support-bundle-and-the-instances-own-facts-are-readable-req-aoc-007
	 */
	#[NoCSRFRequired]
	public function supportBundle(): JSONResponse {
		return new JSONResponse(data: $this->bundle->build());
	}//end supportBundle()

	/**
	 * The instance facts: version, build, dependencies and licence.
	 *
	 * @return JSONResponse The facts.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-a-support-bundle-and-the-instances-own-facts-are-readable-req-aoc-007
	 */
	#[NoCSRFRequired]
	public function facts(): JSONResponse {
		return new JSONResponse(data: $this->bundle->facts());
	}//end facts()


}//end class
