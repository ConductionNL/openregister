<?php

/**
 * OpenRegister Credential — OAuth2 Revocation
 *
 * One best-effort upstream revoke of a connection.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Credential
 * @package  OCA\OpenRegister\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Credential;

/**
 * Revoke a connection at its provider: the provider, the credential's data and
 * where its token set is held.
 *
 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-disconnecting-revokes-upstream-where-it-can-and-disables-locally
 */
final class OAuth2Revocation {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $provider   The catalogue provider entry.
	 * @param array<string, mixed> $credential The credential object's serialised data.
	 * @param TokenSetAddress      $address    The credential UUID and scope.
	 */
	public function __construct(
		public readonly array $provider,
		public readonly array $credential,
		public readonly TokenSetAddress $address,
	) {
	}//end __construct()
}//end class
