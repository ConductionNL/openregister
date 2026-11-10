<?php

/**
 * ApiTokenRequest: a personal API token the user asks to mint.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\User
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/profile-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\User;

/**
 * The name and the optional lifetime of a personal API token to mint.
 *
 * ADR-011 Rule 2 operation object for UserService::issueApiToken().
 *
 * @spec openspec/specs/profile-actions/spec.md
 */
final class ApiTokenRequest {

	/**
	 * Describe it.
	 *
	 * @param string      $name      The token name.
	 * @param string|null $expiresIn Optional lifetime, a number followed by d, h or m (e.g. "90d").
	 */
	public function __construct(
		public readonly string $name,
		public readonly ?string $expiresIn = null,
	) {
	}//end __construct()
}//end class
