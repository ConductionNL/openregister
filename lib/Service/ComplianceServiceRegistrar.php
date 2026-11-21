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
use OCA\OpenRegister\Service\Gdpr\GdprRegistrar;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Binds the interfaces of the Compliance area's services that a lower area
 * owns: the inbound-authentication ceiling Core's SecurityService reads and
 * the archival retention guard the referential-integrity cascade asks.
 * It is also the entry for the GDPR registrar, so Application reaches the
 * Compliance GDPR wiring here rather than through a Core class.
 *
 * Application names this class only; the map lives in
 * ComplianceServiceBindings, inside the area.
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

	/**
	 * Register the DSAR signer and the shared DSAR registries with their fail-closed defaults.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Container wiring; no behaviour of its own.
	 */
	public static function registerGdpr(IRegistrationContext $context): void {
		GdprRegistrar::register(context: $context);
	}//end registerGdpr()
}//end class
