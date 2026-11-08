<?php

/**
 * AnonymiserRequestInterface: the backend state and the ExApp call entity recognition uses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Anonymisation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Anonymisation;

use OCA\OpenRegister\Exception\AnalyzeRequestRejectedException;

/**
 * The resolved anonymisation backend, and one request to the OpenAnonymiser ExApp.
 *
 * Bound to AnonymisationBackendService in ContractBindings.
 */
interface AnonymiserRequestInterface {

	/**
	 * Resolve the full backend selection state.
	 *
	 * @return BackendState The fully-resolved state.
	 */
	public function getState(): BackendState;

	/**
	 * Issue a signed request to the active OpenAnonymiser ExApp via AppAPI.
	 *
	 * Uses `OCA\AppAPI\PublicFunctions::exAppRequest()`, resolved lazily so
	 * OpenRegister carries no hard dependency on AppAPI (ADR-017). ExApps reject
	 * unsigned requests, so a plain HTTP call to the internal host would fail —
	 * routing and auth headers are handled by AppAPI.
	 *
	 * @param string $route ExApp route (e.g. `/api/v1/analyze`).
	 * @param array<string, mixed> $params Request body parameters.
	 * @param string $method HTTP method (default POST).
	 *
	 * @return array<string, mixed>|null Decoded JSON response, or null on failure.
	 *
	 * @throws AnalyzeRequestRejectedException When the ExApp answers 4xx: it was reached and refused the request.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md
	 */
	public function requestOpenAnonymiser(string $route, array $params, string $method = 'POST'): ?array;
}//end interface
