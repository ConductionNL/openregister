<?php

/**
 * OpenRegister DecodedJwt
 *
 * A compact JWS together with the header decoded from it, as signature verification takes it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Consumer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Consumer;

/**
 * The compact token and its decoded header.
 *
 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
 */
final class DecodedJwt {

	/**
	 * Constructor.
	 *
	 * @param string               $token  The compact JWS.
	 * @param array<string, mixed> $header The decoded header.
	 */
	public function __construct(
		public readonly string $token,
		public readonly array $header,
	) {
	}//end __construct()
}//end class
