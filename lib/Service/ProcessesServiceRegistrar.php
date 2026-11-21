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

use OCA\OpenRegister\Db\DbRegistrar;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Binds the interfaces of the Processes area's services.
 *
 * Application names this class only, right after ContractBindingsRegistrar;
 * the map lives in ProcessesServiceBindings, inside the area.
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
		DbRegistrar::bind(context: $context, bindings: ProcessesServiceBindings::BINDINGS);
	}//end register()
}//end class
