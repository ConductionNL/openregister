<?php

/**
 * OpenRegister token grant use
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
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

namespace OCA\OpenRegister\Service\Rbac;

use DateTimeInterface;

/**
 * The token grant in force for one resolution, the schema and register it is resolved for, and the moment.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class TokenGrantUse {
	/**
	 * Constructor.
	 *
	 * @param TokenGrant|null        $grant  The grant in force, or null for a session user.
	 * @param TokenGrantTarget       $target The schema being resolved and its register.
	 * @param DateTimeInterface|null $now    The moment, for the expiry; now when null.
	 */
	public function __construct(
		public readonly ?TokenGrant $grant,
		public readonly TokenGrantTarget $target,
		public readonly ?DateTimeInterface $now = null,
	) {
	}//end __construct()
}//end class
