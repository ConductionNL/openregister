<?php

/**
 * OpenRegister ActorTrailQuery
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
 * One page of the entries one actor produced.
 *
 * The operation object of AuditTrailMapper::findActorTrail().
 */
final class ActorTrailQuery {

	/**
	 * Constructor.
	 *
	 * @param string       $userId The actor UID to filter on.
	 * @param int          $limit  Maximum number of rows to return.
	 * @param int          $offset Row offset for pagination.
	 * @param ActionWindow $window The optional action and date range.
	 */
	public function __construct(
		public readonly string $userId,
		public readonly int $limit = 25,
		public readonly int $offset = 0,
		public readonly ActionWindow $window = new ActionWindow(),
	) {
	}//end __construct()
}//end class
