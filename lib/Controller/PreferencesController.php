<?php

/**
 * OpenRegister PreferencesController.
 *
 * Generic per-user key/value preferences, backed by Nextcloud IConfig
 * user values. Used by shared @conduction/nextcloud-vue widgets (e.g.
 * CnSupportDialog's "seen" flag) that need to persist a small per-user
 * UI flag cross-device without a bespoke endpoint per feature.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Support\PreferencesControllerServices;
use OCA\OpenRegister\Controller\Support\PreferencesUserValues;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Per-user preferences controller.
 */
class PreferencesController extends Controller {
	/**
	 * The preference values of this app.
	 *
	 * @var PreferencesUserValues
	 */
	private readonly PreferencesUserValues $values;

	/**
	 * The controller's collaborators.
	 *
	 * @var PreferencesControllerServices
	 */
	private readonly PreferencesControllerServices $services;

	/**
	 * Constructor.
	 *
	 * @param IRequest                      $request  The request.
	 * @param PreferencesControllerServices $services The controller's collaborators.
	 */
	public function __construct(
		IRequest $request,
		PreferencesControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->services = $services;
		$this->values   = new PreferencesUserValues(config: $services->config, appName: Application::APP_ID);
	}//end __construct()

	/**
	 * Read a per-user preference value.
	 *
	 * @param string $key The preference key (kebab/alphanumeric).
	 *
	 * @return JSONResponse `{value: string|null}`.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/apphost-boilerplate/spec.md#requirement-generic-preferences-controller
	 */
	public function getPreference(string $key): JSONResponse {
		$slot = $this->values->slotFor(user: $this->services->session->userSession->getUser(), key: $key);
		if ($slot instanceof JSONResponse) {
			return $slot;
		}

		return new JSONResponse(data: ['value' => $this->values->read(slot: $slot)]);
	}//end getPreference()

	/**
	 * Write a per-user preference value. An empty value clears it.
	 *
	 * @param string $key The preference key (kebab/alphanumeric).
	 * @param string $value The value to store (empty string clears it).
	 *
	 * @return JSONResponse `{value: string|null}`.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/apphost-boilerplate/spec.md#requirement-generic-preferences-controller
	 */
	public function setPreference(string $key, string $value = ''): JSONResponse {
		$slot = $this->values->slotFor(user: $this->services->session->userSession->getUser(), key: $key);
		if ($slot instanceof JSONResponse) {
			return $slot;
		}

		return new JSONResponse(data: ['value' => $this->values->write(slot: $slot, value: $value)]);
	}//end setPreference()
}//end class
