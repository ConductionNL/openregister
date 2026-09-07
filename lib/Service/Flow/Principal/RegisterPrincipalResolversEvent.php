<?php

/**
 * Where an app contributes the kinds of principal it owns.
 *
 * Modelled on {@see \OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent},
 * deliberately: contribution through an event means OpenRegister never names a
 * consuming app, a type whose app is not installed is simply absent rather than
 * fatal, and the registration point is somewhere the fleet's developers already
 * know to look.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Principal
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Principal;

use OCP\EventDispatcher\Event;

/**
 * Carries the registry an app registers its principal resolvers on.
 */
class RegisterPrincipalResolversEvent extends Event {

	/**
	 * Constructor.
	 *
	 * @param PrincipalResolverRegistry $registry The registry to contribute to.
	 */
	public function __construct(
		private readonly PrincipalResolverRegistry $registry,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * Contribute a resolver.
	 *
	 * @param IPrincipalResolver $resolver The resolver.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
	 */
	public function registerResolver(IPrincipalResolver $resolver): void {
		$this->registry->register(resolver: $resolver);

	}//end registerResolver()
}//end class
