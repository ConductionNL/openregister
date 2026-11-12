<?php

/**
 * The terms one token grant is issued on: when it lapses and how often it may call.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use DateTimeInterface;

/**
 * The terms of a token grant. A null expiry reads as expired, see TokenGrant::isExpired().
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
 */
final class TokenGrantTerms {

	/**
	 * Constructor.
	 *
	 * @param DateTimeInterface|null $expiresAt When it lapses; required at issue.
	 * @param int|null               $rateLimit Calls per minute, or null for none.
	 */
	public function __construct(
		public readonly ?DateTimeInterface $expiresAt = null,
		public readonly ?int $rateLimit = null,
	) {
	}//end __construct()
}//end class
