<?php

/**
 * ConfigurationMapperRegistrar: the entry that binds the Configuration area's mapper interfaces.
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
 * Binds the interfaces of the Configuration area's mappers: configurations
 * and their bindings, deployments and drafts, applications, export profiles,
 * import previews, job runs, migration packs, record templates and sequences.
 *
 * Application names this class only; the map lives in
 * ConfigurationMapperBindings, inside the area. An interface of a lower area
 * that a Configuration class implements is bound here as well, so no lower
 * registrar names it.
 *
 * @spec exclude Container wiring; no behaviour of its own.
 */
final class ConfigurationMapperRegistrar {

	/**
	 * The interfaces callers type against, bound to their class.
	 *
	 * @var array<class-string, class-string>
	 */
	public const BINDINGS = ConfigurationMapperBindings::BINDINGS;

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
		DbRegistrar::bind(context: $context, bindings: ConfigurationMapperBindings::BINDINGS);
	}//end register()
}//end class
