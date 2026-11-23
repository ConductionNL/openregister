<?php

/**
 * OpenRegister TransitionCommand
 *
 * One named transition a caller asked for, by object id.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;

/**
 * One named transition a caller asked for, before its object is resolved.
 *
 * Built by the public entry points of {@see TransitionEngine} (ADR-011 rule 2).
 * Whether the move runs on the system path is NOT part of the command: only
 * {@see TransitionEngine::transitionAsSystem()} selects that path, so nothing
 * a caller posts can ask for it.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class TransitionCommand {

	/**
	 * Name the command.
	 *
	 * @param string               $objectId   Object id/uuid/slug.
	 * @param TransitionSubmission $submission The action and its input values.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly string $objectId,
		public readonly TransitionSubmission $submission,
	) {
	}//end __construct()
}//end class
