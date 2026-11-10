<?php

/**
 * SearchRegistrar: container registrations of the Search component.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Search
 * @package  OCA\OpenRegister\Search
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

namespace OCA\OpenRegister\Search;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * The body and the binding map live in SearchWiring, in this component;
 * this class keeps the entry points its callers use.
 *
 * Registers the services of the Search component that the container cannot
 * autowire, and binds the interfaces other components type against.
 */
final class SearchRegistrar {

	/**
	 * The interfaces callers outside the component type against, bound to their class.
	 *
	 * @var array<class-string, class-string>
	 */
	public const BINDINGS = SearchWiring::BINDINGS;

	/**
	 * Register the Search component's services.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Container wiring; no behaviour of its own.
	 */
	public static function register(IRegistrationContext $context): void {
		SearchWiring::register(context: $context);
	}//end register()
}//end class
