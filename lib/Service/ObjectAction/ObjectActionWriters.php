<?php

/**
 * The two writes a runner performs itself: a lifecycle transition and a self-assign.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectAction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/declared-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectAction;

use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;

/**
 * The writers the action runner holds.
 *
 * @spec openspec/specs/declared-actions/spec.md
 */
final class ObjectActionWriters {

	/**
	 * Constructor.
	 *
	 * @param TransitionEngine       $transitions Runs a `transition` body.
	 * @param ObjectActionSelfAssign $selfAssign  Runs a `selfAssign` body.
	 */
	public function __construct(
		public readonly TransitionEngine $transitions,
		public readonly ObjectActionSelfAssign $selfAssign,
	) {
	}//end __construct()
}//end class
