<?php

/**
 * ConfigurationSeedController — stage the working defaults as a set to review.
 *
 * ADMINISTRATOR ONLY, by the framework: the route carries no `@NoAdminRequired`,
 * so Nextcloud's middleware refuses everybody else before the method runs.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationSeedService;
use OCA\OpenRegister\Service\ConfigurationDeployment\DeploymentRefusedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The seed action of the configuration lifecycle.
 *
 * @spec openspec/changes/configuration-as-a-deployment/specs/settings-management/spec.md
 */
class ConfigurationSeedController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                   $appName The app name.
	 * @param IRequest                 $request The request.
	 * @param ConfigurationSeedService $seeds   The working defaults, as drafts.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ConfigurationSeedService $seeds,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * POST /api/configuration/seed — stage the working defaults for review.
	 *
	 * Body: `name` (optional). Seeding writes drafts, never live values. A key
	 * this instance already sets is skipped and named, because an administrator
	 * asking for the gaps to be filled is not asking for their own choices to be
	 * replaced.
	 *
	 * @return JSONResponse The set, what was staged, and what was skipped.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/settings-management/spec.md
	 */
	public function create(): JSONResponse {
		$name = trim((string)($this->request->getParam(key: 'name') ?? ''));
		if ($name === '') {
			$name = null;
		}

		try {
			$seeded = $this->seeds->seed(name: $name);
		} catch (DeploymentRefusedException $exception) {
			return new JSONResponse(
				data: $exception->toResponseBody(),
				statusCode: $exception->getStatusCode()
			);
		}

		return new JSONResponse(data: $seeded, statusCode: Http::STATUS_CREATED);

	}//end create()
}//end class
