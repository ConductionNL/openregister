<?php

/**
 * ComplianceServiceRegistrar: the entry that binds the Compliance area's service interfaces.
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
 * Binds the interfaces of the Compliance area's services: for now the
 * archival retention guard, as the Core port the referential-integrity
 * cascade asks.
 *
 * Application names this class only; the map lives in
 * ComplianceServiceBindings, inside the area. An interface of a lower area
 * that a Compliance class implements is bound here as well, so no lower
 * registrar names it.
 *
 * @spec exclude Container wiring; no behaviour of its own.
 */
final class ComplianceServiceRegistrar {

	/**
	 * The interfaces callers type against, bound to their class.
	 *
	 * @var array<class-string, class-string>
	 */
	public const BINDINGS = ComplianceServiceBindings::BINDINGS;

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
		DbRegistrar::bind(context: $context, bindings: ComplianceServiceBindings::BINDINGS);
	}//end register()
}//end class
