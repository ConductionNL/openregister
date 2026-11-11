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
 * The body lives in FlowWiring, in this component;
 * this class keeps the entry points its callers use.
 *
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
		FlowWiring::register(context: $context);
	}//end register()
}//end class
