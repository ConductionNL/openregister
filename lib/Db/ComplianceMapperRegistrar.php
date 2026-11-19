<?php

/**
 * ComplianceMapperRegistrar: the entry that binds the Compliance area's mapper interfaces.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
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

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Binds the interfaces of the Compliance area's mappers: audit trails,
 * search trails, processing records, GDPR entities, subject exports, erasure
 * previews, content reports and quality audits.
 *
 * Application names this class only; the map lives in
 * ComplianceMapperBindings, inside the area. An interface of a lower area
 * that a Compliance class implements is bound here as well, so no lower
 * registrar names it.
 *
 * @spec exclude Container wiring; no behaviour of its own.
 */
final class ComplianceMapperRegistrar {

	/**
	 * The interfaces callers type against, bound to their class.
	 *
	 * @var array<class-string, class-string>
	 */
	public const BINDINGS = ComplianceMapperBindings::BINDINGS;

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
		DbRegistrar::bind(context: $context, bindings: ComplianceMapperBindings::BINDINGS);
	}//end register()
}//end class
