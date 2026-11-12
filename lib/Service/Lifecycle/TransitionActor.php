<?php

/**
 * OpenRegister TransitionActor
 *
 * Who made a committed transition: the acting user, and whether a rule made it.
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
 * Who made a committed transition, as ObjectTransitionedEvent reports it.
 *
 * An automatic move acts as the identity whose write triggered it, so the
 * user id is the same for a person's click and a rule firing on their save;
 * `automatic` is what tells the two apart.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class TransitionActor {

	/**
	 * Constructor.
	 *
	 * @param string|null $userId    Caller uid, or null for a system-applied transition.
	 * @param bool        $automatic True when a transition's `autoWhen` made this move.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `automatic` is data the event carries, not a switch.
	 */
	public function __construct(
		public readonly ?string $userId = null,
		public readonly bool $automatic = false,
	) {
	}//end __construct()
}//end class
