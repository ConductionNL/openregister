<?php

/**
 * RateLimitResetInterface: what the security settings API asks of SecurityService.
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
 * Clearing the login rate limits of an IP address or a user. Only the methods the security settings controller calls.
 */
interface RateLimitResetInterface {

	/**
	 * Clear all rate limits for a specific IP address
	 *
	 * @param string $ipAddress The IP address to unblock
	 *
	 * @return void
	 *
	 * @spec openspec/specs/auth-system/spec.md#requirement-rate-limiting-must-protect-against-brute-force-attacks-and-api-abuse
	 */
	public function clearIpRateLimits(string $ipAddress): void;

	/**
	 * Clear all rate limits for a specific user
	 *
	 * @param string $username The username to unblock
	 *
	 * @return void
	 *
	 * @spec openspec/specs/auth-system/spec.md#requirement-rate-limiting-must-protect-against-brute-force-attacks-and-api-abuse
	 */
	public function clearUserRateLimits(string $username): void;
}//end interface
