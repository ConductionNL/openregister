<?php

/**
 * OpenRegister ActionWindow
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
 * An optional action and an optional `created` range that narrow a trail query.
 */
final class ActionWindow {

	/**
	 * Constructor.
	 *
	 * @param string|null $action Optional action filter (create|update|delete).
	 * @param string|null $from   Optional ISO-8601 start date (inclusive).
	 * @param string|null $to     Optional ISO-8601 end date (inclusive).
	 */
	public function __construct(
		public readonly ?string $action = null,
		public readonly ?string $from = null,
		public readonly ?string $to = null,
	) {
	}//end __construct()
}//end class
