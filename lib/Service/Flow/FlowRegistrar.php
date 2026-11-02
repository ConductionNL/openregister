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

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the flow run guards, the shared run context and the loop step dispatcher.
 *
 * Application::register() calls register() once.
 */
final class FlowRegistrar {

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
		// 🔴 THE RUN AUTHORIZATION IS REGISTERED EXPLICITLY, because its
		// failure mode is total. `FlowService` takes it as a NULLABLE argument
		// and an absent one is UNDECIDABLE, which refuses every run — correct
		// for a security control, and an outage if the container quietly
		// declined to build it. A named registration turns that into a loud
		// container error instead of a fleet of refusals nobody can explain
		// (change `flow-runs-honour-their-declaration`).
		$context->registerService(
			FlowRunAuthorization::class,
			static function ($c) {
				return new FlowRunAuthorization(
					access: $c->get(\OCA\OpenRegister\Service\Flow\FlowAccess::class),
				);
			}
		);

		// 🔴 THE RUN AND EDIT GUARD IS REGISTERED EXPLICITLY, for the reason
		// directly above. Both of its collaborators are nullable and both
		// absences fail CLOSED, so a container that quietly declined to build
		// one of them would refuse every run and every test run with no error
		// anywhere saying why. A named registration turns that into a loud
		// container error instead.
		$context->registerService(
			FlowRunnableGuard::class,
			static function ($c) {
				return new FlowRunnableGuard(
					flows: $c->get(\OCA\OpenRegister\Service\Flow\FlowService::class),
					access: $c->get(\OCA\OpenRegister\Service\Flow\FlowAccess::class),
				);
			}
		);

		// The ambient flow-attribution stack MUST be shared. Nextcloud's
		// container builds an auto-wired class fresh at every injection point,
		// so without this registration the engine would push frames onto one
		// instance and the audit mapper would read an empty stack on another —
		// attributing nothing at all, silently and with no error anywhere.
		$context->registerService(
			FlowRunContext::class,
			function () {
				return new FlowRunContext();
			}
		);

		// The loop node's step dispatcher. `IterateNode` resolves the INTERFACE
		// — correctly, it should not know which dispatcher runs its body — but
		// nothing ever bound it, and an interface cannot be instantiated. So
		// every repeat step failed the moment it executed:
		//
		// > Could not resolve …\FlowStepDispatcher! Class can not be instantiated
		//
		// The node validated, appeared in the palette and drew on the canvas
		// throughout, which is why this survived: nothing short of RUNNING a
		// flow that contains one can see it, and no flow on the instance did.
		// Registered as an explicit FACTORY, not an alias. An alias makes the
		// container autowire RegistryStepDispatcher, and its second parameter is
		// a FlowRunGuard whose own constructor takes a `string $runUuid` — which
		// the container cannot invent. Autowiring it therefore died with
		// "Could not resolve runUuid!" on the first repeat step that ran, and a
		// nullable default does not help: the container resolves a typed
		// parameter before it considers the default.
		//
		// The guard is per-RUN state and cannot come from the container at all.
		// A container-built dispatcher gets none, and picks one up from the run
		// context at dispatch time instead.
		$context->registerService(
			\OCA\OpenRegister\Service\Flow\FlowStepDispatcher::class,
			static function ($c) {
				return new RegistryStepDispatcher(
					registry: $c->get(\OCA\OpenRegister\Service\Flow\FlowNodeRegistry::class),
					// The acting-identity scope IS container state, unlike the
					// guard: a loop's body steps must scope a contributed node
					// to the run's runAs exactly as top-level steps do.
					scope: $c->get(\OCA\OpenRegister\Service\Flow\FlowRunAsScope::class)
				);
			}
		);
	}//end register()
}//end class
