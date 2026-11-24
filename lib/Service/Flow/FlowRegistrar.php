<?php

/**
 * The flow service registrations of OpenRegister.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Service\ProcessesServiceRegistrar;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * The body lives in FlowWiring, in this component;
 * this class keeps the entry points its callers use.
 *
 * Registers the flow run guards, the shared run context, the loop step dispatcher,
 * the lifecycle request state and the task services that are built by hand.
 *
 * Application::register() calls register() once; it also binds the Processes
 * service contracts (ProcessesServiceRegistrar), so the bootstrap names no other
 * Processes registrar.
 */
final class FlowRegistrar {

	/**
	 * The interfaces callers outside the component type against, bound to their class.
	 *
	 * @var array<class-string, class-string>
	 */
	public const BINDINGS = FlowWiring::BINDINGS;

	/**
	 * Register the flow run guards, the run context and the step dispatcher.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
	 */
	public static function register(IRegistrationContext $context): void {
		FlowWiring::register(context: $context);
		ProcessesServiceRegistrar::register(context: $context);
	}//end register()
}//end class
