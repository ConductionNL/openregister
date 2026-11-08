<?php

/**
 * The entry Application::register() calls to bind the contracts to their services.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers ContractBindings with the app container.
 *
 * Application::register() calls register() once, where it used to call
 * ContractBindings::register() itself, so the binding table stays inside
 * lib/Service and the registration order does not change.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class ContractBindingsRegistrar {

	/**
	 * Register every contract binding with the app container.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public static function register(IRegistrationContext $context): void {
		ContractBindings::register(context: $context);
	}//end register()
}//end class
