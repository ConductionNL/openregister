<?php

/**
 * BulkActionRegistrar: container registrations of the BulkAction component.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BulkAction
 * @package  OCA\OpenRegister\BulkAction
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

namespace OCA\OpenRegister\BulkAction;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * The body and the binding map live in BulkActionWiring, in this component;
 * this class keeps the entry points its callers use.
 *
 * Binds the interfaces other components type against to the built-in bulk actions.
 */
final class BulkActionRegistrar {

	/**
	 * The interfaces callers outside the component type against, bound to their class.
	 *
	 * @var array<class-string, class-string>
	 */
	public const BINDINGS = BulkActionWiring::BINDINGS;

	/**
	 * Register the BulkAction component's bindings.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Container wiring; no behaviour of its own.
	 */
	public static function register(IRegistrationContext $context): void {
		BulkActionWiring::register(context: $context);
	}//end register()
}//end class
