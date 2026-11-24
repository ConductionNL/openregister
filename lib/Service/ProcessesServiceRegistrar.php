<?php

/**
 * ProcessesServiceRegistrar: the entry that binds the Processes area's service interfaces.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Binds the interfaces of the Processes area's services.
 *
 * FlowRegistrar (the Processes entry point Application calls) runs it; the map
 * lives in ProcessesServiceBindings, inside the area. No other registrar binds
 * these interfaces, so their order against ContractBindings does not matter.
 *
 * @spec exclude Container wiring; no behaviour of its own.
 */
final class ProcessesServiceRegistrar {

	/**
	 * Register every binding with the app container.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Container wiring; no behaviour of its own.
	 */
	public static function register(IRegistrationContext $context): void {
		foreach (ProcessesServiceBindings::BINDINGS as $contract => $class) {
			$context->registerServiceAlias($contract, $class);
		}
	}//end register()
}//end class
