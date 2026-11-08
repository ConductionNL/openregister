<?php

/**
 * AppHostRegistrar: container bindings of the AppHost component.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category AppHost
 * @package  OCA\OpenRegister\AppHost
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

namespace OCA\OpenRegister\AppHost;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * The body and the binding map live in AppHostWiring, in this component;
 * this class keeps the entry points its callers use.
 *
 * Binds the interfaces other components type against to the AppHost classes.
 *
 * FeatureToggleRegistrar and ObservabilityRegistrar keep their own wiring;
 * this one only holds the interface bindings.
 */
final class AppHostRegistrar {

	/**
	 * The interfaces callers outside the component type against, bound to their class.
	 *
	 * @var array<class-string, class-string>
	 */
	public const BINDINGS = AppHostWiring::BINDINGS;

	/**
	 * Register the AppHost component's bindings.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Container wiring; no behaviour of its own.
	 */
	public static function register(IRegistrationContext $context): void {
		AppHostWiring::register(context: $context);
	}//end register()
}//end class
