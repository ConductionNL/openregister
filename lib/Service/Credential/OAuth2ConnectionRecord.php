<?php

/**
 * OAuth2ConnectionRecord — one stored OAuth2 connection: its credential UUID and its property bag.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Credential;

/**
 * A connection as the endpoint found it: the uuid of the row the guard returned, and that row's data.
 *
 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-disconnecting-revokes-upstream-where-it-can-and-disables-locally
 */
final readonly class OAuth2ConnectionRecord {
	/**
	 * Constructor.
	 *
	 * @param string               $credentialId The credential UUID.
	 * @param array<string, mixed> $data         The credential's property bag.
	 *
	 * @return void
	 */
	public function __construct(
		public string $credentialId,
		public array $data,
	) {
	}//end __construct()
}//end class
