<?php

/**
 * OpenRegister PartyQueryRefusal
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\AuditTrail
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\AuditTrail;

/**
 * A person query refused because it would match more parties than the administered cap.
 *
 * The operation object of AuditTrailMapper::recordPartyQueryRefusal().
 */
final class PartyQueryRefusal {

	/**
	 * Constructor.
	 *
	 * @param string   $query  The query that was refused.
	 * @param int      $cap    The administered maximum result count.
	 * @param int      $would  How many parties the query would have matched.
	 * @param int|null $schema Schema id searched, when the query named one.
	 */
	public function __construct(
		public readonly string $query,
		public readonly int $cap,
		public readonly int $would,
		public readonly ?int $schema = null,
	) {
	}//end __construct()
}//end class
