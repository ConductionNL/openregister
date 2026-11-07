<?php

/**
 * RegisterResolverServiceInterface: what callers outside the Service component use of RegisterResolverService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * The app-config enumeration the resolver occ command calls on RegisterResolverService.
 */
interface RegisterResolverServiceInterface {

	/**
	 * Enumerate every `<context>_(register|schema)` key set for an app.
	 *
	 * Used by admin UIs and the `openregister:resolver:list` console
	 * command for diagnostics. The bare keys `register` and `schema`
	 * are surfaced as `default_register` / `default_schema` so admin
	 * UIs can flag them as legacy convention.
	 *
	 * @param string $appId Consumer app id.
	 *
	 * @return array<string, string> Map of config-key → raw configured value.
	 *
	 * @spec openspec/specs/register-resolver-service/spec.md
	 *   (Requirement: enumerateAppConfigs)
	 */
	public function enumerateAppConfigs(string $appId): array;
}//end interface
