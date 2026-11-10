<?php

/**
 * One narrowing of an authorization block by a token's grant.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use DateTimeInterface;

/**
 * The grant in force, the schema it is resolved for and the moment, for one narrowing.
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
 */
final class TokenNarrowing {

	/**
	 * Constructor.
	 *
	 * @param TokenGrant        $grant  The grant in force.
	 * @param TokenGrantTarget  $target The schema being resolved and its register.
	 * @param DateTimeInterface $now    The moment, for the expiry.
	 */
	public function __construct(
		public readonly TokenGrant $grant,
		public readonly TokenGrantTarget $target,
		public readonly DateTimeInterface $now,
	) {
	}//end __construct()
}//end class
