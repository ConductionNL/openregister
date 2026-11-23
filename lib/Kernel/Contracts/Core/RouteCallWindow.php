<?php
/**
 * RouteCallWindow: the route prefixes and the moment from which their callers are asked for.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

use DateTime;

/**
 * Which routes, and since when, a question about their callers covers.
 *
 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md#requirement-the-breaking-change-gate-shows-who-will-be-affected
 */
final class RouteCallWindow {

	/**
	 * Describe the window.
	 *
	 * @param array<int, string> $prefixes The route prefixes.
	 * @param DateTime           $from     The earliest last call.
	 */
	public function __construct(
		public readonly array $prefixes,
		public readonly DateTime $from,
	) {
	}//end __construct()
}//end class
