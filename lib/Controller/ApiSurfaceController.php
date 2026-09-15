<?php

/**
 * The published contract: what this instance serves, and what each served
 * version looks like.
 *
 * Three reads, and every one of them is a read an integrator currently makes
 * by guessing:
 *
 *  - `GET /api/capabilities` — the versions, the limits, the links. Public,
 *    because a client that must authenticate to learn the upload limit will
 *    not learn the upload limit. With a session it also carries the
 *    operational switches (design D-5).
 *  - `GET /api/versions` — just the version list, for a consumer that polls
 *    for a status change and does not want the rest.
 *  - `GET /api/versions/{version}/oas` — that version's own OpenAPI
 *    description, narrowed to the paths it declares and stamped with its
 *    status and end date.
 *
 * 🔴 THE VERSION IN THE PATH HERE NAMES THE DOCUMENT, NOT THE CONTRACT THIS
 * CALL SPEAKS. Asking for the description of version 1 is not the same as
 * speaking version 1, and a caller may legitimately read the description of a
 * version it has not moved to yet. The middleware still negotiates this call's
 * own version from the header as it does for every other route.
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
 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\ApiVersion\ApiCapabilitiesService;
use OCA\OpenRegister\Service\ApiVersion\ApiContractService;
use OCA\OpenRegister\Service\ApiVersion\ApiVersion;
use OCA\OpenRegister\Service\ApiVersion\ApiVersionCatalogue;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Publishes the instance's capabilities, its version list and each version's
 * own description.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @psalm-suppress UnusedClass Registered through appinfo/routes.php.
 */
class ApiSurfaceController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The incoming request.
	 * @param ApiCapabilitiesService $capabilities Builds the capabilities answer.
	 * @param ApiVersionCatalogue $catalogue The declared contract versions.
	 * @param ApiContractService $contracts Builds one description per version.
	 * @param IUserSession $userSession Decides which half of the answer is returned.
	 * @param LoggerInterface $logger Records a description that could not be generated.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ApiCapabilitiesService $capabilities,
		private readonly ApiVersionCatalogue $catalogue,
		private readonly ApiContractService $contracts,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * What this instance supports and what it will not let you do.
	 *
	 * Anonymous callers get the versions and the limits. A caller with a
	 * session also gets the operational switches, which describe the posture
	 * of a specific installation and are nobody else's business.
	 *
	 * @return JSONResponse The capabilities.
	 *
	 * @psalm-return JSONResponse<200, array<string, mixed>, array<never, never>>
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md#requirement-the-instance-publishes-its-capabilities-and-its-limits-req-avs-002
	 *
	 * @contract tests/Unit/Controller/ApiSurfaceControllerTest.php
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[UserRateLimit(limit: 600, period: 60)]
	public function capabilities(): JSONResponse {
		if ($this->userSession->isLoggedIn() === true) {
			return new JSONResponse(data: $this->capabilities->sessionCapabilities());
		}

		return new JSONResponse(data: $this->capabilities->publicCapabilities());

	}//end capabilities()

	/**
	 * The declared contract versions and their statuses.
	 *
	 * Every declared version, withdrawn ones included: a consumer that still
	 * calls a withdrawn version needs to see it named here rather than find
	 * it missing and conclude the instance is broken.
	 *
	 * @return JSONResponse The version list.
	 *
	 * @psalm-return JSONResponse<200, array<string, mixed>, array<never, never>>
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/openapi-generation/spec.md#requirement-two-contract-versions-are-served-at-once-with-a-declared-lifecycle-req-avs-005
	 *
	 * @contract tests/Unit/Controller/ApiSurfaceControllerTest.php
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[UserRateLimit(limit: 600, period: 60)]
	public function versions(): JSONResponse {
		return new JSONResponse(
			data: [
				'currentVersion' => $this->catalogue->current()->id,
				'versions' => array_values(array_map(
					static fn (ApiVersion $version): array => $version->jsonSerialize(),
					$this->catalogue->all()
				)),
			]
		);

	}//end versions()

	/**
	 * One version's own OpenAPI description.
	 *
	 * An unknown version is a 404 here rather than the middleware's 400,
	 * because this path names a document: asking for a description that does
	 * not exist is a missing resource, while speaking a contract that does not
	 * exist is a bad request. A withdrawn version has no description at all,
	 * and says so with 410 and its successor.
	 *
	 * @param string $version The version identifier.
	 *
	 * @return JSONResponse The description, or the refusal.
	 *
	 * @psalm-return JSONResponse<200|404|410|500, array<string, mixed>, array<never, never>>
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/openapi-generation/spec.md#requirement-two-contract-versions-are-served-at-once-with-a-declared-lifecycle-req-avs-005
	 *
	 * @contract tests/Unit/Controller/ApiSurfaceControllerTest.php
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[UserRateLimit(limit: 240, period: 60)]
	public function contract(string $version): JSONResponse {
		$declared = $this->catalogue->get(identifier: $version);
		if ($declared === null) {
			return new JSONResponse(
				data: [
					'error' => 'No API version "' . $version . '" is declared on this instance.',
					'servedVersions' => array_keys($this->catalogue->served()),
				],
				statusCode: Http::STATUS_NOT_FOUND,
			);
		}

		if ($declared->isServed() === false) {
			return new JSONResponse(
				data: [
					'error' => 'API version ' . $declared->id . ' has been withdrawn. Use version ' . (string)$declared->successor . '.',
					'successorVersion' => $declared->successor,
					'servedVersions' => array_keys($this->catalogue->served()),
				],
				statusCode: Http::STATUS_GONE,
			);
		}

		try {
			$document = $this->contracts->documentFor(
				version: $declared,
				registerId: $this->registerFilter(),
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'OpenRegister: could not generate the description for API version ' . $declared->id . '.',
				['exception' => $e]
			);

			return new JSONResponse(
				data: ['error' => 'The description for API version ' . $declared->id . ' could not be generated.'],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}

		return new JSONResponse(data: $document);

	}//end contract()

	/**
	 * The optional `register` query parameter, narrowing the description.
	 *
	 * @return string|null The register identifier, or null for all registers.
	 */
	private function registerFilter(): ?string {
		$value = trim((string)$this->request->getParam('register', ''));
		if ($value === '') {
			return null;
		}

		return $value;

	}//end registerFilter()
}//end class
