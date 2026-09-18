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
use OCA\OpenRegister\Service\Hardening\ElevationRequiredException;
use OCA\OpenRegister\Service\Hardening\ElevationService;
use OCA\OpenRegister\Service\Hardening\HardeningFloorException;
use OCA\OpenRegister\Service\Hardening\HardeningPolicy;
use OCA\OpenRegister\Service\Hardening\HardeningReportService;
use OCA\OpenRegister\Service\Hardening\HardeningSettingsService;
use OCA\OpenRegister\Service\Hardening\StatementService;
use OCA\OpenRegister\Service\Hardening\ThrottledSurfaces;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

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
	 * @param StatementService $statements Publishes the statement, and records an acceptance.
	 * @param ElevationService $elevation Guards the administration writes with a fresh sign-in.
	 * @param IUserSession $userSession Names the account, which is never read from the request.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly HardeningReportService $reportService,
		private readonly HardeningSettingsService $settingsService,
		private readonly HardeningPolicy $policy,
		private readonly StatementService $statements,
		private readonly ElevationService $elevation,
		private readonly IUserSession $userSession,
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
			// A stolen session is not a confirmed password, and this write
			// weakens the instance. REQ-IHC-002.
			$this->elevation->requireElevated();
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
		} catch (ElevationRequiredException $stale) {
			return new JSONResponse(data: $stale->toArray(), statusCode: Http::STATUS_FORBIDDEN);
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
			// Declaring a floor is an administration write too: a floor moved
			// down is what lets the next control be weakened. REQ-IHC-002.
			$this->elevation->requireElevated();

			$applied = [];
			foreach ($requested as $control => $floor) {
				$applied[$control] = $this->settingsService->setFloor(
					control: (string)$control,
					floor: (int)$floor
				);
			}

			return new JSONResponse(data: ['floors' => $applied]);
		} catch (ElevationRequiredException $stale) {
			return new JSONResponse(data: $stale->toArray(), statusCode: Http::STATUS_FORBIDDEN);
		} catch (HardeningFloorException $refusal) {
			return new JSONResponse(data: $refusal->toArray(), statusCode: Http::STATUS_CONFLICT);
		} catch (InvalidArgumentException $invalid) {
			return new JSONResponse(data: ['error' => $invalid->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}//end try

	}//end updateFloors()
	/**
	 * Start an elevated administration period by confirming the password.
	 *
	 * Administrator-only, like everything else here, and throttled: a correct
	 * guess on this one surface buys the right to weaken every control on the
	 * report. The account is the session's; the request never names one.
	 *
	 * @return JSONResponse The period now running, or the refusal.
	 *
	 * @psalm-return JSONResponse<200|401, array<string, mixed>, array<never, never>>
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-administration-requires-a-fresh-expiring-authentication-req-ihc-002
	 *
	 * @contract tests/Unit/Controller/HardeningControllerTest.php
	 */
	#[NoCSRFRequired]
	#[BruteForceProtection(action: ThrottledSurfaces::ELEVATION)]
	public function elevate(): JSONResponse {
		$password = (string)($this->request->getParam('password') ?? '');

		if ($this->elevation->elevate(password: $password) === false) {
			$refused = new JSONResponse(
				data: ['error' => 'That password was not confirmed.', 'elevationRequired' => true],
				statusCode: Http::STATUS_UNAUTHORIZED
			);
			$refused->throttle(['action' => ThrottledSurfaces::ELEVATION]);

			return $refused;
		}

		return new JSONResponse(
			data: [
				'elevated' => true,
				'periodSeconds' => $this->elevation->periodSeconds(),
				'remainingSeconds' => $this->elevation->remainingSeconds(),
			]
		);

	}//end elevate()

	/**
	 * The statement in force, and whether this account still has to accept it.
	 *
	 * The one read here an ordinary account may make, because it is the one
	 * thing it is asked to do. It answers about the CALLER and nobody else: the
	 * account comes from the session, so there is no id to tamper with and no
	 * other person's acceptance to read.
	 *
	 * @return JSONResponse The statement, or an empty answer when none is published.
	 *
	 * @psalm-return JSONResponse<200|401, array<string, mixed>, array<never, never>>
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-published-statement-is-accepted-before-use-per-version-req-ihc-001
	 *
	 * @contract tests/Unit/Controller/HardeningControllerTest.php
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function statement(): JSONResponse {
		$uid = ($this->userSession->getUser()?->getUID() ?? '');
		if ($uid === '') {
			return new JSONResponse(
				data: ['error' => 'A statement is shown to an account.'],
				statusCode: Http::STATUS_UNAUTHORIZED
			);
		}

		return new JSONResponse(
			data: [
				'statement' => $this->statements->published(),
				'acceptance' => $this->statements->acceptanceOf(userId: $uid),
				'needsAcceptance' => $this->statements->needsAcceptance(userId: $uid),
			]
		);

	}//end statement()

	/**
	 * Record that the signed-in account accepted the version in force.
	 *
	 * @return JSONResponse The acceptance as recorded, or the refusal.
	 *
	 * @psalm-return JSONResponse<200|400|401, array<string, mixed>, array<never, never>>
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-published-statement-is-accepted-before-use-per-version-req-ihc-001
	 *
	 * @contract tests/Unit/Controller/HardeningControllerTest.php
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function acceptStatement(): JSONResponse {
		$uid = ($this->userSession->getUser()?->getUID() ?? '');
		if ($uid === '') {
			return new JSONResponse(
				data: ['error' => 'An acceptance is recorded against an account.'],
				statusCode: Http::STATUS_UNAUTHORIZED
			);
		}

		try {
			// The account is the session's and the version is checked against
			// the one in force, so a client cannot accept on behalf of somebody
			// else, nor close the gate on a text nobody was shown.
			return new JSONResponse(
				data: $this->statements->accept(
					userId: $uid,
					version: (string)($this->request->getParam('version') ?? '')
				)
			);
		} catch (InvalidArgumentException $invalid) {
			return new JSONResponse(data: ['error' => $invalid->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}

	}//end acceptStatement()

	/**
	 * Publish a statement, or a new version of one.
	 *
	 * @return JSONResponse The statement now in force, or the refusal.
	 *
	 * @psalm-return JSONResponse<200|400|403, array<string, mixed>, array<never, never>>
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-published-statement-is-accepted-before-use-per-version-req-ihc-001
	 *
	 * @contract tests/Unit/Controller/HardeningControllerTest.php
	 */
	#[NoCSRFRequired]
	public function publishStatement(): JSONResponse {
		try {
			$this->elevation->requireElevated();

			return new JSONResponse(
				data: $this->statements->publish(
					version: (string)($this->request->getParam('version') ?? ''),
					body: (string)($this->request->getParam('body') ?? ''),
					title: (string)($this->request->getParam('title') ?? ''),
					userId: ($this->userSession->getUser()?->getUID() ?? '')
				)
			);
		} catch (ElevationRequiredException $stale) {
			return new JSONResponse(data: $stale->toArray(), statusCode: Http::STATUS_FORBIDDEN);
		} catch (InvalidArgumentException $invalid) {
			return new JSONResponse(data: ['error' => $invalid->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}

	}//end publishStatement()

	/**
	 * Withdraw the statement, so nothing is asked.
	 *
	 * @return JSONResponse The empty statement, or the refusal.
	 *
	 * @psalm-return JSONResponse<200|403, array<string, mixed>, array<never, never>>
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-published-statement-is-accepted-before-use-per-version-req-ihc-001
	 *
	 * @contract tests/Unit/Controller/HardeningControllerTest.php
	 */
	#[NoCSRFRequired]
	public function withdrawStatement(): JSONResponse {
		try {
			$this->elevation->requireElevated();
			$this->statements->withdraw();

			return new JSONResponse(data: ['statement' => null]);
		} catch (ElevationRequiredException $stale) {
			return new JSONResponse(data: $stale->toArray(), statusCode: Http::STATUS_FORBIDDEN);
		}

	}//end withdrawStatement()
}//end class