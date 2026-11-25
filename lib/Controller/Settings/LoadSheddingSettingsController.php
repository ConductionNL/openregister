<?php

/**
 * OpenRegister Load Shedding Settings Controller
 *
 * The "Load shedding" section of the admin settings: the breaker thresholds,
 * the database-pressure thresholds and the pressure switch.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller\Settings
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Settings;

use InvalidArgumentException;
use OCA\OpenRegister\Kernel\Contracts\AppIdentity;
use OCA\OpenRegister\Service\Resilience\LoadSheddingSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Throwable;

/**
 * Reads and writes the load shedding thresholds.
 *
 * Administrator-only: neither method declares `#[NoAdminRequired]`, so
 * Nextcloud refuses anyone else with 403 before this controller is built.
 * The write keeps CSRF required.
 *
 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
 *
 * @psalm-suppress UnusedClass
 */
class LoadSheddingSettingsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest             $request  The request.
	 * @param LoadSheddingSettings $settings The thresholds.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly LoadSheddingSettings $settings,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * GET /api/settings/load-shedding: the values in force, their defaults and bounds.
	 *
	 * @return JSONResponse The form.
	 *
	 * @auth admin-only declares no NoAdminRequired attribute, so the middleware
	 *       refuses a non-administrator before this controller is built.
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	#[NoCSRFRequired]
	public function index(): JSONResponse {
		return new JSONResponse(data: $this->settings->read());
	}//end index()

	/**
	 * PUT /api/settings/load-shedding: store the fields sent.
	 *
	 * A field out of bounds is 400 and stores nothing; a store that fails is 500.
	 *
	 * @return JSONResponse The form after storing.
	 *
	 * @auth admin-only declares no NoAdminRequired attribute, so the middleware
	 *       refuses a non-administrator before this controller is built, and
	 *       CSRF stays required on the write.
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	public function update(): JSONResponse {
		$input = $this->request->getParams();
		if (is_array($input) === false) {
			$input = [];
		}

		try {
			return new JSONResponse(data: $this->settings->write(input: $input));
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		} catch (Throwable $e) {
			return new JSONResponse(data: ['error' => 'The settings could not be stored.'], statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end update()
}//end class
