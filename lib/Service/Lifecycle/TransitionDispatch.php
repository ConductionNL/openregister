<?php

/**
 * What a transition tells and asks around it: the event dispatcher, the write boundary and the action providers, wired by DI autowiring.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;

use OCP\EventDispatcher\IEventDispatcher;

/**
 * What a transition tells and asks around it: the event dispatcher, the write boundary and the action providers, wired by DI autowiring.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class TransitionDispatch {
	/**
	 * Bundle the services.
	 *
	 * @param IEventDispatcher                $eventDispatcher  Dispatches ObjectTransitionedEvent.
	 * @param LifecycleWriteBoundary          $writeBoundary    Names the transition for the listeners and wraps
	 *                                                          the request-scoped automatic-transition pass.
	 * @param LifecycleActionProviderRegistry $providerRegistry Resolves a provider-mode annotation's
	 *                                                          `provider` tag to the app service that answers available actions.
	 */
	public function __construct(
		public readonly IEventDispatcher $eventDispatcher,
		public readonly LifecycleWriteBoundary $writeBoundary,
		public readonly LifecycleActionProviderRegistry $providerRegistry,
	) {
	}//end __construct()
}//end class
