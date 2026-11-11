<?php

/**
 * ApiContractServiceInterface: what callers outside the component use of ApiContractService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ApiVersion
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

namespace OCA\OpenRegister\Service\ApiVersion;

/**
 * The contract document the API surface endpoint serves.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface ApiContractServiceInterface {

	/**
	 * The OpenAPI description of one contract version.
	 *
	 * @param ApiVersion $version The version to describe.
	 * @param string|null $registerId Narrow to one register, or null for all.
	 *
	 * @return array<string, mixed> The description.
	 *
	 * @spec openspec/specs/openapi-generation/spec.md
	 */
	public function documentFor(ApiVersion $version, ?string $registerId = null): array;
}//end interface
