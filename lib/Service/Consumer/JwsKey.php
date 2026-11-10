<?php

/**
 * The algorithm and key material one JWS signature is checked against.
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
 * A pinned signing algorithm with the HMAC secret or RSA public key that goes with it.
 *
 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
 */
final class JwsKey {

	/**
	 * Pair an algorithm with its key material.
	 *
	 * @param string $algorithm The pinned algorithm (HS*, RS* or PS*).
	 * @param string $material  The HMAC secret, or a PEM public key (or base64 of one).
	 *
	 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
	 */
	public function __construct(
		public readonly string $algorithm,
		public readonly string $material,
	) {
	}//end __construct()
}//end class
