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
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-maintenance-mode-closes-the-instance-without-locking-administration-out-req-aoc-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\Operations\MaintenanceModeService;
use OCA\OpenRegister\Service\Operations\SupportBundleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Closing the instance, and saying what it is made of.
 *
 * Kept apart from the console's read panes because entering and leaving
 * maintenance are the two writes on the whole operations surface that change
 * what every OTHER caller can do. They declare no `#[NoAdminRequired]`, so
 * the middleware refuses a non-administrator before this controller is even
 * built, and CSRF stays required on them.
 *
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-maintenance-mode-closes-the-instance-without-locking-administration-out-req-aoc-006
 */
class OperationsMaintenanceController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                 $appName     Application name.
	 * @param IRequest               $request     HTTP request.
	 * @param MaintenanceModeService $maintenance Maintenance mode.
	 * @param SupportBundleService   $bundle      The support bundle and the instance facts.
	 * @param IUserSession           $userSession Names the administrator acting.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly MaintenanceModeService $maintenance,
		private readonly SupportBundleService $bundle,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: $appName, request: $request);
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
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-maintenance-mode-closes-the-instance-without-locking-administration-out-req-aoc-006
	 */
	public function maintenance(): JSONResponse {
		$method = $this->request->getMethod();

		if ($method === 'GET') {
			return new JSONResponse(data: $this->maintenance->state());
		}

		if ($method === 'DELETE') {
			return new JSONResponse(data: $this->maintenance->leave(actor: $this->actor()));
		}

		return new JSONResponse(
			data: $this->maintenance->enter(
				actor: $this->actor(),
				message: $this->stringParam(name: 'message')
			)
		);
	}//end maintenance()

	/**
	 * The support bundle, redacted where it was built.
	 *
	 * @return JSONResponse The bundle.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-support-bundle-and-the-instances-own-facts-are-readable-req-aoc-007
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
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-support-bundle-and-the-instances-own-facts-are-readable-req-aoc-007
	 */
	#[NoCSRFRequired]
	public function facts(): JSONResponse {
		return new JSONResponse(data: $this->bundle->facts());
	}//end facts()

	/**
	 * The uid acting.
	 *
	 * Every write here is an administrator's act and is recorded as theirs, so
	 * an unresolvable session is an empty string the services refuse rather
	 * than a system identity the record would blame.
	 *
	 * @return string The uid, or the empty string.
	 */
	private function actor(): string {
		return (string)($this->userSession->getUser()?->getUID() ?? '');
	}//end actor()

	/**
	 * Read a non-empty string request parameter.
	 *
	 * @param string $name The parameter name.
	 *
	 * @return string|null The value, or null when absent or empty.
	 */
	private function stringParam(string $name): ?string {
		$value = $this->request->getParam($name);

		if (is_string($value) === false || $value === '') {
			return null;
		}

		return $value;
	}//end stringParam()
}//end class
