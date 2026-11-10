<?php

/**
 * OpenRegister note step
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Notifications\Controller\Support
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

namespace OCA\OpenRegister\Notifications\Controller\Support;

use Closure;

/**
 * What a notes request does with the resolved object, and how it answers any other failure.
 *
 * @spec openspec/specs/object-interactions/spec.md
 */
final class NoteStep {
	/**
	 * Constructor.
	 *
	 * @param Closure $action  What to do with the object: (ObjectEntity): JSONResponse.
	 * @param Closure $failure How to answer any other failure: (Exception): JSONResponse.
	 */
	public function __construct(
		public readonly Closure $action,
		public readonly Closure $failure,
	) {
	}//end __construct()
}//end class
