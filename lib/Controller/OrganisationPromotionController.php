<?php

/**
 * Promotes an organisation's configuration into another organisation.
 *
 * Admin only: the action carries no NoAdminRequired attribute, so Nextcloud
 * refuses every other caller before it runs.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/configuration-as-a-deployment/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\ConfigurationDeployment\DeploymentRefusedException;
use OCA\OpenRegister\Service\ConfigurationDeployment\PromotionServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * `POST /api/organisations/{uuid}/promote`.
 *
 * @spec openspec/changes/configuration-as-a-deployment/tasks.md
 */
class OrganisationPromotionController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                    $appName   The app name.
	 * @param IRequest                  $request   The request.
	 * @param PromotionServiceInterface $promotion The promotion.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PromotionServiceInterface $promotion,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Promote the organisation's configuration into `targetOrganisation` as a draft set.
	 *
	 * @param string $uuid The source organisation.
	 *
	 * @return JSONResponse 201 with the draft set, or the refusal with its status.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/tasks.md
	 */
	public function promote(string $uuid): JSONResponse {
		$target = $this->request->getParam(key: 'targetOrganisation');
		if (is_string($target) === false || trim($target) === '') {
			return new JSONResponse(
				data: ['error' => DeploymentRefusedException::REASON_INVALID, 'message' => 'targetOrganisation is required'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$result = $this->promotion->promote(sourceUuid: $uuid, targetUuid: trim($target));
		} catch (DeploymentRefusedException $exception) {
			return new JSONResponse(data: $exception->toResponseBody(), statusCode: $exception->getStatusCode());
		}

		return new JSONResponse(data: $result, statusCode: Http::STATUS_CREATED);
	}//end promote()
}//end class
