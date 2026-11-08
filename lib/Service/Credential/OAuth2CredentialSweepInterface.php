<?php

/**
 * OAuth2CredentialSweepInterface: what callers outside the Service component ask of OAuth2RefreshService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Credential
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

namespace OCA\OpenRegister\Service\Credential;

use Throwable;

/**
 * Refreshing one OAuth2 credential for the daily sweep, as the token refresh job does. Only the method that caller calls.
 */
interface OAuth2CredentialSweepInterface {

	/**
	 * Refresh one credential unconditionally, for the daily sweep.
	 *
	 * @param array<string, mixed> $credential The credential object's serialised data.
	 * @param array<string, mixed> $provider The catalogue provider entry.
	 * @param string $credentialId The credential UUID.
	 * @param string $scope The credential scope.
	 *
	 * @return boolean True when a rotation happened, false when the credential was outside the sweep window.
	 *
	 * @throws Throwable Whatever the rotation raised; the sweep decides what to do with it.
	 *
	 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-a-daily-job-refreshes-active-token-sets-before-they-expire
	 */
	public function sweepCredential(array $credential, array $provider, string $credentialId, string $scope): bool;
}//end interface
