<?php

/**
 * The security controls an administrator expects to switch and to see.
 *
 * Four reads and two writes, all administrator-only:
 *
 *  - `GET /api/hardening/report` — every control, the value in force, where it
 *    comes from, the floor beside it, and whether the two agree.
 *  - `GET /api/hardening/floors` — just the floors, for a consumer that wants
 *    the contract without the current posture. dossiq's admin settings read
 *    this one to draw its link.
 *  - `PUT /api/hardening/controls` — change an administered control.
 *  - `PUT /api/hardening/floors` — declare a floor.
 *
 * 🔴 THE REFUSAL IS A 409, NOT A 400. A weakening that crosses the floor is a
 * well-formed request the instance will not carry out, and a client that reads
 * 400 will go looking for a typo in its own payload. The body names the
 * control, the floor and what was asked for, and nothing about the caller.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Hardening\HardeningFloorException;
use OCA\OpenRegister\Service\Hardening\HardeningPolicy;
use OCA\OpenRegister\Service\Hardening\HardeningReportService;
use OCA\OpenRegister\Service\Hardening\HardeningSettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Serves the hardening report and administers the controls behind it.
 *
 * Administrator-only: none of these methods carries `#[NoAdminRequired]`, so
 * Nextcloud's own middleware refuses a request from an ordinary account before
 * the method runs. The report names the security posture of a specific
 * gemeente's installation, which is nobody else's business.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @psalm-suppress UnusedClass Registered through appinfo/routes.php.
 */
class HardeningController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The incoming request.
	 * @param HardeningReportService $reportService Builds the report.
	 * @param HardeningSettingsService $settingsService Applies a change, or refuses it.
	 * @param HardeningPolicy $policy Reads the floors in force.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly HardeningReportService $reportService,
		private readonly HardeningSettingsService $settingsService,
		private readonly HardeningPolicy $policy,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * What is on, what is not, and what is below the line.
	 *
	 * @return JSONResponse The hardening report.
	 *
	 * @psalm-return JSONResponse<200, array<string, mixed>, array<never, never>>
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 *
	 * @contract tests/Unit/Controller/HardeningControllerTest.php
	 */
	#[NoCSRFRequired]
	public function report(): JSONResponse {
		return new JSONResponse(data: $this->reportService->report());

	}//end report()

	/**
	 * The floors this instance declared, and the baselines behind them.
	 *
	 * The shape a consuming app reads to draw its own link: `floors` is what is
	 * in force, `baselines` is the weakest any of them may be declared, and
	 * `comparators` says which direction is stronger. A consumer that reads only
	 * the floor and assumes "higher is stronger" gets the upload ceiling and the
	 * session lifetime exactly backwards.
	 *
	 * @return JSONResponse The floors.
	 *
	 * @psalm-return JSONResponse<200, array<string, mixed>, array<never, never>>
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 *
	 * @contract tests/Unit/Controller/HardeningControllerTest.php
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) The catalogue is a constant and its readers are
	 * pure functions over it. Injecting a stateless lookup would add a constructor argument
	 * to every caller and change nothing about what the lookup can answer.
	 */
	#[NoCSRFRequired]
	public function floors(): JSONResponse {
		$floors = $this->policy->floors();

		$baselines = [];
		$comparators = [];
		foreach (array_keys($floors) as $control) {
			$baselines[$control] = HardeningPolicy::baseline(control: $control);
			$comparators[$control] = HardeningPolicy::comparator(control: $control);
		}

		return new JSONResponse(
			data: [
				'floors' => $floors,
				'baselines' => $baselines,
				'comparators' => $comparators,
				'declared' => $this->policy->declaredFloors(),
			]
		);

	}//end floors()

	/**
	 * Change the administered controls.
	 *
	 * @return JSONResponse The controls now in force, or the refusal.
	 *
	 * @psalm-return JSONResponse<200|400|409, array<string, mixed>, array<never, never>>
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 *
	 * @contract tests/Unit/Controller/HardeningControllerTest.php
	 */
	#[NoCSRFRequired]
	public function updateControls(): JSONResponse {
		$body = $this->request->getParams();

		try {
			$controls = [];
			$requested = ($body['controls'] ?? []);
			if (is_array($requested) === true) {
				foreach ($requested as $control => $value) {
					$controls[$control] = $this->settingsService->setControl(
						control: (string)$control,
						value: (int)$value
					);
				}
			}

			$origins = null;
			if (array_key_exists('allowedOrigins', $body) === true && is_array($body['allowedOrigins']) === true) {
				$origins = $this->settingsService->setAllowedOrigins(origins: $body['allowedOrigins']);
			}

			$answer = ['controls' => $controls];
			if ($origins !== null) {
				$answer['allowedOrigins'] = $origins;
			}

			return new JSONResponse(data: $answer);
		} catch (HardeningFloorException $refusal) {
			return new JSONResponse(data: $refusal->toArray(), statusCode: Http::STATUS_CONFLICT);
		} catch (InvalidArgumentException $invalid) {
			return new JSONResponse(data: ['error' => $invalid->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}//end try

	}//end updateControls()

	/**
	 * Declare a floor for one or more controls.
	 *
	 * @return JSONResponse The floors now in force, or the refusal.
	 *
	 * @psalm-return JSONResponse<200|400|409, array<string, mixed>, array<never, never>>
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 *
	 * @contract tests/Unit/Controller/HardeningControllerTest.php
	 */
	#[NoCSRFRequired]
	public function updateFloors(): JSONResponse {
		$body = $this->request->getParams();
		$requested = ($body['floors'] ?? []);

		if (is_array($requested) === false) {
			return new JSONResponse(
				data: ['error' => 'Send floors as a map of control to number.'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$applied = [];
			foreach ($requested as $control => $floor) {
				$applied[$control] = $this->settingsService->setFloor(
					control: (string)$control,
					floor: (int)$floor
				);
			}

			return new JSONResponse(data: ['floors' => $applied]);
		} catch (HardeningFloorException $refusal) {
			return new JSONResponse(data: $refusal->toArray(), statusCode: Http::STATUS_CONFLICT);
		} catch (InvalidArgumentException $invalid) {
			return new JSONResponse(data: ['error' => $invalid->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}//end try

	}//end updateFloors()
}//end class
