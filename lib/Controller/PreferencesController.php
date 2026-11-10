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

use OCA\OpenRegister\Controller\Support\PreferencesUserValues;
use OCA\OpenRegister\Kernel\Contracts\AppIdentity;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;

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
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param IConfig $config The Nextcloud config (user values).
	 * @param IUserSession $userSession The user session.
	 */
	public function __construct(
		IRequest $request,
		IConfig $config,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
		$this->values = new PreferencesUserValues(config: $config, appName: AppIdentity::APP_ID);
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
		return $this->values->forUser(
			user: $this->userSession->getUser(),
			key: $key,
			action: fn (string $userId, string $storedKey): JSONResponse => new JSONResponse(
				data: ['value' => $this->values->read(userId: $userId, storedKey: $storedKey)]
			)
		);
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
		return $this->values->forUser(
			user: $this->userSession->getUser(),
			key: $key,
			action: fn (string $userId, string $storedKey): JSONResponse => new JSONResponse(
				data: ['value' => $this->values->write(userId: $userId, storedKey: $storedKey, value: $value)]
			)
		);
	}//end setPreference()
}//end class
