<?php

/**
 * OpenRegister Case Transition Effects
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case
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

namespace OCA\OpenRegister\Service\Case;

use OCP\EventDispatcher\IEventDispatcher;

/**
 * What a transition reaches outside its row: the realisations, the object's mirrored state and the event bus.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-one-lifecycle-table-governs-every-plan-item
 */
final readonly class CaseTransitionEffects {

	/**
	 * Bundle the collaborators.
	 *
	 * @param CaseRealisationService $realiser Creates and closes realisations.
	 * @param CaseBusinessStateWriter $writer Mirrors a milestone onto the object.
	 * @param IEventDispatcher|null $dispatcher Announces committed transitions.
	 */
	public function __construct(
		public CaseRealisationService $realiser,
		public CaseBusinessStateWriter $writer,
		public ?IEventDispatcher $dispatcher = null,
	) {
	}//end __construct()
}//end class
