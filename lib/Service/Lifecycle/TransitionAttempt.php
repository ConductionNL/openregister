<?php

/**
 * OpenRegister TransitionAttempt
 *
 * A write that attempts one lifecycle transition: the object after and before
 * the write, and the move it asks for.
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
 * The write (object after and before, its schema's slug, the lifecycle field)
 * and the transition it attempts (name, from, to): what a lifecycle rule is
 * evaluated against, and what its refusal names.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final readonly class TransitionAttempt {
	/**
	 * Describe the attempt.
	 *
	 * @param LifecycleWrite $write The object after and before the write, its schema's slug and the lifecycle field.
	 * @param TransitionMove $move  The transition's name and the states it moves between.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public LifecycleWrite $write,
		public TransitionMove $move,
	) {
	}//end __construct()
}//end class
