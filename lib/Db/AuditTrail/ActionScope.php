<?php

/**
 * OpenRegister ActionScope
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
 * Which actions of one object's trail an operation touches.
 */
final class ActionScope {

	/**
	 * Constructor.
	 *
	 * @param array<int, string>|null $onlyActions    Restrict to these actions, or null for every action.
	 * @param array<int, string>      $excludeActions Actions never touched, such as the destruction record.
	 */
	public function __construct(
		public readonly ?array $onlyActions = null,
		public readonly array $excludeActions = [],
	) {
	}//end __construct()
}//end class
