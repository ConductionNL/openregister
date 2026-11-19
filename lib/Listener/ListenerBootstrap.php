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
 * ListenerRegistrar, which stays inside the Listener component and binds the
 * interfaces of its own listeners. This class is the application's entry into
 * that list and binds only the Platform listener classes outside callers type
 * against (the presence push and the notify_push batch entry the import path
 * drives). The aliases are registered before the listeners and do not change
 * their order.
 *
 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
 */
final class ListenerBootstrap {

	/**
	 * The Platform listener interfaces callers outside the component type against, bound to their class.
	 *
	 * NotifyPushBatchInterface is the Core port the import path drives; NotifyPushBatch is the
	 * notify_push listener's own batch entry, so Platform binds it here.
	 *
	 * DecisionTableValidationListenerInterface is bound by ListenerRegistrar::BINDINGS, next to the
	 * other Processes listener interfaces.
	 *
	 * @var array<class-string, class-string>
	 */
	public const BINDINGS = [
		PresencePushInterface::class    => NotifyPushListener::class,
		NotifyPushBatchInterface::class => NotifyPushBatch::class,
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
