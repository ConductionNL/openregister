<?php

/**
 * OpenRegister claim validation run
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Consumer;

use DateTime;

/**
 * The state of one {@see JwtValidator::validateClaims()} pass: the payload, the
 * moment it is checked at, and the issue and expiry times read from it. It never
 * leaves the validator that built it.
 *
 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
 */
final class ClaimValidationRun {

	/**
	 * When the token was issued (its iat claim); read once the claim is known to be present.
	 *
	 * @var DateTime|null
	 */
	public ?DateTime $iat = null;

	/**
	 * When the token expires: its exp claim, else one hour after iat; read once the issue time is accepted.
	 *
	 * @var DateTime|null
	 */
	public ?DateTime $exp = null;

	/**
	 * Start a pass over a payload at a moment.
	 *
	 * @param array    $payload The token payload.
	 * @param DateTime $now     The moment the claims are checked at.
	 */
	public function __construct(
		public readonly array $payload,
		public readonly DateTime $now,
	) {
	}//end __construct()
}//end class
