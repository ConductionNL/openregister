<?php

/**
 * McpListenerRegistrar: the event listeners of the Mcp component.
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

use OCA\OpenRegister\Event\ToolRegistrationEvent;
use OCA\OpenRegister\Listener\ToolRegistrationListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the listeners of the Mcp component on events no other component listens to.
 *
 * An event with listeners from more than one component stays in
 * ListenerRegistrar, in one ordered list, because listeners of one event run
 * in registration order.
 */
final class McpListenerRegistrar {

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
		// ToolRegistrationListener for agent function tools.
		$context->registerEventListener(ToolRegistrationEvent::class, ToolRegistrationListener::class);
	}//end register()
}//end class
