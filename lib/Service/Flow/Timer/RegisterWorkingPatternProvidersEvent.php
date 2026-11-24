<?php

/**
 * Where an app contributes the working patterns of the people it administers.
 *
 * Modelled on {@see \OCA\OpenRegister\Service\Flow\Principal\RegisterPrincipalResolversEvent}:
 * contribution through an event means OpenRegister never names a consuming
 * app, and an instance without such an app simply has no provider, so the
 * scope calendar decides as it always did.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Timer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-persons-working-pattern-is-read-from-the-app-that-owns-it-req-wca-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use OCP\EventDispatcher\Event;

/**
 * Carries the registry an app registers its working-pattern provider on.
 */
class RegisterWorkingPatternProvidersEvent extends Event {

	/**
	 * Constructor.
	 *
	 * @param WorkingPatternProviders $registry The registry to register on.
	 */
	public function __construct(
		private readonly WorkingPatternProviders $registry,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Register a provider.
	 *
	 * @param WorkingPatternProviderInterface $provider The provider.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-persons-working-pattern-is-read-from-the-app-that-owns-it-req-wca-006
	 */
	public function register(WorkingPatternProviderInterface $provider): void {
		$this->registry->register(provider: $provider);
	}//end register()
}//end class
