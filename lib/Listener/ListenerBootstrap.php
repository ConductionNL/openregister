<?php

/**
 * ListenerBootstrap: the entry Application calls to register the listeners of OpenRegister.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the event listeners of OpenRegister, in their fixed order.
 *
 * Application::register() calls register() once. The ordered list itself is
 * ListenerRegistrar, which stays inside the Listener component; this class
 * also binds the interfaces outside callers type against. Those aliases are
 * registered before the listeners and do not change their order.
 *
 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
 */
final class ListenerBootstrap {

	/**
	 * The interfaces callers outside the component type against, bound to their class.
	 *
	 * @var array<class-string, class-string>
	 */
	public const BINDINGS = [
		DecisionTableValidationListenerInterface::class => DecisionTableValidationListener::class,
		PresencePushInterface::class => NotifyPushListener::class,
	];

	/**
	 * Register all event listeners for the application, in order.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
	 */
	public static function register(IRegistrationContext $context): void {
		foreach (self::BINDINGS as $contract => $listener) {
			$context->registerServiceAlias($contract, $listener);
		}

		ListenerRegistrar::register(context: $context);
	}//end register()
}//end class
