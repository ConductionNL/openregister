<?php

/**
 * Class OasController
 *
 * Controller for generating OpenAPI Specifications (OAS) for registers in the OpenRegister app.
 * Provides endpoints to generate OAS for a single register or all registers.
 *
 * Supports `?validate=true` (attaches an `x-validation-summary` extension to the
 * response) and `?strict=true` (returns HTTP 422 with the validation report when
 * any validation error is detected, instead of auto-correcting).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/oas-generation/spec.md
 * @spec openspec/specs/oas-generation/spec.md
 * @spec openspec/specs/oas-generation/spec.md
 */

namespace OCA\OpenRegister\Controller;

use Exception;
use OCA\OpenRegister\Exception\OasValidationException;
use OCA\OpenRegister\Service\Oas\OasETagComputerInterface;
use OCA\OpenRegister\Service\OasServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * OasController class.
 *
 * Controller for generating OpenAPI Specifications (OAS) for registers.
 *
 * @psalm-suppress UnusedClass - This controller is registered via routes.php and used by Nextcloud's routing system
 */
class OasController extends Controller {

	/**
	 * OAS service instance
	 *
	 * @var OasServiceInterface
	 */
	private readonly OasServiceInterface $oasService;

	/**
	 * OasController constructor
	 *
	 * @param string $appName Application name
	 * @param IRequest $request Request object
	 * @param OasServiceInterface $oasService OAS service instance
	 * @param ?OasETagComputerInterface $etagComputer Optional ETag computer for If-None-Match short-circuit
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		OasServiceInterface $oasService,
		private readonly ?OasETagComputerInterface $etagComputer = null,
	) {
		parent::__construct(appName: $appName, request: $request);
		$this->oasService = $oasService;
	}//end __construct()

	/**
	 * Generate OAS for all registers
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @PublicPage
	 *
	 * @return JSONResponse
	 *
	 * @psalm-return JSONResponse<200|422|500, array<string, mixed>, array<never, never>>
	 *
	 * @spec openspec/specs/oas-generation/spec.md
	 */
	#[AnonRateLimit(limit: 30, period: 60)]
	public function generateAll(): JSONResponse {
		return $this->generateInternal(registerId: null);
	}//end generateAll()

	/**
	 * Generate OAS for a specific register
	 *
	 * @param string $id The register slug or identifier.
	 *
	 * @return JSONResponse OAS specification JSON response.
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @PublicPage
	 *
	 * @psalm-return JSONResponse<200|422|500, array<string, mixed>, array<never, never>>
	 *
	 * @spec openspec/specs/oas-generation/spec.md
	 */
	#[AnonRateLimit(limit: 30, period: 60)]
	public function generate(string $id): JSONResponse {
		return $this->generateInternal(registerId: $id);
	}//end generate()

	/**
	 * Shared generation path for both single-register and all-registers routes.
	 *
	 * Honours the `?strict=true` and `?validate=true` query parameters from
	 * `$this->request`.
	 *
	 * @param string|null $registerId Register slug/identifier or null for all registers.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/oas-generation/spec.md
	 */
	private function generateInternal(?string $registerId): JSONResponse {
		$strict = $this->boolQueryParam(name: 'strict');
		$emitSummary = $this->boolQueryParam(name: 'validate');

		try {
			$oasData = $this->oasService->createOas($registerId, $strict);
		} catch (OasValidationException $e) {
			return $this->strictRejection(e: $e);
		} catch (Exception $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
		}

		if ($emitSummary === true) {
			return $this->withValidationSummary(oasData: $oasData);
		}

		return $this->withETag(oasData: $oasData);
	}//end generateInternal()

	/**
	 * Strict mode rejected the document: return the report so the caller can
	 * see exactly which checks failed.
	 *
	 * @param OasValidationException $e The strict-mode rejection.
	 *
	 * @return JSONResponse The 422 with the validation summary.
	 */
	private function strictRejection(OasValidationException $e): JSONResponse {
		return new JSONResponse(
			data: [
				'error' => $e->getMessage(),
				'summary' => $e->getReport()->toSummary(),
			],
			statusCode: 422,
		);
	}//end strictRejection()

	/**
	 * The spec with the last validation summary attached, never cached by ETag
	 * so the summary is always fresh.
	 *
	 * @param array<string, mixed> $oasData The generated spec.
	 *
	 * @return JSONResponse The spec with `x-validation-summary`.
	 */
	private function withValidationSummary(array $oasData): JSONResponse {
		$oasData['x-validation-summary'] = $this->oasService->getLastValidationReport()->toSummary();

		return new JSONResponse(data: $oasData);
	}//end withValidationSummary()

	/**
	 * ETag short-circuit (RFC 7232): when the client sends an If-None-Match
	 * that matches the current spec hash, return 304 without the (large) body.
	 *
	 * @param array<string, mixed> $oasData The generated spec.
	 *
	 * @return JSONResponse The spec, or a 304, with an ETag when one can be computed.
	 */
	private function withETag(array $oasData): JSONResponse {
		if ($this->etagComputer === null) {
			return new JSONResponse(data: $oasData);
		}

		$etag = $this->etagComputer->computeETag(oas: $oasData);
		$response = new JSONResponse(data: $oasData);
		if ($this->ifNoneMatchHits(etag: $etag) === true) {
			$response = new JSONResponse(data: null, statusCode: 304);
		}

		$response->addHeader('ETag', $etag);
		return $response;
	}//end withETag()

	/**
	 * Whether the request's If-None-Match matches the current ETag.
	 *
	 * @param string $etag The current ETag.
	 *
	 * @return bool True when the client already holds this spec.
	 */
	private function ifNoneMatchHits(string $etag): bool {
		$ifNoneMatch = (string)$this->request->getHeader('IF_NONE_MATCH');

		return $ifNoneMatch !== ''
			&& $this->etagComputer?->matches(ifNoneMatch: $ifNoneMatch, currentETag: $etag) === true;
	}//end ifNoneMatchHits()

	/**
	 * Read a boolean query parameter. Accepts "true", "1", "yes" (case-insensitive)
	 * as truthy; everything else is false. Missing parameters are false.
	 *
	 * @param string $name Query parameter name.
	 *
	 * @return bool
	 */
	private function boolQueryParam(string $name): bool {
		$raw = $this->request->getParam($name);
		if ($raw === null || $raw === '') {
			return false;
		}

		$normalized = strtolower((string)$raw);
		return in_array($normalized, ['true', '1', 'yes', 'on'], true);
	}//end boolQueryParam()
}//end class
