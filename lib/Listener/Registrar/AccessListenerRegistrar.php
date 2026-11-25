<?php

/**
 * AccessListenerRegistrar: the event listeners of the Access component.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener\Registrar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener\Registrar;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the listeners of the Access component on events no other component listens to.
 *
 * An event with listeners from more than one component stays in
 * ListenerRegistrar, in one ordered list, because listeners of one event run
 * in registration order.
 */
final class AccessListenerRegistrar {

	/**
	 * Register the listeners.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Container wiring; the listeners carry their own specs.
	 */
	public static function register(IRegistrationContext $context): void {
		// Access derived from what an identity provider asserted, once per
		// sign-in: a Core listener, registered through Core's slot at this position.
		\OCA\OpenRegister\Listener\CoreListenerRegistrar::identityClaims(context: $context);

		// A service account signs in with its tokens only (REQ-SAT-004): a
		// Core listener, registered through Core's slot at this position.
		\OCA\OpenRegister\Listener\CoreListenerRegistrar::serviceAccountLogin(context: $context);

		// A removed user's object shares end with them.
		$context->registerEventListener(
			\OCP\User\Events\BeforeUserDeletedEvent::class,
			\OCA\OpenRegister\Listener\SharerViewsEndWithUserListener::class
		);

		// A share made from the platform's own sharing surface on an object's
		// folder passes the schema's opt-in and the permission mapping: a Core
		// listener, registered through Core's slot at this position.
		\OCA\OpenRegister\Listener\CoreListenerRegistrar::platformShareRouting(context: $context);

		// A grant to a slot follows whoever holds the role: a Core listener,
		// registered through Core's slot at this position.
		\OCA\OpenRegister\Listener\CoreListenerRegistrar::slotOccupants(context: $context);
	}//end register()
}//end class
