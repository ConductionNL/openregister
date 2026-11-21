<?php

/**
 * OpenRegister ActionLookupOptions
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
 * Which entries of one object's trail a lookup by action returns.
 */
final class ActionLookupOptions {

	/**
	 * Constructor.
	 *
	 * @param array<int, string> $actions Actions to return; every action when empty.
	 * @param int                $limit   Maximum rows.
	 */
	public function __construct(
		public readonly array $actions = [],
		public readonly int $limit = 50,
	) {
	}//end __construct()
}//end class
