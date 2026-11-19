<?php

/**
 * ComplianceServiceRegistrar: binds the Compliance area's service interfaces.
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
 * Binds the interfaces of ComplianceServiceBindings, the Compliance area's own service map.
 *
 * Application::register() calls register() once.
 *
 * @spec exclude Container wiring; no behaviour of its own.
 */
final class ComplianceServiceRegistrar {

	/**
	 * The interfaces this registrar binds, bound to their class.
	 *
	 * @var array<class-string, class-string>
	 */
	public const BINDINGS = ComplianceServiceBindings::BINDINGS;

	/**
	 * Bind the Compliance service interfaces.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Container wiring; no behaviour of its own.
	 */
	public static function register(IRegistrationContext $context): void {
		DbRegistrar::bind(context: $context, bindings: ComplianceServiceBindings::BINDINGS);
	}//end register()
}//end class
