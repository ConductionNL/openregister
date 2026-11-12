<?php

/**
 * ApiCapabilitiesServiceInterface: what callers outside the component use of ApiCapabilitiesService.
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
 * The capability documents the API surface endpoint serves.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface ApiCapabilitiesServiceInterface {

	/**
	 * The half any caller may read.
	 *
	 * @return array<string, mixed> The versions, the limits and the contract links.
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md
	 */
	public function publicCapabilities(): array;

	/**
	 * The public half plus the operational switches.
	 *
	 * @return array<string, mixed> The full capabilities answer.
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md
	 */
	public function sessionCapabilities(): array;
}//end interface
